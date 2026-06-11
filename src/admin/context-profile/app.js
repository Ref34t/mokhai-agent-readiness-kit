/**
 * AI Readiness Kit — Context Profile view (FR-1 keystone / #142).
 *
 * Rendered as the "Profile" tab of the Context app shell (#142 / AgDR-0048).
 * Saves the whole profile via the `ai-readiness-kit/v1/context-profile` REST
 * route through `apiFetch` — no page reload — instead of the legacy
 * options.php form POST. The server-side `Context_Profile_Settings::save()`
 * (whitelist via `sanitize_internal()`) remains the source of truth.
 *
 * Safe-by-default rule (FR-9): a fresh install ships with `exposed_cpts: []`
 * — the agency lead must explicitly opt in CPTs and statuses before any
 * agent-facing surface emits content.
 */

import { useState, useMemo } from '@wordpress/element';
import {
	Panel,
	PanelBody,
	PanelRow,
	ToggleControl,
	CheckboxControl,
	TextareaControl,
	Notice,
	Button,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import '../shared/admin-ui.css';

/**
 * Toggle a slug in a string-array, keeping the array de-duplicated.
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

export function ContextProfileApp( { bootstrap } ) {
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
	const [ saving, setSaving ] = useState( false );
	const [ flash, setFlash ] = useState( null );

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

	// The exclude list is two server-side arrays (numeric IDs + slugs) but a
	// single textarea here: one entry per line, numeric lines become IDs and
	// everything else a slug. The server re-sanitises both arrays on save.
	const excludeText = useMemo( () => {
		const ids = ( profile.excluded_ids || [] ).map( String );
		const slugs = profile.excluded_slugs || [];
		return [ ...ids, ...slugs ].join( '\n' );
	}, [ profile.excluded_ids, profile.excluded_slugs ] );

	const onExcludeListChange = ( text ) => {
		const ids = [];
		const slugs = [];
		text.split( '\n' )
			.map( ( line ) => line.trim() )
			.filter( Boolean )
			.forEach( ( line ) => {
				if ( /^\d+$/.test( line ) ) {
					ids.push( parseInt( line, 10 ) );
				} else {
					slugs.push( line );
				}
			} );
		setProfile( ( prev ) => ( {
			...prev,
			excluded_ids: ids,
			excluded_slugs: slugs,
		} ) );
	};

	// Term deny-lists (#188) — same single-textarea shape as the exclude
	// list: numeric lines become term IDs, everything else a term slug.
	const excludeTermsText = useMemo( () => {
		const ids = ( profile.excluded_term_ids || [] ).map( String );
		const slugs = profile.excluded_term_slugs || [];
		return [ ...ids, ...slugs ].join( '\n' );
	}, [ profile.excluded_term_ids, profile.excluded_term_slugs ] );

	const onExcludeTermsChange = ( text ) => {
		const ids = [];
		const slugs = [];
		text.split( '\n' )
			.map( ( line ) => line.trim() )
			.filter( Boolean )
			.forEach( ( line ) => {
				if ( /^\d+$/.test( line ) ) {
					ids.push( parseInt( line, 10 ) );
				} else {
					slugs.push( line );
				}
			} );
		setProfile( ( prev ) => ( {
			...prev,
			excluded_term_ids: ids,
			excluded_term_slugs: slugs,
		} ) );
	};

	const save = async () => {
		setSaving( true );
		setFlash( null );
		try {
			const response = await apiFetch( {
				path: `/${ settings.restNamespace }${ settings.restBase }`,
				method: 'PUT',
				data: profile,
				headers: { 'X-WP-Nonce': settings.restNonce },
			} );
			// Adopt the persisted (whitelisted + migrated) profile so the UI
			// reflects exactly what the server kept.
			if ( response && response.profile ) {
				setProfile( response.profile );
			}
			setFlash( {
				type: 'success',
				message: __( 'Context Profile saved.', 'ai-readiness-kit' ),
			} );
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message:
					err.message || __( 'Save failed.', 'ai-readiness-kit' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div
			aria-label={ __(
				'AI Readiness Kit Context Profile',
				'ai-readiness-kit'
			) }
		>
			{ flash && (
				<Notice
					status={ flash.type }
					onRemove={ () => setFlash( null ) }
				>
					{ flash.message }
				</Notice>
			) }

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
							<CheckboxControl
								key={ cpt.slug }
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
							<CheckboxControl
								key={ status.slug }
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
						) ) }
					</fieldset>
				</PanelBody>
			</Panel>

			<Panel
				header={ __( 'Content exclusions', 'ai-readiness-kit' ) }
				className="agentready-context-profile-panel"
			>
				<PanelBody opened>
					<PanelRow>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Exclude WordPress sample content',
								'ai-readiness-kit'
							) }
							help={ __(
								'Drops the default "Hello World" post and "Sample Page" from agent output so seeded placeholder content never reaches /llms.txt or .md views. On by default.',
								'ai-readiness-kit'
							) }
							checked={ !! profile.exclude_wp_samples }
							onChange={ ( on ) =>
								updateField( 'exclude_wp_samples', on )
							}
						/>
					</PanelRow>
					<PanelRow>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Exclude list', 'ai-readiness-kit' ) }
							help={ __(
								'One entry per line. A number is treated as a post ID; anything else as a slug (e.g. "sample-page"). Excluded content is removed from /llms.txt, .md views, and alternate-link advertising. You can also exclude a single post from its editor sidebar.',
								'ai-readiness-kit'
							) }
							value={ excludeText }
							onChange={ onExcludeListChange }
							rows={ 4 }
						/>
					</PanelRow>
					<PanelRow>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'Exclude by category / tag',
								'ai-readiness-kit'
							) }
							help={ __(
								'One category or tag per line. A number is treated as a term ID; anything else as a term slug (e.g. "internal"). Posts carrying any listed term are removed from agent output.',
								'ai-readiness-kit'
							) }
							value={ excludeTermsText }
							onChange={ onExcludeTermsChange }
							rows={ 3 }
						/>
					</PanelRow>
					<PanelRow>
						<p className="description">
							{ __(
								'Posts marked noindex by a supported SEO plugin (Yoast, Rank Math) are excluded automatically.',
								'ai-readiness-kit'
							) }
						</p>
					</PanelRow>
				</PanelBody>
			</Panel>

			<Panel
				header={ __( 'Discovery channels', 'ai-readiness-kit' ) }
				className="agentready-context-profile-panel"
			>
				<PanelBody opened>
					<PanelRow>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Serve AI discovery channels',
								'ai-readiness-kit'
							) }
							help={ __(
								'Serves ai.txt, /.well-known/llms-policy.json, and /.well-known/ai-layer dynamically — no files are written, and a file you place at any of these paths always wins. Turning this off removes the routes (404). On by default.',
								'ai-readiness-kit'
							) }
							checked={ !! profile.discovery_channels_enabled }
							onChange={ ( on ) =>
								updateField( 'discovery_channels_enabled', on )
							}
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Declare inference allowed',
								'ai-readiness-kit'
							) }
							help={ __(
								'Published in llms-policy.json and echoed in ai.txt: AI systems may read your content to answer questions. Declarative only — nothing is enforced.',
								'ai-readiness-kit'
							) }
							checked={ !! profile.policy_allow_inference }
							onChange={ ( on ) =>
								updateField( 'policy_allow_inference', on )
							}
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Declare training allowed',
								'ai-readiness-kit'
							) }
							help={ __(
								'Published in llms-policy.json and echoed in ai.txt: AI systems may use your content to train models. Off by default — opt in deliberately. Declarative only — nothing is enforced.',
								'ai-readiness-kit'
							) }
							checked={ !! profile.policy_allow_training }
							onChange={ ( on ) =>
								updateField( 'policy_allow_training', on )
							}
						/>
					</PanelRow>
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
					</PanelRow>
				</PanelBody>
			</Panel>

			<div className="agentready-button-row">
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ saving }
				>
					{ saving
						? __( 'Saving…', 'ai-readiness-kit' )
						: __( 'Save Context Profile', 'ai-readiness-kit' ) }
				</Button>
				{ saving && <Spinner /> }
			</div>
		</div>
	);
}
