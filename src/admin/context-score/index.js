/**
 * AI Readiness Kit — Context Score admin UI (#10 / AgDR-0031).
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
 * All mutations route through `ai-readiness-kit/v1/context-score/recompute`
 * (POST) so the server is the source of truth — the UI is presentation
 * only. Initial paint reuses `bootstrap.initialBreakdown` to avoid a
 * round-trip when the cache is populated.
 */

import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useRef,
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
import { __, _n, sprintf } from '@wordpress/i18n';

const MOUNT_SELECTOR = '#agentready-context-score-root';
const BOOTSTRAP_KEY = 'agentreadyContextScore';

// Hide the success notice after this many ms so it doesn't dominate
// the screenshot the operator is taking to put in their deck.
const SUCCESS_NOTICE_TIMEOUT_MS = 4000;

const SUB_SCORE_LABELS = {
	discoverability: __( 'Discoverability', 'ai-readiness-kit' ),
	content_readability: __( 'Description coverage', 'ai-readiness-kit' ),
	schema_coverage: __( 'Schema coverage', 'ai-readiness-kit' ),
	exposure_safety: __( 'Exposure safety', 'ai-readiness-kit' ),
	integration_health: __( 'Integration health', 'ai-readiness-kit' ),
	md_conversion_quality: __(
		'Markdown conversion quality',
		'ai-readiness-kit'
	),
	multi_channel_discovery: __(
		'Multi-channel discovery',
		'ai-readiness-kit'
	),
};

// Human-readable labels for the narrative.degraded_reason vocabulary
// emitted by Narrative_Generator (#11 / AgDR-0032). The reason names
// the failure class so the operator can act — `unconfigured` points at
// AI Client setup, `rate_limit` says "try again later", etc.
const DEGRADED_REASON_LABELS = {
	unconfigured: __(
		'AI Client is not configured. Narrative is using deterministic templates.',
		'ai-readiness-kit'
	),
	rate_limit: __(
		'AI provider rate-limited the request. Narrative is using deterministic templates; the next recompute will retry.',
		'ai-readiness-kit'
	),
	network_error: __(
		'AI provider was unreachable. Narrative is using deterministic templates; the next recompute will retry.',
		'ai-readiness-kit'
	),
	permanent_error: __(
		'AI provider rejected the request (configuration issue). Narrative is using deterministic templates.',
		'ai-readiness-kit'
	),
	parse_error: __(
		'AI response could not be parsed. Narrative is using deterministic templates.',
		'ai-readiness-kit'
	),
	budget_exceeded: __(
		'AI generation exceeded the time budget. Narrative is using deterministic templates; it will retry on the next recompute.',
		'ai-readiness-kit'
	),
};

// Translatable templates for the Engine reason codes (#139 / AgDR-0047).
// Engine emits `{ code, args }` tokens alongside the English `reasons`
// strings; this map turns a code + positional args into a localised line.
// The set of keys here MUST mirror Engine's canonical reason-code set —
// the PHP Reason_Keys_Test pins that set, so a mismatch trips CI. An
// unknown code falls back to the English `reasons[i]` via renderReason().
const REASON_TEMPLATES = {
	// discoverability
	disc_llms_txt_populated: () =>
		__( '/llms.txt cache is populated.', 'ai-readiness-kit' ),
	disc_llms_txt_empty: () =>
		__(
			'/llms.txt cache is empty — agents cannot discover the site index.',
			'ai-readiness-kit'
		),
	disc_cpt_exposed: ( a ) =>
		sprintf(
			// translators: %d: number of post types exposed to agents.
			__( 'Site exposes %d post type(s) to agents.', 'ai-readiness-kit' ),
			a[ 0 ]
		),
	disc_no_cpt_exposed: () =>
		__(
			'No post types are exposed to agents (Context Profile → Exposed CPTs is empty).',
			'ai-readiness-kit'
		),
	disc_entries_listed: ( a ) =>
		sprintf(
			// translators: %d: number of entries listed in /llms.txt.
			__( '/llms.txt lists %d entries.', 'ai-readiness-kit' ),
			a[ 0 ]
		),
	disc_zero_entries: () =>
		__( '/llms.txt has zero entries.', 'ai-readiness-kit' ),
	disc_rewrite_conflict: () =>
		__(
			'Another plugin is overriding the /llms.txt rewrite rule.',
			'ai-readiness-kit'
		),
	// content_readability
	cr_no_exposed_entries: () =>
		__(
			'No exposed entries — nothing for agents to read.',
			'ai-readiness-kit'
		),
	cr_coverage_good: ( a ) =>
		sprintf(
			// translators: %d: percentage of entries with a curated description.
			__(
				'%d%% of exposed entries have a curated description.',
				'ai-readiness-kit'
			),
			a[ 0 ]
		),
	cr_coverage_medium: ( a ) =>
		sprintf(
			// translators: %d: percentage of entries with a curated description.
			__(
				'%d%% of exposed entries have a curated description — room to improve.',
				'ai-readiness-kit'
			),
			a[ 0 ]
		),
	cr_coverage_low: ( a ) =>
		sprintf(
			// translators: %d: percentage of entries with a curated description.
			__(
				'Only %d%% of exposed entries have a curated description.',
				'ai-readiness-kit'
			),
			a[ 0 ]
		),
	// schema_coverage
	sc_seo_plugin_detected: ( a ) =>
		sprintf(
			// translators: %s: detected SEO plugin name.
			__(
				'Detected SEO plugin (%s) — structured data is likely being emitted.',
				'ai-readiness-kit'
			),
			a[ 0 ]
		),
	sc_native_jsonld: () =>
		__(
			'AI Readiness Kit is emitting native JSON-LD (WebSite + Organization + per-content). Schema coverage satisfied without a third-party SEO plugin.',
			'ai-readiness-kit'
		),
	sc_no_structured_data: () =>
		__(
			'No structured data detected on this site. Enable Schema emission in the Context Profile to have AI Readiness Kit emit native JSON-LD, or rely on a third-party SEO plugin.',
			'ai-readiness-kit'
		),
	// exposure_safety
	es_only_published: () =>
		__(
			'Only published content is exposed to agents.',
			'ai-readiness-kit'
		),
	es_risky_statuses: ( a ) =>
		sprintf(
			// translators: %s: comma-separated list of non-publish post statuses.
			__(
				'Exposed statuses include %s — these can leak unpublished content to agents.',
				'ai-readiness-kit'
			),
			a[ 0 ]
		),
	es_cpt_explicit: () =>
		__(
			'Exposed CPTs are configured explicitly (no implicit defaults).',
			'ai-readiness-kit'
		),
	es_no_cpt: () =>
		__(
			'No CPTs exposed — safe-by-default, but agents will find nothing.',
			'ai-readiness-kit'
		),
	// integration_health
	ih_llm_configured: () =>
		__(
			'AI client configured and LLM features enabled.',
			'ai-readiness-kit'
		),
	ih_llm_disabled: () =>
		__(
			'LLM features disabled — no AI client required.',
			'ai-readiness-kit'
		),
	ih_llm_unconfigured: () =>
		__(
			'LLM features enabled but AI client is unconfigured — those features are silently degraded.',
			'ai-readiness-kit'
		),
	ih_llms_txt_conflict: ( a ) =>
		sprintf(
			// translators: %s: comma-separated list of /llms.txt conflict kinds.
			__( '/llms.txt conflict detected (%s).', 'ai-readiness-kit' ),
			a[ 0 ]
		),
	// md_conversion_quality
	mcq_no_cache: () =>
		__(
			'No Markdown Views cache rows yet — visit a few `.md` URLs to populate the cache.',
			'ai-readiness-kit'
		),
	mcq_mean_quality: ( a ) =>
		sprintf(
			// translators: 1: number of cached posts. 2: mean quality score 0-100.
			__(
				'Mean Markdown quality across %1$d cached posts: %2$d/100.',
				'ai-readiness-kit'
			),
			a[ 0 ],
			a[ 1 ]
		),
	mcq_above_threshold: ( a ) =>
		sprintf(
			// translators: 1: percentage above threshold. 2: MD-quality threshold value.
			__(
				'%1$d%% of cached posts are above the MD-quality threshold (%2$d).',
				'ai-readiness-kit'
			),
			a[ 0 ],
			a[ 1 ]
		),
	// multi_channel_discovery
	mcd_no_channels: () =>
		__(
			'No agent-discovery channels detected — site is invisible to agents that scan for ai.txt or /.well-known/ declarations.',
			'ai-readiness-kit'
		),
	mcd_channels_detected: ( a ) =>
		sprintf(
			// translators: %d: number of plugin-served agent-discovery channels detected (of 4).
			__(
				'%d of 4 plugin-served agent-discovery channel(s) detected.',
				'ai-readiness-kit'
			),
			a[ 0 ]
		),
	mcd_openapi_bonus: () =>
		__(
			'OpenAPI spec detected — bonus discovery channel for sites exposing an API.',
			'ai-readiness-kit'
		),
	mcd_provider_configurable: ( a ) =>
		sprintf(
			// translators: 1: sibling provider name. 2: provider admin config URL.
			__(
				'%1$s detected — coordinating multi-channel discovery. Configure at %2$s',
				'ai-readiness-kit'
			),
			a[ 0 ],
			a[ 1 ]
		),
	mcd_provider_detected: ( a ) =>
		sprintf(
			// translators: %s: sibling provider name.
			__(
				'%s detected — coordinating multi-channel discovery.',
				'ai-readiness-kit'
			),
			a[ 0 ]
		),
};

// Render a single Engine reason: localise via its `{code, args}` token when
// the code is known, otherwise fall back to the English string Engine also
// emits in the parallel `reasons` array (#139 / AgDR-0047).
function renderReason( reasonKey, fallback ) {
	if (
		reasonKey &&
		typeof reasonKey === 'object' &&
		typeof REASON_TEMPLATES[ reasonKey.code ] === 'function'
	) {
		const args = Array.isArray( reasonKey.args ) ? reasonKey.args : [];
		return REASON_TEMPLATES[ reasonKey.code ]( args );
	}
	return fallback;
}

// Localise the full parallel reasons/reason_keys pair into display strings,
// falling back element-wise to the English reasons.
function renderReasons( sub ) {
	const reasons = Array.isArray( sub.reasons ) ? sub.reasons : [];
	const keys = Array.isArray( sub.reason_keys ) ? sub.reason_keys : [];
	return reasons.map( ( text, i ) =>
		renderReason( keys[ i ], String( text ) )
	);
}

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

function narrativeFor( breakdown, name ) {
	if (
		! breakdown ||
		! breakdown.narrative ||
		! breakdown.narrative.sub_scores
	) {
		return null;
	}
	const entry = breakdown.narrative.sub_scores[ name ];
	if ( ! entry || typeof entry !== 'object' ) {
		return null;
	}
	return {
		why: typeof entry.why === 'string' ? entry.why : '',
		fix: typeof entry.fix === 'string' ? entry.fix : '',
		source: entry.source === 'llm' ? 'llm' : 'rule_based',
	};
}

function SourceBadge( { source } ) {
	const isLlm = source === 'llm';
	const label = isLlm
		? __( 'AI-generated', 'ai-readiness-kit' )
		: __( 'Rule-based', 'ai-readiness-kit' );
	return (
		<span
			style={ {
				display: 'inline-block',
				marginLeft: '8px',
				padding: '1px 6px',
				borderRadius: '8px',
				fontSize: '11px',
				fontWeight: 500,
				lineHeight: 1.4,
				color: isLlm ? '#0a4b78' : '#555',
				background: isLlm ? '#dbeafe' : '#eee',
			} }
		>
			{ label }
		</span>
	);
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
		return __( 'unknown', 'ai-readiness-kit' );
	}
	const then = Date.parse( iso );
	if ( Number.isNaN( then ) ) {
		return __( 'unknown', 'ai-readiness-kit' );
	}
	const diffSec = Math.max( 0, Math.round( ( Date.now() - then ) / 1000 ) );
	if ( diffSec < 60 ) {
		return __( 'just now', 'ai-readiness-kit' );
	}
	const diffMin = Math.round( diffSec / 60 );
	if ( diffMin < 60 ) {
		return sprintf(
			/* translators: %d: number of minutes ago */
			_n(
				'%d minute ago',
				'%d minutes ago',
				diffMin,
				'ai-readiness-kit'
			),
			diffMin
		);
	}
	const diffHr = Math.round( diffMin / 60 );
	if ( diffHr < 24 ) {
		return sprintf(
			/* translators: %d: number of hours ago */
			_n( '%d hour ago', '%d hours ago', diffHr, 'ai-readiness-kit' ),
			diffHr
		);
	}
	const diffDay = Math.round( diffHr / 24 );
	return sprintf(
		/* translators: %d: number of days ago */
		_n( '%d day ago', '%d days ago', diffDay, 'ai-readiness-kit' ),
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
					title={ __( 'Overall score', 'ai-readiness-kit' ) }
				>
					<Spinner />
					<p>
						{ __( 'Computing Context Score…', 'ai-readiness-kit' ) }
					</p>
				</PanelBody>
			</Panel>
		);
	}

	if ( ! breakdown ) {
		return (
			<Panel>
				<PanelBody
					initialOpen
					title={ __( 'Overall score', 'ai-readiness-kit' ) }
				>
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No Context Score breakdown is available yet. Click "Recompute now" to generate the first audit.',
							'ai-readiness-kit'
						) }
					</Notice>
					<Button
						variant="primary"
						onClick={ onRecompute }
						disabled={ pending }
					>
						{ pending
							? __( 'Recomputing…', 'ai-readiness-kit' )
							: __( 'Recompute now', 'ai-readiness-kit' ) }
					</Button>
				</PanelBody>
			</Panel>
		);
	}

	const overall = Number( breakdown.overall || 0 );
	const bucket = statusBucket( overall );
	const narrative =
		breakdown.narrative && typeof breakdown.narrative === 'object'
			? breakdown.narrative
			: null;
	// `llm_pending` is the transient "narrative is generating in the
	// background" state (#167 / AgDR-0051) — shown as an info notice, NOT a
	// degraded warning (the LLM hasn't failed, it just hasn't run yet).
	const llmPending = !! ( narrative && narrative.llm_pending );
	const degraded = narrative && narrative.degraded === true && ! llmPending;
	const degradedReason = degraded ? narrative.degraded_reason : null;
	const degradedMessage =
		degradedReason && DEGRADED_REASON_LABELS[ degradedReason ]
			? DEGRADED_REASON_LABELS[ degradedReason ]
			: __(
					'Narrative is using deterministic templates.',
					'ai-readiness-kit'
			  );

	return (
		<Panel>
			<PanelBody
				initialOpen
				title={ __( 'Overall score', 'ai-readiness-kit' ) }
			>
				{ flash && (
					<Notice status={ flash.type } onRemove={ onDismissFlash }>
						{ flash.message }
					</Notice>
				) }
				{ llmPending && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'AI narrative is generating in the background — it will appear here shortly.',
							'ai-readiness-kit'
						) }
					</Notice>
				) }
				{ degraded && (
					<Notice status="warning" isDismissible={ false }>
						{ degradedMessage }
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
								__( 'Good', 'ai-readiness-kit' ) }
							{ bucket.tone === 'recommended' &&
								__( 'Needs attention', 'ai-readiness-kit' ) }
							{ bucket.tone === 'critical' &&
								__( 'Critical', 'ai-readiness-kit' ) }
						</div>
					</div>
					<div style={ { flex: 1, minWidth: '200px' } }>
						<ProgressBar
							value={ overall }
							label={ __(
								'Overall Context Score (0 to 100)',
								'ai-readiness-kit'
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
								__( 'Last computed %s.', 'ai-readiness-kit' ),
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
								? __( 'Recomputing…', 'ai-readiness-kit' )
								: __( 'Recompute now', 'ai-readiness-kit' ) }
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
				label: __( 'Configure in Context Profile', 'ai-readiness-kit' ),
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
				reason: renderReasons( sub )[ 0 ] || '',
				narrative: narrativeFor( breakdown, name ),
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
				title={ __( 'What is missing', 'ai-readiness-kit' ) }
			>
				{ rows.length === 0 && (
					<p>
						{ __(
							'Every sub-score is at 100. Nothing actionable to surface.',
							'ai-readiness-kit'
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
														'ai-readiness-kit'
													),
													row.value,
													row.weight
												) }
											</span>
										</div>
										{ row.narrative ? (
											<>
												<div
													style={ {
														color: '#222',
														fontSize: '13px',
														marginBottom: '4px',
													} }
												>
													{ row.narrative.why }
													<SourceBadge
														source={
															row.narrative.source
														}
													/>
												</div>
												<div
													style={ {
														color: '#555',
														fontSize: '13px',
														fontStyle: 'italic',
													} }
												>
													{ sprintf(
														/* translators: %s: one-line fix suggestion. */
														__(
															'Fix: %s',
															'ai-readiness-kit'
														),
														row.narrative.fix
													) }
												</div>
											</>
										) : (
											<div
												style={ {
													color: '#444',
													fontSize: '13px',
												} }
											>
												{ row.reason }
											</div>
										) }
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
				title={ __( 'Full breakdown', 'ai-readiness-kit' ) }
			>
				{ subs.map( ( [ name, sub ] ) => {
					const value = Number( sub.value || 0 );
					const weight = Number( sub.weight || 0 );
					const reasons = renderReasons( sub );
					const signals =
						sub.signals && typeof sub.signals === 'object'
							? sub.signals
							: {};
					const narrative = narrativeFor( breakdown, name );
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
											'ai-readiness-kit'
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
									__(
										'%s score (0 to 100)',
										'ai-readiness-kit'
									),
									SUB_SCORE_LABELS[ name ] || name
								) }
							/>
							{ narrative && (
								<div
									style={ {
										marginTop: '8px',
										paddingBottom: '8px',
										borderBottom: '1px dashed #ddd',
									} }
								>
									<div
										style={ {
											color: '#222',
											fontSize: '13px',
										} }
									>
										{ narrative.why }
										<SourceBadge
											source={ narrative.source }
										/>
									</div>
									<div
										style={ {
											color: '#555',
											fontSize: '13px',
											fontStyle: 'italic',
											marginTop: '2px',
										} }
									>
										{ sprintf(
											/* translators: %s: one-line fix suggestion. */
											__( 'Fix: %s', 'ai-readiness-kit' ),
											narrative.fix
										) }
									</div>
								</div>
							) }
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
										{ __(
											'Raw signals',
											'ai-readiness-kit'
										) }
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

// Posture slug → human-readable label. Mirrors Schema_Coordination_Detector
// signatures so a posture coming back from the server always resolves.
const POSTURE_LABELS = {
	yoast: __( 'Yoast SEO', 'ai-readiness-kit' ),
	rank_math: __( 'Rank Math', 'ai-readiness-kit' ),
	aioseo: __( 'All in One SEO', 'ai-readiness-kit' ),
};

function TypeChip( { children, tone } ) {
	const palette =
		tone === 'deferred'
			? { color: '#0a4b78', background: '#dbeafe' }
			: { color: '#553a00', background: '#fff4cd' };
	return (
		<span
			style={ {
				display: 'inline-block',
				marginRight: '6px',
				marginBottom: '4px',
				padding: '2px 8px',
				borderRadius: '10px',
				fontSize: '12px',
				fontWeight: 500,
				color: palette.color,
				background: palette.background,
			} }
		>
			{ children }
		</span>
	);
}

// Schema Coordination panel (#12 / AgDR-0033). Documents which JSON-LD
// types are deferred to the active SEO plugin and which (if any)
// AI Readiness Kit fills via gap-fill. Static info — no mutations from this
// surface; the matrix is read-only state.
function SchemaCoordinationPanel( { coordination } ) {
	if ( ! coordination || typeof coordination !== 'object' ) {
		return null;
	}
	const posture = String( coordination.posture || 'none' );
	const label =
		POSTURE_LABELS[ posture ] ||
		( coordination.label ? String( coordination.label ) : '' );
	const baseline = Array.isArray( coordination.baseline )
		? coordination.baseline
		: [];
	const deferred = Array.isArray( coordination.deferred )
		? coordination.deferred
		: [];
	const filled = Array.isArray( coordination.filled )
		? coordination.filled
		: [];
	const emitting = coordination.emitting !== false;
	const profileOptIn = coordination.profileOptIn === true;
	const hasPlugin = posture !== 'none' && posture !== '';

	return (
		<Panel>
			<PanelBody
				initialOpen={ false }
				title={ __( 'Schema coordination', 'ai-readiness-kit' ) }
			>
				<p style={ { marginTop: 0 } }>
					{ hasPlugin &&
						sprintf(
							/* translators: %s: SEO plugin name */
							__(
								'%s is active. AI Readiness Kit defers JSON-LD coordination to it and only fills schema types it does not already provide.',
								'ai-readiness-kit'
							),
							label
						) }
					{ ! hasPlugin &&
						profileOptIn &&
						__(
							'No SEO plugin detected. AI Readiness Kit is emitting a minimal baseline schema set (site identity + content type) on the front-end.',
							'ai-readiness-kit'
						) }
					{ ! hasPlugin &&
						! profileOptIn &&
						__(
							'No SEO plugin detected and Schema emission is off in Context Profile. AI Readiness Kit is emitting nothing — enable Schema emission in the Profile to satisfy schema coverage.',
							'ai-readiness-kit'
						) }
				</p>
				<div
					style={ {
						display: 'grid',
						gridTemplateColumns: 'minmax(160px, max-content) 1fr',
						rowGap: '8px',
						columnGap: '12px',
						fontSize: '13px',
					} }
				>
					<div style={ { color: '#555', fontWeight: 600 } }>
						{ __( 'Baseline types', 'ai-readiness-kit' ) }
					</div>
					<div>
						{ baseline.length > 0
							? baseline.join( ', ' )
							: __( '(none)', 'ai-readiness-kit' ) }
					</div>

					<div style={ { color: '#555', fontWeight: 600 } }>
						{ __( 'Deferred to plugin', 'ai-readiness-kit' ) }
					</div>
					<div>
						{ deferred.length === 0 && (
							<span style={ { color: '#777' } }>
								{ __( '(none)', 'ai-readiness-kit' ) }
							</span>
						) }
						{ deferred.map( ( type ) => (
							<TypeChip key={ type } tone="deferred">
								{ type }
							</TypeChip>
						) ) }
					</div>

					<div style={ { color: '#555', fontWeight: 600 } }>
						{ __(
							'Filled by AI Readiness Kit',
							'ai-readiness-kit'
						) }
					</div>
					<div>
						{ filled.length === 0 && (
							<span style={ { color: '#777' } }>
								{ hasPlugin
									? __(
											'(none — every baseline type is covered by the active SEO plugin)',
											'ai-readiness-kit'
									  )
									: __( '(none)', 'ai-readiness-kit' ) }
							</span>
						) }
						{ filled.map( ( type ) => (
							<TypeChip key={ type } tone="filled">
								{ type }
							</TypeChip>
						) ) }
					</div>

					<div style={ { color: '#555', fontWeight: 600 } }>
						{ __( 'Emission on wp_head', 'ai-readiness-kit' ) }
					</div>
					<div>
						{ emitting && __( 'Enabled', 'ai-readiness-kit' ) }
						{ ! emitting &&
							! profileOptIn &&
							__(
								'Off — toggle in Context Profile → Schema emission',
								'ai-readiness-kit'
							) }
						{ ! emitting &&
							profileOptIn &&
							__(
								'Suppressed by agentready_schema_emit filter',
								'ai-readiness-kit'
							) }
					</div>
				</div>
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
	// Bounds the llm_pending silent-poll so a never-arriving narrative (e.g.
	// WP-Cron not firing) can't poll forever while the page sits open.
	const narrativePollsRef = useRef( 0 );

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
					__(
						'Failed to load the Context Score.',
						'ai-readiness-kit'
					),
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
						'ai-readiness-kit'
					),
					Number( response.overall || 0 ),
					Number( response.recompute_duration_ms || 0 )
				),
			} );
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message:
					err.message ||
					__( 'Recompute failed.', 'ai-readiness-kit' ),
			} );
		} finally {
			setPending( false );
		}
	}, [ bootstrap ] );

	// While the LLM narrative is generating in the background (#167 /
	// AgDR-0051), silently re-fetch every few seconds so the UI upgrades from
	// the rule-based placeholder to the LLM narrative without a manual reload.
	// Self-terminates when `llm_pending` clears; bounded as a backstop.
	useEffect( () => {
		const MAX_NARRATIVE_POLLS = 12;
		const isPending = !! (
			bootstrap &&
			breakdown &&
			breakdown.narrative &&
			breakdown.narrative.llm_pending
		);
		if ( ! isPending ) {
			narrativePollsRef.current = 0;
			return undefined;
		}
		if ( narrativePollsRef.current >= MAX_NARRATIVE_POLLS ) {
			return undefined;
		}
		const id = setTimeout( () => {
			narrativePollsRef.current += 1;
			apiFetch( {
				path: `/${ bootstrap.restNamespace }${ bootstrap.restBase }`,
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} )
				.then( ( response ) => setBreakdown( response ) )
				.catch( () => {} );
		}, 5000 );
		return () => clearTimeout( id );
	}, [ bootstrap, breakdown ] );

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
					'AI Readiness Kit Context Score UI failed to bootstrap. Reload the page; if the issue persists, check the browser console.',
					'ai-readiness-kit'
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
			<SchemaCoordinationPanel
				coordination={ bootstrap.schemaCoordination }
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
