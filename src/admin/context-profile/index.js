/**
 * AI Readiness Kit — Context Profile admin UI.
 *
 * React-based editor for the FR-1 keystone. Reads the server-rendered
 * bootstrap payload from `window.agentreadyContextProfile`, posts back via
 * the standard options.php Settings API flow.
 *
 * Safe-by-default rule (FR-9): a fresh install ships with `exposed_cpts: []`
 * — the agency lead must explicitly opt in CPTs and statuses before any
 * agent-facing surface emits content.
 */

import { createRoot, useState, useMemo } from '@wordpress/element';
import {
	Panel,
	PanelBody,
	PanelRow,
	ToggleControl,
	CheckboxControl,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import '../shared/admin-ui.css';

const MOUNT_SELECTOR = '#agentready-context-profile-root';
const BOOTSTRAP_KEY = 'agentreadyContextProfile';

/**
 * Read the bootstrap payload injected by the PHP enqueue.
 *
 * @return {object|null} Bootstrap data, or null if the payload is missing
 *                      (signals a misconfigured enqueue — UI renders a
 *                      Notice rather than crashing).
 */
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

/**
 * Toggle a slug in a string-array, keeping the array sorted + de-duplicated.
 *
 * @param {string[]} list Current list.
 * @param {string}   slug Slug to add/remove.
 * @param {boolean}  on   Add when true, remove when false.
 * @return {string[]} New list (immutable update).
 */
function toggleSlug( list, slug, on ) {
	const set = new Set( list );
	if ( on ) {
		set.add( slug );
	} else {
		set.delete( slug );
	}
	return Array.from( set );
}

function ContextProfileApp( { bootstrap } ) {
	const {
		profile: initialProfile,
		cptOptions,
		statusOptions,
		siteIdentity,
		schemaCoordination,
		aiClient,
		settings,
	} = bootstrap;

	const [ profile, setProfile ] = useState( initialProfile );

	const aiNotice = useMemo( () => {
		if ( aiClient.configured ) {
			return null;
		}
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'WP AI Client is not configured. The LLM toggles below stay on but degrade silently — outputs fall back to deterministic equivalents until a provider is configured.',
					'ai-readiness-kit'
				) }
			</Notice>
		);
	}, [ aiClient.configured ] );

	const schemaPostureLabel = useMemo( () => {
		if ( schemaCoordination.posture === 'none' ) {
			return __(
				'No SEO plugin detected — AI Readiness Kit will emit its own JSON-LD schema.',
				'ai-readiness-kit'
			);
		}
		return sprintf(
			/* translators: %s: SEO plugin label (e.g. "Yoast SEO") */
			__(
				'Deferring JSON-LD to %s. AI Readiness Kit fills only the schema gaps that plugin does not provide.',
				'ai-readiness-kit'
			),
			schemaCoordination.label
		);
	}, [ schemaCoordination.label, schemaCoordination.posture ] );

	const updateField = ( field, value ) => {
		setProfile( ( prev ) => ( { ...prev, [ field ]: value } ) );
	};

	// Mirror of WP's wp_referer_field() — options.php reads this to redirect
	// back to the calling screen with ?settings-updated=true, which is what
	// triggers the "Settings saved." admin notice via settings_errors().
	// Without it the user lands on raw /wp-admin/options.php with no feedback.
	const referer =
		typeof window !== 'undefined'
			? window.location.pathname + window.location.search
			: '';

	return (
		<form
			action={ settings.optionsUrl }
			method="post"
			aria-label={ __(
				'AI Readiness Kit Context Profile form',
				'ai-readiness-kit'
			) }
		>
			{ /* Settings API plumbing — option_page + action + nonce + referer. */ }
			<input
				type="hidden"
				name="option_page"
				value={ settings.optionGroup }
			/>
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="_wpnonce" value={ settings.nonce } />
			<input type="hidden" name="_wp_http_referer" value={ referer } />

			{ /* Whole profile encoded as one POST payload — keeps the
			     sanitiser the only write path. schema_version is preserved
			     so future migrations have a starting version. */ }
			<input
				type="hidden"
				name={ `${ settings.optionKey }[schema_version]` }
				value={ profile.schema_version }
			/>

			<Panel
				header={ __( 'Site identity', 'ai-readiness-kit' ) }
				className="agentready-context-profile-panel"
			>
				<PanelBody opened>
					<PanelRow>
						<p>
							<strong>
								{ __( 'Site name:', 'ai-readiness-kit' ) }
							</strong>{ ' ' }
							{ siteIdentity.name }
						</p>
					</PanelRow>
					<PanelRow>
						<p>
							<strong>
								{ __( 'Tagline:', 'ai-readiness-kit' ) }
							</strong>{ ' ' }
							{ siteIdentity.tagline ||
								__( '(none)', 'ai-readiness-kit' ) }
						</p>
					</PanelRow>
					<PanelRow>
						<p>
							<strong>
								{ __( 'Locale:', 'ai-readiness-kit' ) }
							</strong>{ ' ' }
							{ siteIdentity.locale }
						</p>
					</PanelRow>
					<PanelRow>
						<p className="description">
							{ __(
								'Site identity is read live from WordPress General Settings. Edit it there to change what AI Readiness Kit emits.',
								'ai-readiness-kit'
							) }
						</p>
					</PanelRow>
				</PanelBody>
			</Panel>

			<Panel
				header={ __( 'Exposed content', 'ai-readiness-kit' ) }
				className="agentready-context-profile-panel"
			>
				<PanelBody
					title={ __( 'Custom post types', 'ai-readiness-kit' ) }
					initialOpen
				>
					<PanelRow>
						<p className="description">
							{ __(
								'A fresh install exposes nothing. Tick every post type you want available to AI agents via /llms.txt and .md views.',
								'ai-readiness-kit'
							) }
						</p>
					</PanelRow>
					<fieldset
						aria-labelledby="agentready-cpts-legend"
						className="agentready-checkbox-fieldset"
					>
						<legend
							id="agentready-cpts-legend"
							className="screen-reader-text"
						>
							{ __(
								'Custom post types to expose',
								'ai-readiness-kit'
							) }
						</legend>
						{ cptOptions.length === 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'No public custom post types registered on this site.',
									'ai-readiness-kit'
								) }
							</Notice>
						) }
						{ cptOptions.map( ( cpt ) => (
							<div key={ cpt.slug }>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ `${ cpt.label } (${ cpt.slug })` }
									checked={ profile.exposed_cpts.includes(
										cpt.slug
									) }
									onChange={ ( on ) =>
										updateField(
											'exposed_cpts',
											toggleSlug(
												profile.exposed_cpts,
												cpt.slug,
												on
											)
										)
									}
								/>
								{ profile.exposed_cpts.includes( cpt.slug ) && (
									<input
										type="hidden"
										name={ `${ settings.optionKey }[exposed_cpts][]` }
										value={ cpt.slug }
									/>
								) }
							</div>
						) ) }
					</fieldset>
				</PanelBody>

				<PanelBody
					title={ __( 'Post statuses', 'ai-readiness-kit' ) }
					initialOpen={ false }
				>
					<PanelRow>
						<p className="description">
							{ __(
								'"Published" is the only status enabled by default. Exposing private / draft / pending content to agents requires deliberate opt-in.',
								'ai-readiness-kit'
							) }
						</p>
					</PanelRow>
					<fieldset
						aria-labelledby="agentready-statuses-legend"
						className="agentready-checkbox-fieldset"
					>
						<legend
							id="agentready-statuses-legend"
							className="screen-reader-text"
						>
							{ __(
								'Post statuses to expose',
								'ai-readiness-kit'
							) }
						</legend>
						{ statusOptions.map( ( status ) => (
							<div key={ status.slug }>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ status.label }
									checked={ profile.exposed_statuses.includes(
										status.slug
									) }
									onChange={ ( on ) =>
										updateField(
											'exposed_statuses',
											toggleSlug(
												profile.exposed_statuses,
												status.slug,
												on
											)
										)
									}
								/>
								{ profile.exposed_statuses.includes(
									status.slug
								) && (
									<input
										type="hidden"
										name={ `${ settings.optionKey }[exposed_statuses][]` }
										value={ status.slug }
									/>
								) }
							</div>
						) ) }
					</fieldset>
				</PanelBody>
			</Panel>

			<Panel
				header={ __( 'Schema coordination', 'ai-readiness-kit' ) }
				className="agentready-context-profile-panel"
			>
				<PanelBody opened>
					<PanelRow>
						<p>{ schemaPostureLabel }</p>
					</PanelRow>
					<PanelRow>
						<p className="description">
							{ __(
								'Auto-detected. Activate or deactivate your SEO plugin to change this.',
								'ai-readiness-kit'
							) }
						</p>
					</PanelRow>
				</PanelBody>
			</Panel>

			<Panel
				header={ __( 'Schema emission', 'ai-readiness-kit' ) }
				className="agentready-context-profile-panel"
			>
				<PanelBody opened>
					<PanelRow>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Emit native JSON-LD on the front-end',
								'ai-readiness-kit'
							) }
							help={ __(
								'When on, AI Readiness Kit emits WebSite + Organization site-identity JSON-LD on every page, plus an Article node for exposed posts and a WebPage node for exposed pages. Stays silent when a supported SEO plugin is detected (Yoast, Rank Math, AIOSEO). Default off — opt in to satisfy Context Score schema coverage without a third-party plugin.',
								'ai-readiness-kit'
							) }
							checked={ profile.schema_emit_enabled }
							onChange={ ( on ) =>
								updateField( 'schema_emit_enabled', on )
							}
						/>
						{ profile.schema_emit_enabled && (
							<input
								type="hidden"
								name={ `${ settings.optionKey }[schema_emit_enabled]` }
								value="1"
							/>
						) }
					</PanelRow>
				</PanelBody>
			</Panel>

			<Panel
				header={ __( 'LLM features', 'ai-readiness-kit' ) }
				className="agentready-context-profile-panel"
			>
				<PanelBody opened>
					{ aiNotice }
					<PanelRow>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Enable LLM cleanup pass for messy pages',
								'ai-readiness-kit'
							) }
							help={ __(
								'When a page is built with a page builder (Elementor, Divi, etc.) or scores low on deterministic conversion, run a cleanup pass via WP AI Client. Admin-previewable; never auto-publishes.',
								'ai-readiness-kit'
							) }
							checked={ profile.llm_cleanup_enabled }
							onChange={ ( on ) =>
								updateField( 'llm_cleanup_enabled', on )
							}
						/>
						{ profile.llm_cleanup_enabled && (
							<input
								type="hidden"
								name={ `${ settings.optionKey }[llm_cleanup_enabled]` }
								value="1"
							/>
						) }
					</PanelRow>
					<PanelRow>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Auto-generate /llms.txt entry descriptions',
								'ai-readiness-kit'
							) }
							help={ __(
								'Use WP AI Client to write one-line descriptions for auto-listed entries. Falls back to the post excerpt when the AI Client is unconfigured.',
								'ai-readiness-kit'
							) }
							checked={ profile.llm_descriptions_enabled }
							onChange={ ( on ) =>
								updateField( 'llm_descriptions_enabled', on )
							}
						/>
						{ profile.llm_descriptions_enabled && (
							<input
								type="hidden"
								name={ `${ settings.optionKey }[llm_descriptions_enabled]` }
								value="1"
							/>
						) }
					</PanelRow>
				</PanelBody>
			</Panel>

			<p className="submit">
				<button type="submit" className="button button-primary">
					{ __( 'Save Context Profile', 'ai-readiness-kit' ) }
				</button>
			</p>
		</form>
	);
}

function init() {
	const mount = document.querySelector( MOUNT_SELECTOR );
	if ( ! mount ) {
		return;
	}

	const bootstrap = readBootstrap();
	if ( ! bootstrap ) {
		mount.textContent = __(
			'AI Readiness Kit Context Profile failed to load. Reload the page or contact support.',
			'ai-readiness-kit'
		);
		return;
	}

	const root = createRoot( mount );
	root.render( <ContextProfileApp bootstrap={ bootstrap } /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
