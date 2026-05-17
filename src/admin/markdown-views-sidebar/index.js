/**
 * Gutenberg PluginDocumentSettingPanel for previewing the Markdown view
 * of the post being edited.
 *
 * Wires to the agentready/v1/markdown-views/preview REST endpoint (Phase 5).
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
				'agentready'
			);
		case 'status':
			return __(
				'Post status is not in the Context Profile’s exposed statuses.',
				'agentready'
			);
		case 'password':
			return __(
				'Post is password-protected. Markdown view never serves password-protected content.',
				'agentready'
			);
		case 'noindex':
			return __(
				'Post is flagged noindex by an SEO plugin.',
				'agentready'
			);
		default:
			return __( 'Hidden from agents.', 'agentready' );
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
				path: `/agentready/v1/markdown-views/preview?post=${ postId }`,
			} );
			setData( response );
		} catch ( err ) {
			setError(
				err && err.message
					? err.message
					: __( 'Failed to load preview.', 'agentready' )
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
			setError( __( 'Copy to clipboard failed.', 'agentready' ) );
		}
	}, [ data ] );

	return (
		<PluginDocumentSettingPanel
			name="agentready-md-preview"
			title={ __( 'Markdown view (agentready)', 'agentready' ) }
			className="agentready-md-preview"
		>
			{ loading && (
				<p>
					<Spinner /> { __( 'Loading preview…', 'agentready' ) }
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
						<strong>{ __( 'Visibility:', 'agentready' ) }</strong>{ ' ' }
						{ data.visibility.verdict === 'exposable'
							? __( 'Exposed to agents.', 'agentready' )
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
										? __( 'Copied!', 'agentready' )
										: __(
												'Copy to clipboard',
												'agentready'
										  ) }
								</Button>

								<Button
									variant="tertiary"
									onClick={ loadPreview }
								>
									{ __( 'Refresh', 'agentready' ) }
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
										{ __( 'Cache:', 'agentready' ) }
									</strong>{ ' ' }
									{ data.cache_state.cached
										? __( 'hit', 'agentready' )
										: __( 'miss', 'agentready' ) }
									{ ' · ' }
									{ __( 'walker v', 'agentready' ) }
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
				label: __( 'Cleanup running…', 'agentready' ),
				variant: 'info',
			};
		case 'done':
			return {
				label: __( 'Cleanup ready — review needed', 'agentready' ),
				variant: 'warning',
			};
		case 'approved':
			return {
				label: __(
					'Cleanup approved — serving cleaned MD',
					'agentready'
				),
				variant: 'success',
			};
		case 'rejected':
			return {
				label: __(
					'Cleanup rejected — serving deterministic',
					'agentready'
				),
				variant: 'info',
			};
		case 'needs-retry':
			return {
				label: __( 'Cleanup needs retry', 'agentready' ),
				variant: 'warning',
			};
		case 'failed':
			return {
				label: __( 'Cleanup failed', 'agentready' ),
				variant: 'error',
			};
		default:
			return {
				label: __(
					'Cleanup not yet evaluated for this post',
					'agentready'
				),
				variant: 'info',
			};
	}
}

/**
 * Side-by-side Markdown cleanup panel. Drives the four REST routes
 * under `agentready/v1/markdown-views/cleanup` per AgDR-0020.
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

	const loadState = useCallback( async () => {
		if ( ! postId ) {
			return;
		}

		setLoading( true );
		setError( null );

		try {
			const response = await apiFetch( {
				path: `/agentready/v1/markdown-views/cleanup?post=${ postId }`,
			} );
			setState( response );
		} catch ( err ) {
			setError(
				err && err.message
					? err.message
					: __( 'Failed to load cleanup state.', 'agentready' )
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

		const id = setInterval( loadState, 5000 );
		return () => clearInterval( id );
	}, [ state, loadState ] );

	const runAction = useCallback(
		async ( route ) => {
			if ( ! postId ) {
				return;
			}

			setActionInFlight( route );
			setError( null );

			try {
				const response = await apiFetch( {
					path: `/agentready/v1/markdown-views/cleanup/${ route }`,
					method: 'POST',
					data: { post_id: postId },
				} );
				setState( response );
			} catch ( err ) {
				setError(
					err && err.message
						? err.message
						: __( 'Cleanup action failed.', 'agentready' )
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
				title={ __( 'Markdown cleanup (agentready)', 'agentready' ) }
			>
				<p>
					<Spinner /> { __( 'Loading cleanup state…', 'agentready' ) }
				</p>
			</PluginDocumentSettingPanel>
		);
	}

	const status = state ? state.status : '';
	const badge = cleanupStatusBadge( status );
	const hasCleaned = state && state.cleaned_markdown;
	const canApproveReject = status === 'done';
	const canRegenerate = status && status !== 'pending';

	return (
		<PluginDocumentSettingPanel
			name="agentready-md-cleanup"
			title={ __( 'Markdown cleanup (agentready)', 'agentready' ) }
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

			{ state && state.quality_score !== null && (
				<p style={ { marginTop: '8px', fontSize: '12px' } }>
					<strong>{ __( 'Quality score:', 'agentready' ) }</strong>{ ' ' }
					{ state.quality_score } / 100
				</p>
			) }

			{ hasCleaned && (
				<div style={ { marginTop: '12px' } }>
					<p style={ { margin: '0 0 4px', fontSize: '12px' } }>
						<strong>
							{ __( 'Deterministic MD:', 'agentready' ) }
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
							{ __( 'LLM-cleaned MD:', 'agentready' ) }
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
							{ __( 'Cleanup diagnostics', 'agentready' ) }
						</summary>
						<p style={ { margin: '4px 0' } }>
							{ state.diagnostics.sentences_kept !== undefined &&
								`${ __( 'Sentences kept:', 'agentready' ) } ${
									state.diagnostics.sentences_kept
								} · ${ __( 'dropped:', 'agentready' ) } ${
									state.diagnostics.sentences_dropped
								}` }
						</p>
						{ state.diagnostics.error_code && (
							<p style={ { margin: '4px 0', color: '#a00' } }>
								{ __( 'Error:', 'agentready' ) }{ ' ' }
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
							{ __( 'Approve', 'agentready' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => runAction( 'reject' ) }
							disabled={ actionInFlight !== null }
							isBusy={ actionInFlight === 'reject' }
						>
							{ __( 'Reject', 'agentready' ) }
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
						{ __( 'Regenerate', 'agentready' ) }
					</Button>
				) }

				<Button
					variant="tertiary"
					onClick={ loadState }
					disabled={ loading || actionInFlight !== null }
				>
					{ __( 'Refresh', 'agentready' ) }
				</Button>
			</div>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'agentready-md-cleanup', {
	render: MarkdownCleanupPanel,
	icon: null,
} );
