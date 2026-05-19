/**
 * AgentReady — Context Score admin UI (#10 / AgDR-0031).
 *
 * Renders the cached breakdown shipped in AgDR-0030 (#9) as three
 * vertical regions:
 *
 *   1. Overall card — integer score, progress bar, recompute button,
 *      and relative "computed N minutes ago" timestamp.
 *   2. What's missing — sub-scores below 100, sorted by leverage
 *      (Site_Health.php uses the same axis) — each row a one-line
 *      reason plus a "Configure" deep-link to the Context Profile.
 *   3. Full breakdown — one collapsible PanelBody per sub-score with
 *      value/weight, all reasons, and the raw signals dict.
 *
 * All mutations route through `agentready/v1/context-score/recompute`
 * (POST) so the server is the source of truth — the UI is presentation
 * only. Initial paint reuses `bootstrap.initialBreakdown` to avoid a
 * round-trip when the cache is populated.
 */

import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const MOUNT_SELECTOR = '#agentready-context-score-root';
const BOOTSTRAP_KEY = 'agentreadyContextScore';

// Hide the success notice after this many ms so it doesn't dominate
// the screenshot the operator is taking to put in their deck.
const SUCCESS_NOTICE_TIMEOUT_MS = 4000;

const SUB_SCORE_LABELS = {
	discoverability: __( 'Discoverability', 'agentready' ),
	content_readability: __( 'Content readability', 'agentready' ),
	schema_coverage: __( 'Schema coverage', 'agentready' ),
	exposure_safety: __( 'Exposure safety', 'agentready' ),
	integration_health: __( 'Integration health', 'agentready' ),
	md_conversion_quality: __( 'Markdown conversion quality', 'agentready' ),
};

function readBootstrap() {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const data = window[ BOOTSTRAP_KEY ];
	if ( ! data || typeof data !== 'object' ) {
		return null;
	}
	return data;
}

function statusBucket( overall ) {
	if ( overall >= 80 ) {
		return { tone: 'good', color: '#2e7d32' };
	}
	if ( overall >= 50 ) {
		return { tone: 'recommended', color: '#b26a00' };
	}
	return { tone: 'critical', color: '#a94442' };
}

function relativeTime( iso ) {
	if ( ! iso ) {
		return __( 'unknown', 'agentready' );
	}
	const then = Date.parse( iso );
	if ( Number.isNaN( then ) ) {
		return __( 'unknown', 'agentready' );
	}
	const diffSec = Math.max( 0, Math.round( ( Date.now() - then ) / 1000 ) );
	if ( diffSec < 60 ) {
		return __( 'just now', 'agentready' );
	}
	const diffMin = Math.round( diffSec / 60 );
	if ( diffMin < 60 ) {
		return sprintf(
			/* translators: %d: minutes ago */
			__( '%d minute(s) ago', 'agentready' ),
			diffMin
		);
	}
	const diffHr = Math.round( diffMin / 60 );
	if ( diffHr < 24 ) {
		return sprintf(
			/* translators: %d: hours ago */
			__( '%d hour(s) ago', 'agentready' ),
			diffHr
		);
	}
	const diffDay = Math.round( diffHr / 24 );
	return sprintf(
		/* translators: %d: days ago */
		__( '%d day(s) ago', 'agentready' ),
		diffDay
	);
}

function ProgressBar( { value, label } ) {
	const clamped = Math.max( 0, Math.min( 100, Math.round( value ) ) );
	const bucket = statusBucket( clamped );
	return (
		<div
			role="progressbar"
			aria-valuenow={ clamped }
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
			aria-label={ label }
			style={ {
				position: 'relative',
				height: '8px',
				background: '#eee',
				borderRadius: '4px',
				overflow: 'hidden',
				marginTop: '6px',
			} }
		>
			<div
				style={ {
					width: `${ clamped }%`,
					height: '100%',
					background: bucket.color,
				} }
			/>
		</div>
	);
}

function leverage( sub ) {
	if ( ! sub || typeof sub !== 'object' ) {
		return 0;
	}
	const value = Number( sub.value || 0 );
	const weight = Number( sub.weight || 0 );
	return Math.max( 0, ( 100 - value ) * weight );
}

function OverallCard( {
	breakdown,
	loading,
	pending,
	flash,
	onRecompute,
	onDismissFlash,
} ) {
	if ( loading && ! breakdown ) {
		return (
			<Panel>
				<PanelBody
					initialOpen
					title={ __( 'Overall score', 'agentready' ) }
				>
					<Spinner />
					<p>{ __( 'Computing Context Score…', 'agentready' ) }</p>
				</PanelBody>
			</Panel>
		);
	}

	if ( ! breakdown ) {
		return (
			<Panel>
				<PanelBody
					initialOpen
					title={ __( 'Overall score', 'agentready' ) }
				>
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No Context Score breakdown is available yet. Click "Recompute now" to generate the first audit.',
							'agentready'
						) }
					</Notice>
					<Button
						variant="primary"
						onClick={ onRecompute }
						disabled={ pending }
					>
						{ pending
							? __( 'Recomputing…', 'agentready' )
							: __( 'Recompute now', 'agentready' ) }
					</Button>
				</PanelBody>
			</Panel>
		);
	}

	const overall = Number( breakdown.overall || 0 );
	const bucket = statusBucket( overall );

	return (
		<Panel>
			<PanelBody
				initialOpen
				title={ __( 'Overall score', 'agentready' ) }
			>
				{ flash && (
					<Notice status={ flash.type } onRemove={ onDismissFlash }>
						{ flash.message }
					</Notice>
				) }
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: '24px',
						marginBottom: '12px',
						flexWrap: 'wrap',
					} }
				>
					<div>
						<div
							style={ {
								fontSize: '48px',
								fontWeight: 700,
								lineHeight: 1,
								color: bucket.color,
							} }
						>
							{ overall }
							<span
								style={ {
									fontSize: '20px',
									color: '#888',
									fontWeight: 400,
								} }
							>
								/100
							</span>
						</div>
						<div
							style={ {
								marginTop: '4px',
								fontSize: '12px',
								color: '#555',
								textTransform: 'uppercase',
								letterSpacing: '0.06em',
							} }
						>
							{ bucket.tone === 'good' &&
								__( 'Good', 'agentready' ) }
							{ bucket.tone === 'recommended' &&
								__( 'Needs attention', 'agentready' ) }
							{ bucket.tone === 'critical' &&
								__( 'Critical', 'agentready' ) }
						</div>
					</div>
					<div style={ { flex: 1, minWidth: '200px' } }>
						<ProgressBar
							value={ overall }
							label={ __(
								'Overall Context Score (0 to 100)',
								'agentready'
							) }
						/>
						<div
							style={ {
								marginTop: '8px',
								color: '#555',
								fontSize: '13px',
							} }
						>
							{ sprintf(
								/* translators: %s: relative time, e.g. "5 minutes ago" */
								__( 'Last computed %s.', 'agentready' ),
								relativeTime( breakdown.computed_at )
							) }
						</div>
					</div>
					<div>
						<Button
							variant="primary"
							onClick={ onRecompute }
							disabled={ pending }
						>
							{ pending
								? __( 'Recomputing…', 'agentready' )
								: __( 'Recompute now', 'agentready' ) }
						</Button>
					</div>
				</div>
			</PanelBody>
		</Panel>
	);
}

// Per-sub-score fix-link map. Each sub-score either points at a real
// destination (Context Profile, AI Client section, etc.) with its own
// button label, or returns null when there is no concrete one-click
// path. The map is keyed by the breakdown's machine name (AgDR-0030).
//
// `schema_coverage` deliberately returns null: agentready does not yet
// emit JSON-LD natively (planned for v0.1.x); telling the operator to
// install a third-party plugin is the wrong shape because agentready
// is the agent-readiness layer. The reason text already explains the
// gap; no fix button is better than a misleading one.
//
// `md_conversion_quality` also returns null: it is driven by content
// quality and the cached MD walker output. There is no settings
// surface that resolves it in one click. The Markdown Views Gutenberg
// sidebar (#5) is the per-post fix path but is not a single URL.
function fixActionFor( name, urls ) {
	switch ( name ) {
		case 'discoverability':
		case 'content_readability':
		case 'exposure_safety':
		case 'integration_health':
			return {
				href: urls.profilePageUrl,
				label: __( 'Configure in Context Profile', 'agentready' ),
			};
		case 'schema_coverage':
		case 'md_conversion_quality':
		default:
			return null;
	}
}

function WhatsMissing( { breakdown, profilePageUrl } ) {
	const rows = useMemo( () => {
		if ( ! breakdown || ! breakdown.sub_scores ) {
			return [];
		}
		return Object.entries( breakdown.sub_scores )
			.filter( ( [ , sub ] ) => Number( sub.value || 0 ) < 100 )
			.map( ( [ name, sub ] ) => ( {
				name,
				value: Number( sub.value || 0 ),
				weight: Number( sub.weight || 0 ),
				reason:
					Array.isArray( sub.reasons ) && sub.reasons.length > 0
						? String( sub.reasons[ 0 ] )
						: '',
				_leverage: leverage( sub ),
			} ) )
			.sort( ( a, b ) => b._leverage - a._leverage );
	}, [ breakdown ] );

	if ( ! breakdown ) {
		return null;
	}

	return (
		<Panel>
			<PanelBody
				initialOpen
				title={ __( 'What is missing', 'agentready' ) }
			>
				{ rows.length === 0 && (
					<p>
						{ __(
							'Every sub-score is at 100. Nothing actionable to surface.',
							'agentready'
						) }
					</p>
				) }
				{ rows.length > 0 && (
					<ul
						style={ {
							margin: 0,
							padding: 0,
							listStyle: 'none',
						} }
					>
						{ rows.map( ( row ) => {
							const action = fixActionFor( row.name, {
								profilePageUrl,
							} );
							return (
								<li
									key={ row.name }
									style={ {
										padding: '12px 0',
										borderBottom: '1px solid #eee',
										display: 'flex',
										alignItems: 'flex-start',
										gap: '12px',
										flexWrap: 'wrap',
									} }
								>
									<div
										style={ {
											flex: 1,
											minWidth: '240px',
										} }
									>
										<div
											style={ {
												fontWeight: 600,
												marginBottom: '2px',
											} }
										>
											{ SUB_SCORE_LABELS[ row.name ] ||
												row.name }
											<span
												style={ {
													marginLeft: '8px',
													color: '#888',
													fontWeight: 400,
													fontSize: '12px',
												} }
											>
												{ sprintf(
													/* translators: 1: sub-score value 0-100. 2: weight contribution. */
													__(
														'%1$d/100 · weight %2$d',
														'agentready'
													),
													row.value,
													row.weight
												) }
											</span>
										</div>
										<div
											style={ {
												color: '#444',
												fontSize: '13px',
											} }
										>
											{ row.reason }
										</div>
									</div>
									{ action && (
										<div>
											<Button
												variant="secondary"
												href={ action.href }
											>
												{ action.label }
											</Button>
										</div>
									) }
								</li>
							);
						} ) }
					</ul>
				) }
			</PanelBody>
		</Panel>
	);
}

function SubScoreBreakdown( { breakdown } ) {
	if ( ! breakdown || ! breakdown.sub_scores ) {
		return null;
	}
	const subs = Object.entries( breakdown.sub_scores );
	return (
		<Panel>
			<PanelBody
				initialOpen={ false }
				title={ __( 'Full breakdown', 'agentready' ) }
			>
				{ subs.map( ( [ name, sub ] ) => {
					const value = Number( sub.value || 0 );
					const weight = Number( sub.weight || 0 );
					const reasons = Array.isArray( sub.reasons )
						? sub.reasons
						: [];
					const signals =
						sub.signals && typeof sub.signals === 'object'
							? sub.signals
							: {};
					return (
						<div
							key={ name }
							style={ {
								marginBottom: '16px',
								padding: '12px',
								background: '#fafafa',
								border: '1px solid #eee',
								borderRadius: '4px',
							} }
						>
							<h3
								style={ {
									marginTop: 0,
									marginBottom: '4px',
									fontSize: '14px',
								} }
							>
								{ SUB_SCORE_LABELS[ name ] || name }
								<span
									style={ {
										marginLeft: '8px',
										color: '#888',
										fontWeight: 400,
										fontSize: '12px',
									} }
								>
									{ sprintf(
										/* translators: 1: sub-score value. 2: weight. */
										__(
											'%1$d/100 · weight %2$d',
											'agentready'
										),
										value,
										weight
									) }
								</span>
							</h3>
							<ProgressBar
								value={ value }
								label={ sprintf(
									/* translators: %s: sub-score label */
									__( '%s score (0 to 100)', 'agentready' ),
									SUB_SCORE_LABELS[ name ] || name
								) }
							/>
							{ reasons.length > 0 && (
								<ul style={ { margin: '8px 0 0 18px' } }>
									{ reasons.map( ( reason, idx ) => (
										<li
											key={ idx }
											style={ { fontSize: '13px' } }
										>
											{ reason }
										</li>
									) ) }
								</ul>
							) }
							{ Object.keys( signals ).length > 0 && (
								<details style={ { marginTop: '8px' } }>
									<summary
										style={ {
											cursor: 'pointer',
											fontSize: '12px',
											color: '#555',
										} }
									>
										{ __( 'Raw signals', 'agentready' ) }
									</summary>
									<dl
										style={ {
											margin: '8px 0 0 0',
											fontSize: '12px',
											fontFamily: 'monospace',
										} }
									>
										{ Object.entries( signals ).map(
											( [ k, v ] ) => (
												<div
													key={ k }
													style={ {
														display: 'flex',
														gap: '8px',
													} }
												>
													<dt
														style={ {
															color: '#555',
														} }
													>
														{ k }
													</dt>
													<dd
														style={ {
															margin: 0,
															color: '#222',
														} }
													>
														{ typeof v === 'object'
															? JSON.stringify(
																	v
															  )
															: String( v ) }
													</dd>
												</div>
											)
										) }
									</dl>
								</details>
							) }
						</div>
					);
				} ) }
			</PanelBody>
		</Panel>
	);
}

function ContextScorePanel() {
	const bootstrap = readBootstrap();
	const [ breakdown, setBreakdown ] = useState(
		bootstrap && bootstrap.initialBreakdown
			? bootstrap.initialBreakdown
			: null
	);
	const [ loading, setLoading ] = useState(
		! ( bootstrap && bootstrap.initialBreakdown )
	);
	const [ pending, setPending ] = useState( false );
	const [ flash, setFlash ] = useState( null );

	const fetchBreakdown = useCallback( async () => {
		if ( ! bootstrap ) {
			return;
		}
		setLoading( true );
		try {
			const response = await apiFetch( {
				path: `/${ bootstrap.restNamespace }${ bootstrap.restBase }`,
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			setBreakdown( response );
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message:
					err.message ||
					__( 'Failed to load the Context Score.', 'agentready' ),
			} );
		} finally {
			setLoading( false );
		}
	}, [ bootstrap ] );

	// If the server painted no initial breakdown, fetch one on mount.
	useEffect( () => {
		if ( ! bootstrap ) {
			return;
		}
		if ( ! breakdown ) {
			fetchBreakdown();
		}
		// Intentionally not depending on `breakdown` — re-fetching every
		// time it changes would replace the recompute response with a
		// stale read. We only want the on-mount fetch.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const recompute = useCallback( async () => {
		if ( ! bootstrap ) {
			return;
		}
		setPending( true );
		setFlash( null );
		try {
			const response = await apiFetch( {
				path: `/${ bootstrap.restNamespace }${ bootstrap.restBase }/recompute`,
				method: 'POST',
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			setBreakdown( response );
			setFlash( {
				type: 'success',
				message: sprintf(
					/* translators: 1: overall score. 2: duration in ms. */
					__(
						'Context Score recomputed: %1$d/100 (%2$d ms).',
						'agentready'
					),
					Number( response.overall || 0 ),
					Number( response.recompute_duration_ms || 0 )
				),
			} );
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message: err.message || __( 'Recompute failed.', 'agentready' ),
			} );
		} finally {
			setPending( false );
		}
	}, [ bootstrap ] );

	// Auto-dismiss the success notice after a few seconds so it doesn't
	// dominate a screenshot. Error notices stay until manually dismissed.
	useEffect( () => {
		if ( ! flash || flash.type !== 'success' ) {
			return undefined;
		}
		const id = setTimeout(
			() => setFlash( null ),
			SUCCESS_NOTICE_TIMEOUT_MS
		);
		return () => clearTimeout( id );
	}, [ flash ] );

	if ( ! bootstrap ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'AgentReady Context Score UI failed to bootstrap. Reload the page; if the issue persists, check the browser console.',
					'agentready'
				) }
			</Notice>
		);
	}

	return (
		<div>
			<OverallCard
				breakdown={ breakdown }
				loading={ loading }
				pending={ pending }
				flash={ flash }
				onRecompute={ recompute }
				onDismissFlash={ () => setFlash( null ) }
			/>
			<WhatsMissing
				breakdown={ breakdown }
				profilePageUrl={ bootstrap.profilePageUrl }
			/>
			<SubScoreBreakdown breakdown={ breakdown } />
		</div>
	);
}

const target = document.querySelector( MOUNT_SELECTOR );
if ( target ) {
	const root = createRoot( target );
	root.render( <ContextScorePanel /> );
}
