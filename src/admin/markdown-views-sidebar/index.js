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
					<Spinner />{ ' ' }
					{ __( 'Loading preview…', 'agentready' ) }
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
