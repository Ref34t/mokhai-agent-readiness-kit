/**
 * Gutenberg PluginDocumentSettingPanel: "Exclude from agent output" (#180).
 *
 * Binds a ToggleControl to the `_agentready_excluded` post meta (registered by
 * WPContext\Admin\Exclude_Meta) via useEntityProp. When on, the post is dropped
 * from /llms.txt, .md views, and #178 alternate advertising through the
 * `excluded` gate in Context_Profile_Settings::get_exposure_reason().
 *
 * @package
 */

import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { ToggleControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const META_KEY = '_agentready_excluded';

function ExcludePanel() {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	// Block-editor metaboxes only render for post types whose meta is exposed
	// in REST; bail when there's no post type (e.g. a non-block screen).
	if ( ! postType ) {
		return null;
	}

	const excluded = !! ( meta && meta[ META_KEY ] );

	return (
		<PluginDocumentSettingPanel
			name="agentready-exclude"
			title={ __( 'Agent output (agentready)', 'ai-readiness-kit' ) }
			className="agentready-exclude-panel"
		>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Exclude from agent output', 'ai-readiness-kit' ) }
				help={
					excluded
						? __(
								'Hidden from /llms.txt and .md views — agents will not ingest this content.',
								'ai-readiness-kit'
						  )
						: __(
								'Available to AI agents via /llms.txt and .md views.',
								'ai-readiness-kit'
						  )
				}
				checked={ excluded }
				onChange={ ( on ) =>
					setMeta( { ...( meta || {} ), [ META_KEY ]: on } )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'agentready-exclude', {
	render: ExcludePanel,
	icon: null,
} );
