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
 * @package
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import '../shared/admin-ui.css';

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
							<pre className="agentready-md-pre">
								{ data.markdown }
							</pre>

							<div className="agentready-button-row">
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
								<p className="agentready-md-meta">
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
