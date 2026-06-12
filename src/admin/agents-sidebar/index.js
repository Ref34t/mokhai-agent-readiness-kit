/**
 * Gutenberg PluginDocumentSettingPanel: "AI Readiness" (#201, polished in #203).
 *
 * Consolidates the former "Agent output" exclude toggle (#180) and the
 * "Markdown view" preview (Phase 5 / AgDR-0014) into one panel, ordered
 * control → consequence → detail:
 *
 *  1. Exclude toggle — binds the `_agentready_excluded` post meta
 *     (registered by WPContext\Admin\Exclude_Meta) via useEntityProp.
 *  2. Visibility verdict — reflects the unsaved toggle state immediately
 *     for the exclude case; falls back to the server reason codes from
 *     Context_Profile_Settings::get_exposure_reason() otherwise.
 *  3. Markdown preview + copy / refresh + cache diagnostics, rendered only
 *     when the post is exposable and the toggle is off.
 *
 * The AgDR-0015 mount guard applies to the preview section only: when
 * `markdown_views_enabled === false` the toggle still renders, because
 * exclusion also gates /llms.txt and alternate-link advertising.
 *
 * @package
 */

import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { Button, Spinner, Notice, ToggleControl } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { humanTimeDiff } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import { Pill } from '../shared/Pill';
import '../shared/admin-ui.css';

const META_KEY = '_agentready_excluded';

/**
 * Convert a visibility reason code to a user-facing string.
 *
 * Reason codes come from Context_Profile_Settings::get_exposure_reason():
 * cpt | status | password | noindex | excluded | sample | null.
 *
 * @param {string|null} reason
 * @return {string} User-facing explanation of why the post is hidden.
 */
function reasonLabel( reason ) {
	switch ( reason ) {
		case 'cpt':
			return __(
				'Post type is not in the Context Profile’s exposed CPTs.',
				'agentready-ai-readiness-kit'
			);
		case 'status':
			return __(
				'Post status is not in the Context Profile’s exposed statuses.',
				'agentready-ai-readiness-kit'
			);
		case 'password':
			return __(
				'Post is password-protected. Markdown view never serves password-protected content.',
				'agentready-ai-readiness-kit'
			);
		case 'noindex':
			return __(
				'Post is flagged noindex by an SEO plugin.',
				'agentready-ai-readiness-kit'
			);
		case 'excluded':
			return __(
				'Post is on the agentready exclude list (per-post toggle or Context Profile exclude list).',
				'agentready-ai-readiness-kit'
			);
		case 'sample':
			return __(
				'Post is WordPress sample content excluded by the Context Profile.',
				'agentready-ai-readiness-kit'
			);
		default:
			return __( 'Hidden from agents.', 'agentready-ai-readiness-kit' );
	}
}

/**
 * Resolve the verdict line. The toggle-driven states (excluded ON, or
 * plain exposable) render nothing — the ToggleControl help text already
 * says it. The line only appears when the post is hidden for a reason the
 * toggle does NOT explain: server reason codes, or a saved exclusion the
 * unsaved toggle no longer reflects.
 *
 * @param {boolean}     excluded   Unsaved toggle state.
 * @param {Object|null} visibility Server visibility { verdict, reason }.
 * @return {string} Hidden-reason sentence, or empty when the toggle help text covers it.
 */
function verdictLabel( excluded, visibility ) {
	if ( excluded || ! visibility || visibility.verdict === 'exposable' ) {
		return '';
	}

	if ( visibility.reason === 'excluded' ) {
		return __(
			'Your last save excluded this post — it becomes visible to agents once you save again.',
			'agentready-ai-readiness-kit'
		);
	}

	return reasonLabel( visibility.reason );
}

function AgentsPanel() {
	const { postId, postType, moduleEnabled } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const settings = window.agentreadyAgentsSidebar || {};
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			moduleEnabled: settings.moduleEnabled !== false,
		};
	}, [] );

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const [ loading, setLoading ] = useState( false );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	const loadPreview = useCallback( async () => {
		if ( ! postId || ! moduleEnabled ) {
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
					: __( 'Failed to load preview.', 'agentready-ai-readiness-kit' )
			);
			setData( null );
		} finally {
			setLoading( false );
		}
	}, [ postId, moduleEnabled ] );

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
		} catch {
			setError( __( 'Copy to clipboard failed.', 'agentready-ai-readiness-kit' ) );
		}
	}, [ data ] );

	// Block-editor metaboxes only render for post types whose meta is exposed
	// in REST; bail when there's no post type (e.g. a non-block screen).
	if ( ! postType ) {
		return null;
	}

	const excluded = !! ( meta && meta[ META_KEY ] );
	const verdict = verdictLabel( excluded, data && data.visibility );
	const showPreview =
		moduleEnabled &&
		! excluded &&
		! loading &&
		! error &&
		data &&
		data.visibility.verdict === 'exposable';

	return (
		<PluginDocumentSettingPanel
			name="agentready-agents"
			title={ __( 'AI Readiness', 'agentready-ai-readiness-kit' ) }
			className="agentready-agents-panel"
		>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Exclude from agent output', 'agentready-ai-readiness-kit' ) }
				help={
					excluded
						? __(
								'Hidden from /llms.txt and .md views — agents will not ingest this content.',
								'agentready-ai-readiness-kit'
						  )
						: __(
								'Available to AI agents via /llms.txt and .md views.',
								'agentready-ai-readiness-kit'
						  )
				}
				checked={ excluded }
				onChange={ ( on ) =>
					setMeta( { ...( meta || {} ), [ META_KEY ]: on } )
				}
			/>

			{ verdict && (
				<p className="agentready-verdict">
					<Pill kind="stale">
						{ __( 'Hidden', 'agentready-ai-readiness-kit' ) }
					</Pill>{ ' ' }
					{ verdict }
				</p>
			) }

			{ moduleEnabled && loading && (
				<p>
					<Spinner /> { __( 'Loading preview…', 'agentready-ai-readiness-kit' ) }
				</p>
			) }

			{ moduleEnabled && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ showPreview && (
				<>
					<pre className="agentready-md-pre">{ data.markdown }</pre>

					<div className="agentready-button-row">
						<Button
							variant="secondary"
							onClick={ onCopy }
							disabled={ ! data.markdown }
						>
							{ copied
								? __( 'Copied!', 'agentready-ai-readiness-kit' )
								: __(
										'Copy to clipboard',
										'agentready-ai-readiness-kit'
								  ) }
						</Button>

						<Button variant="tertiary" onClick={ loadPreview }>
							{ __( 'Refresh', 'agentready-ai-readiness-kit' ) }
						</Button>
					</div>

					{ data.cache_state && (
						<p className="agentready-md-meta">
							<strong>
								{ __( 'Cache:', 'agentready-ai-readiness-kit' ) }
							</strong>{ ' ' }
							{ data.cache_state.cached
								? __( 'hit', 'agentready-ai-readiness-kit' )
								: __( 'miss', 'agentready-ai-readiness-kit' ) }
							{ ' · ' }
							{ __( 'walker v', 'agentready-ai-readiness-kit' ) }
							{ data.cache_state.walker_version }
							{ ' · ' }
							{ humanTimeDiff( data.cache_state.generated_at ) }
						</p>
					) }
				</>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'agentready-agents', {
	render: AgentsPanel,
	icon: null,
} );
