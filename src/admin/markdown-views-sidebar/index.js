/**
 * Gutenberg PluginDocumentSettingPanel for previewing the Markdown view
 * of the post being edited.
 *
 * Wires to the ai-readiness-kit/v1/markdown-views/preview REST endpoint (Phase 5).
 * Renders the MD body in a read-only <pre> with a copy-to-clipboard button,
 * plus a visibility verdict line and a cache-state diagnostics row.
 *
 * Mount guard: the panel hides itself when the Context Profile reports
 * `markdown_views_enabled === false` (AgDR-0015 soft-disable).
 *
 * @package WPContext
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Convert a visibility reason code to a user-facing string.
 *
 * Reason codes come from Context_Profile_Settings::get_exposure_reason():
 * cpt | status | password | noindex | null.
 *
 * @param {string|null} reason
 * @return {string}
 */
function reasonLabel( reason ) {
	switch ( reason ) {
		case 'cpt':
			return __(
				'Post type is not in the Context Profile’s exposed CPTs.',
				'ai-readiness-kit'
			);
		case 'status':
			return __(
				'Post status is not in the Context Profile’s exposed statuses.',
				'ai-readiness-kit'
			);
		case 'password':
			return __(
				'Post is password-protected. Markdown view never serves password-protected content.',
				'ai-readiness-kit'
			);
		case 'noindex':
			return __(
				'Post is flagged noindex by an SEO plugin.',
				'ai-readiness-kit'
			);
		default:
			return __( 'Hidden from agents.', 'ai-readiness-kit' );
	}
}

function MarkdownViewsPanel() {
	const { postId, moduleEnabled } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const settings = window.agentreadyMarkdownViews || {};
		return {
			postId: editor.getCurrentPostId(),
			moduleEnabled: settings.moduleEnabled !== false,
		};
	}, [] );

	const [ loading, setLoading ] = useState( false );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	// AgDR-0015 mount guard: if the module is toggled off, render nothing.
	// The toggle lives on the Context Profile page — keep the post-edit
	// surface free of "this is disabled" admin noise.
	if ( ! moduleEnabled ) {
		return null;
	}

	const loadPreview = useCallback( async () => {
		if ( ! postId ) {
			return;
		}

		setLoading( true );
		setError( null );
		setCopied( false );

		try {
			const response = await apiFetch( {
				path: `/ai-readiness-kit/v1/markdown-views/preview?post=${ postId }`,
			} );
			setData( response );
		} catch ( err ) {
			setError(
				err && err.message
					? err.message
					: __( 'Failed to load preview.', 'ai-readiness-kit' )
			);
			setData( null );
		} finally {
			setLoading( false );
		}
	}, [ postId ] );

	useEffect( () => {
		if ( postId ) {
			loadPreview();
		}
	}, [ postId, loadPreview ] );

	const onCopy = useCallback( async () => {
		if ( ! data || ! data.markdown ) {
			return;
		}

		try {
			await navigator.clipboard.writeText( data.markdown );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( err ) {
			setError( __( 'Copy to clipboard failed.', 'ai-readiness-kit' ) );
		}
	}, [ data ] );

	return (
		<PluginDocumentSettingPanel
			name="agentready-md-preview"
			title={ __( 'Markdown view (agentready)', 'ai-readiness-kit' ) }
			className="agentready-md-preview"
		>
			{ loading && (
				<p>
					<Spinner /> { __( 'Loading preview…', 'ai-readiness-kit' ) }
				</p>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && data && (
				<>
					<p>
						<strong>
							{ __( 'Visibility:', 'ai-readiness-kit' ) }
						</strong>{ ' ' }
						{ data.visibility.verdict === 'exposable'
							? __( 'Exposed to agents.', 'ai-readiness-kit' )
							: reasonLabel( data.visibility.reason ) }
					</p>

					{ data.visibility.verdict === 'exposable' && (
						<>
							<pre
								style={ {
									maxHeight: '240px',
									overflow: 'auto',
									padding: '8px',
									background: '#f6f7f7',
									border: '1px solid #ddd',
									fontFamily:
										'Consolas, Menlo, Monaco, monospace',
									fontSize: '12px',
									whiteSpace: 'pre-wrap',
								} }
							>
								{ data.markdown }
							</pre>

							<div
								style={ {
									display: 'flex',
									gap: '8px',
									marginTop: '8px',
								} }
							>
								<Button
									variant="secondary"
									onClick={ onCopy }
									disabled={ ! data.markdown }
								>
									{ copied
										? __( 'Copied!', 'ai-readiness-kit' )
										: __(
												'Copy to clipboard',
												'ai-readiness-kit'
										  ) }
								</Button>

								<Button
									variant="tertiary"
									onClick={ loadPreview }
								>
									{ __( 'Refresh', 'ai-readiness-kit' ) }
								</Button>
							</div>

							{ data.cache_state && (
								<p
									style={ {
										marginTop: '12px',
										fontSize: '11px',
										color: '#6b6b6b',
									} }
								>
									<strong>
										{ __( 'Cache:', 'ai-readiness-kit' ) }
									</strong>{ ' ' }
									{ data.cache_state.cached
										? __( 'hit', 'ai-readiness-kit' )
										: __( 'miss', 'ai-readiness-kit' ) }
									{ ' · ' }
									{ __( 'walker v', 'ai-readiness-kit' ) }
									{ data.cache_state.walker_version }
									{ ' · ' }
									{ data.cache_state.generated_at }
								</p>
							) }
						</>
					) }
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'agentready-md-preview', {
	render: MarkdownViewsPanel,
	icon: null,
} );

/**
 * Threshold (ms) beyond which a `pending` cleanup is considered
 * "stuck" — i.e. WP cron hasn't fired the scheduled event. On a
 * low-traffic site WP cron doesn't fire without page-visits, so a
 * cleanup can sit pending indefinitely with no visible cause. After
 * this threshold the panel surfaces a help line explaining why.
 *
 * Per ticket #53: 60 seconds is short enough that real-traffic sites
 * almost never see the hint (their cleanup transitions on the next
 * pageview), long enough that fast-cron transitions don't surface it.
 */
const STUCK_PENDING_THRESHOLD_MS = 60000;

/**
 * Convert a cleanup-status code to a user-facing label + Notice variant.
 *
 * Status codes come from Cleanup_Orchestrator (AgDR-0020):
 * '' | pending | done | approved | rejected | needs-retry | failed.
 *
 * @param {string} status Status string from the cleanup state blob.
 * @return {{ label: string, variant: 'info'|'success'|'warning'|'error' }}
 *         Display label and matching Notice colour variant.
 */
function cleanupStatusBadge( status ) {
	switch ( status ) {
		case 'pending':
			return {
				label: __( 'Cleanup running…', 'ai-readiness-kit' ),
				variant: 'info',
			};
		case 'done':
			return {
				label: __(
					'Cleanup ready — review needed',
					'ai-readiness-kit'
				),
				variant: 'warning',
			};
		case 'approved':
			return {
				label: __(
					'Cleanup approved — serving cleaned MD',
					'ai-readiness-kit'
				),
				variant: 'success',
			};
		case 'rejected':
			return {
				label: __(
					'Cleanup rejected — serving deterministic',
					'ai-readiness-kit'
				),
				variant: 'info',
			};
		case 'needs-retry':
			return {
				label: __( 'Cleanup needs retry', 'ai-readiness-kit' ),
				variant: 'warning',
			};
		case 'failed':
			return {
				label: __( 'Cleanup failed', 'ai-readiness-kit' ),
				variant: 'error',
			};
		default:
			return {
				label: __(
					'Cleanup not yet evaluated for this post',
					'ai-readiness-kit'
				),
				variant: 'info',
			};
	}
}

/**
 * Side-by-side Markdown cleanup panel. Drives the four REST routes
 * under `ai-readiness-kit/v1/markdown-views/cleanup` per AgDR-0020.
 */
function MarkdownCleanupPanel() {
	const { postId, moduleEnabled } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const settings = window.agentreadyMarkdownViews || {};
		return {
			postId: editor.getCurrentPostId(),
			moduleEnabled: settings.moduleEnabled !== false,
		};
	}, [] );

	const [ loading, setLoading ] = useState( false );
	const [ actionInFlight, setActionInFlight ] = useState( null );
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( null );
	// Timestamp (ms) when the panel first observed status=pending in
	// the current run. Cleared when status transitions away. Used by
	// the stuck-pending UX hint per ticket #53.
	const [ pendingSince, setPendingSince ] = useState( null );
	// Tick counter that bumps every 5s while pending, so the
	// "stuck > N seconds" derived value re-evaluates and the hint
	// appears without needing a state transition to trigger a re-render.
	const [ tick, setTick ] = useState( 0 );

	const loadState = useCallback( async () => {
		if ( ! postId ) {
			return;
		}

		setLoading( true );
		setError( null );

		try {
			const response = await apiFetch( {
				path: `/ai-readiness-kit/v1/markdown-views/cleanup?post=${ postId }`,
			} );
			setState( response );
		} catch ( err ) {
			setError(
				err && err.message
					? err.message
					: __( 'Failed to load cleanup state.', 'ai-readiness-kit' )
			);
			setState( null );
		} finally {
			setLoading( false );
		}
	}, [ postId ] );

	useEffect( () => {
		if ( postId ) {
			loadState();
		}
	}, [ postId, loadState ] );

	// Poll every 5s while a cleanup is running so the editor sees the
	// transition from `pending` → `done` / `needs-retry` without manual
	// reloads.
	useEffect( () => {
		if ( ! state || state.status !== 'pending' ) {
			return undefined;
		}

		const id = setInterval( () => {
			loadState();
			// Bump the tick so the "stuck > 60s" derived value is
			// re-evaluated on every poll even when the REST response
			// is unchanged.
			setTick( ( t ) => t + 1 );
		}, 5000 );
		return () => clearInterval( id );
	}, [ state, loadState ] );

	// Track when `pending` first appeared so the stuck-pending hint
	// (ticket #53) can fire after a configurable threshold without
	// triggering on transient pending states.
	useEffect( () => {
		if ( state && state.status === 'pending' ) {
			if ( pendingSince === null ) {
				setPendingSince( Date.now() );
			}
		} else if ( pendingSince !== null ) {
			setPendingSince( null );
		}
	}, [ state, pendingSince ] );

	const runAction = useCallback(
		async ( route ) => {
			if ( ! postId ) {
				return;
			}

			setActionInFlight( route );
			setError( null );

			try {
				const response = await apiFetch( {
					path: `/ai-readiness-kit/v1/markdown-views/cleanup/${ route }`,
					method: 'POST',
					data: { post_id: postId },
				} );
				setState( response );
			} catch ( err ) {
				setError(
					err && err.message
						? err.message
						: __( 'Cleanup action failed.', 'ai-readiness-kit' )
				);
			} finally {
				setActionInFlight( null );
			}
		},
		[ postId ]
	);

	// AgDR-0015 mount guard: bail out AFTER all hook calls so React's
	// rules-of-hooks invariant holds. The early return below would
	// otherwise call hooks conditionally on subsequent renders.
	if ( ! moduleEnabled ) {
		return null;
	}

	if ( loading && ! state ) {
		return (
			<PluginDocumentSettingPanel
				name="agentready-md-cleanup"
				title={ __(
					'Markdown cleanup (agentready)',
					'ai-readiness-kit'
				) }
			>
				<p>
					<Spinner />{ ' ' }
					{ __( 'Loading cleanup state…', 'ai-readiness-kit' ) }
				</p>
			</PluginDocumentSettingPanel>
		);
	}

	const status = state ? state.status : '';
	const badge = cleanupStatusBadge( status );
	const hasCleaned = state && state.cleaned_markdown;
	const canApproveReject = status === 'done';
	const canRegenerate = status && status !== 'pending';
	// Derived from `pendingSince` + `tick` — re-evaluates on every poll.
	// `tick` is in the dep chain implicitly via the re-render trigger.
	const stuckPending =
		status === 'pending' &&
		pendingSince !== null &&
		Date.now() - pendingSince > STUCK_PENDING_THRESHOLD_MS;
	// Reference `tick` so eslint doesn't flag it as unused even though
	// its only purpose is to force a re-render.
	void tick;

	return (
		<PluginDocumentSettingPanel
			name="agentready-md-cleanup"
			title={ __( 'Markdown cleanup (agentready)', 'ai-readiness-kit' ) }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Notice
				status={ badge.variant }
				isDismissible={ false }
				className="agentready-md-cleanup__status"
			>
				{ badge.label }
			</Notice>

			{ stuckPending && (
				<p
					style={ {
						marginTop: '8px',
						fontSize: '11px',
						color: '#6b6b6b',
						lineHeight: '1.45',
					} }
					className="agentready-md-cleanup__stuck-hint"
				>
					{ __(
						'Cleanup is queued but waiting for WordPress cron. WP cron only fires on page visits — on a low-traffic site this can take a few minutes. Visiting any page on the site (or running `wp cron event run agentready_md_cleanup_run` via WP-CLI) will trigger it immediately.',
						'ai-readiness-kit'
					) }
				</p>
			) }

			{ state && state.quality_score !== null && (
				<p style={ { marginTop: '8px', fontSize: '12px' } }>
					<strong>
						{ __( 'Quality score:', 'ai-readiness-kit' ) }
					</strong>{ ' ' }
					{ state.quality_score } / 100
				</p>
			) }

			{ hasCleaned && (
				<div style={ { marginTop: '12px' } }>
					<p style={ { margin: '0 0 4px', fontSize: '12px' } }>
						<strong>
							{ __( 'Deterministic MD:', 'ai-readiness-kit' ) }
						</strong>
					</p>
					<pre
						style={ {
							maxHeight: '180px',
							overflow: 'auto',
							padding: '8px',
							background: '#f6f7f7',
							border: '1px solid #ddd',
							fontFamily: 'Consolas, Menlo, Monaco, monospace',
							fontSize: '11px',
							whiteSpace: 'pre-wrap',
							margin: 0,
						} }
					>
						{ state.deterministic_markdown }
					</pre>

					<p style={ { margin: '8px 0 4px', fontSize: '12px' } }>
						<strong>
							{ __( 'LLM-cleaned MD:', 'ai-readiness-kit' ) }
						</strong>
					</p>
					<pre
						style={ {
							maxHeight: '180px',
							overflow: 'auto',
							padding: '8px',
							background: '#f0f6fc',
							border: '1px solid #c5d9ed',
							fontFamily: 'Consolas, Menlo, Monaco, monospace',
							fontSize: '11px',
							whiteSpace: 'pre-wrap',
							margin: 0,
						} }
					>
						{ state.cleaned_markdown }
					</pre>
				</div>
			) }

			{ state &&
				state.diagnostics &&
				( state.diagnostics.sentences_dropped > 0 ||
					state.diagnostics.error_code ) && (
					<details style={ { marginTop: '12px', fontSize: '12px' } }>
						<summary>
							{ __( 'Cleanup diagnostics', 'ai-readiness-kit' ) }
						</summary>
						<p style={ { margin: '4px 0' } }>
							{ state.diagnostics.sentences_kept !== undefined &&
								`${ __(
									'Sentences kept:',
									'ai-readiness-kit'
								) } ${
									state.diagnostics.sentences_kept
								} · ${ __( 'dropped:', 'ai-readiness-kit' ) } ${
									state.diagnostics.sentences_dropped
								}` }
						</p>
						{ state.diagnostics.error_code && (
							<p style={ { margin: '4px 0', color: '#a00' } }>
								{ __( 'Error:', 'ai-readiness-kit' ) }{ ' ' }
								{ state.diagnostics.error_code }
							</p>
						) }
					</details>
				) }

			<div
				style={ {
					display: 'flex',
					gap: '8px',
					marginTop: '12px',
					flexWrap: 'wrap',
				} }
			>
				{ canApproveReject && (
					<>
						<Button
							variant="primary"
							onClick={ () => runAction( 'approve' ) }
							disabled={ actionInFlight !== null }
							isBusy={ actionInFlight === 'approve' }
						>
							{ __( 'Approve', 'ai-readiness-kit' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => runAction( 'reject' ) }
							disabled={ actionInFlight !== null }
							isBusy={ actionInFlight === 'reject' }
						>
							{ __( 'Reject', 'ai-readiness-kit' ) }
						</Button>
					</>
				) }

				{ canRegenerate && (
					<Button
						variant="tertiary"
						onClick={ () => runAction( 'regenerate' ) }
						disabled={ actionInFlight !== null }
						isBusy={ actionInFlight === 'regenerate' }
					>
						{ __( 'Regenerate', 'ai-readiness-kit' ) }
					</Button>
				) }

				<Button
					variant="tertiary"
					onClick={ loadState }
					disabled={ loading || actionInFlight !== null }
				>
					{ __( 'Refresh', 'ai-readiness-kit' ) }
				</Button>
			</div>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'agentready-md-cleanup', {
	render: MarkdownCleanupPanel,
	icon: null,
} );
