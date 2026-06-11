<?php
/**
 * Context Profile settings — storage, sanitisation, and reader.
 *
 * Owns the `agentready_context_profile` option per AgDR-0002.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Storage + sanitisation for the Context Profile.
 *
 * The Context Profile is the v0.1 architectural keystone (PRD FR-1): every
 * agent-facing module (#5 Markdown views, #6 LLM cleanup, #7 /llms.txt, #8 LLM
 * descriptions, #9 cache invalidation, #10 Context Score, #11 LLM narrative)
 * reads from this single source of truth.
 *
 * Storage shape is fixed by AgDR-0002:
 *   - Single `wp_options` entry (autoloaded)
 *   - Versioned via `schema_version` for forward migrations
 *   - Single sanitise callback enforces FR-9 safe-by-default ("fresh install
 *     exposes nothing")
 *   - `Context_Profile_Settings::get_profile()` is the only public reader
 *
 * Site identity (site name / tagline / locale) and schema-coordination posture
 * (active SEO plugin) are NOT stored here — they're read live from WP core
 * options and `Schema_Coordination_Detector` respectively, so the profile
 * cannot go stale relative to WP General Settings or a plugin switch.
 */
final class Context_Profile_Settings {

	/**
	 * Option key under which the Context Profile is persisted.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'agentready_context_profile';

	/**
	 * Settings API option group — passed to register_setting() and the
	 * settings-fields nonce so the admin form posts to options.php cleanly.
	 *
	 * @var string
	 */
	public const OPTION_GROUP = 'agentready_context_profile_group';

	/**
	 * Current schema version. Bump when adding fields with non-trivial
	 * defaults or changing a field's meaning. Additive changes (new optional
	 * field with a safe default) do NOT require a /migration ticket; see
	 * AgDR-0002 § "Schema versioning + migration policy".
	 *
	 * @var int
	 */
	public const CURRENT_SCHEMA_VERSION = 5;

	/**
	 * Post statuses the admin is allowed to expose. `publish` is the only
	 * status enabled by default — exposing `private` / `draft` / `pending`
	 * to agents requires deliberate opt-in.
	 *
	 * @var string[]
	 */
	public const ALLOWED_STATUSES = array( 'publish', 'private', 'password', 'draft', 'pending' );

	/**
	 * Per-post meta key. When set to '1', the post is excluded from every
	 * agent-facing surface regardless of CPT / status. Written by the
	 * block-editor sidebar toggle (Exclude_Sidebar_Assets / #180) or
	 * programmatically. Underscore prefix keeps it out of the custom-fields UI.
	 *
	 * @var string
	 */
	public const EXCLUDE_META_KEY = '_agentready_excluded';

	/**
	 * Slugs WordPress seeds on a fresh install. Dropped from agent output by
	 * default (the `exclude_wp_samples` toggle) so "Hello World" / "Sample
	 * Page" placeholder content never reaches the agent surface. See #180.
	 *
	 * @var string[]
	 */
	public const WP_SAMPLE_SLUGS = array( 'hello-world', 'sample-page' );

	/**
	 * Wire the WordPress hooks owned by this class.
	 *
	 * Called once from Main::register_hooks (added in #4's Main wiring).
	 *
	 * The post-save action (`agentready_context_profile_saved`) is dispatched
	 * via `update_option_<key>` / `add_option_<key>` rather than from inside
	 * `sanitize()`. The Settings API runs `sanitize_callback` BEFORE
	 * `update_option()` writes the new value, so listeners that re-read via
	 * `get_profile()` from inside `sanitize()` would observe the OLD value —
	 * defeating the FR-1 keystone contract for #9 / #10 / #11.
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_init', array( self::class, 'register_setting' ) );
		\add_action( 'update_option_' . self::OPTION_KEY, array( self::class, 'on_profile_updated' ), 10, 2 );
		\add_action( 'add_option_' . self::OPTION_KEY, array( self::class, 'on_profile_added' ), 10, 2 );
	}

	/**
	 * Register the Context Profile option with the Settings API.
	 *
	 * `show_in_rest` is intentionally false in v0.1 — admin-only screen,
	 * Settings API only. The WP Abilities API integration (#21) will register
	 * its own read-ability with its own capability check; it does NOT piggyback
	 * on the Settings API REST exposure.
	 */
	public static function register_setting(): void {
		\register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'description'       => \__( 'AI Readiness Kit Context Profile (single source of truth for agent-facing surfaces).', 'ai-readiness-kit' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Default profile — the safe-by-default state a fresh install reads.
	 *
	 * `exposed_cpts === []` is load-bearing: every consumer treats an empty
	 * list as "expose nothing." Never inject implicit defaults here that
	 * would silently break FR-9.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'schema_version'               => self::CURRENT_SCHEMA_VERSION,
			'exposed_cpts'                 => array(),
			'exposed_statuses'             => array( 'publish' ),
			'llm_descriptions_enabled'     => true,
			// Native JSON-LD emission opt-in (#73 / AgDR-0034). Default
			// false: FR-9 safe-by-default. Operator flips this on to
			// satisfy Context Score's schema_coverage without a third-
			// party SEO plugin. Site-identity nodes always emit when on;
			// per-content nodes additionally gate on exposed_cpts /
			// exposed_statuses.
			'schema_emit_enabled'          => false,
			// Per-module enable flags. Adding modules here is an additive
			// schema change (legacy stored profiles default true via merge()).
			'markdown_views_enabled'       => true,
			// Advertise agent surfaces (#178): per-page `.md` Link header +
			// `<head>` alternate, and the /llms.txt reference in robots.txt.
			// Default true — discovery is the whole point; flip off to keep
			// generating artifacts without announcing them.
			'advertise_alternates_enabled' => true,
			// Content exclusions (#180). `excluded_ids` / `excluded_slugs` are
			// operator-curated deny-lists applied on top of the CPT / status
			// gates; `exclude_wp_samples` drops WordPress's seeded sample
			// content. All three feed get_exposure_reason(), so they apply
			// uniformly to /llms.txt, .md views, and #178 alternate advertising.
			// `exclude_wp_samples` defaults true: sample content is never
			// legitimate agent input.
			'excluded_ids'                 => array(),
			'excluded_slugs'               => array(),
			// Term-based exclusions (#188). A post carrying ANY listed
			// category / tag (by term ID or slug) is denied — drops whole
			// content classes (e.g. an "internal" category) without listing
			// every post. Same deny-list semantics as the two lists above.
			'excluded_term_ids'            => array(),
			'excluded_term_slugs'          => array(),
			'exclude_wp_samples'           => true,
			// Served AI-discovery channels (#172 / AgDR-0056): ai.txt +
			// /.well-known/llms-policy.json + /.well-known/ai-layer, emitted
			// virtually via rewrites. Default true — channel payloads are
			// site metadata + pointers, no content exposure, so FR-9 isn't
			// implicated and discovery is the plugin's point.
			'discovery_channels_enabled'   => true,
			// Access stance declared in llms-policy.json (+ echoed in
			// ai.txt). Declarative only — never enforced. Inference defaults
			// allowed (the reason a site installs an AI-readiness plugin);
			// training defaults DENIED — the operator must opt in to the
			// consequential dimension. See AgDR-0056 § Decision.
			'policy_allow_inference'       => true,
			'policy_allow_training'        => false,
			// Consolidated /llms-full.txt (#179 / AgDR-0057). Default true —
			// it inlines only content the exposure gates already publish via
			// /llms.txt and the per-page surfaces, so FR-9 isn't implicated
			// (fresh install with empty exposed_cpts serves an empty file).
			// No UI checkbox ships (the issue's "No UI changes" note); the
			// key is settable via the option, WP-CLI, or a filter — same
			// shape as advertise_alternates_enabled (#178).
			'llms_full_txt_enabled'        => true,
		);
	}

	/**
	 * Per-cron-tick cap for the LLM descriptions pipeline.
	 *
	 * Descriptions historically borrowed `get_md_cleanup_max_per_run()`; #153
	 * (AgDR-0049) retires the Markdown Views cleanup pass, so the descriptions
	 * pipeline owns its own cap. The constant equals the cleanup cap's default
	 * (10), so per-tick behaviour is unchanged for sites that never customised
	 * the (now-removed) cleanup cap.
	 *
	 * @var int
	 */
	public const DESCRIPTIONS_MAX_PER_RUN = 10;

	/**
	 * Resolve the per-cron-tick cap for the LLM descriptions pipeline.
	 *
	 * @return int
	 */
	public static function get_descriptions_max_per_run(): int {
		return self::DESCRIPTIONS_MAX_PER_RUN;
	}

	/**
	 * Resolve whether a per-module enable flag is on.
	 *
	 * Lookup convention: `{module}_enabled`. Unknown modules default true so
	 * a feature that exists in code but isn't yet represented in the profile
	 * schema is reachable until an admin actively opts out. The default is
	 * compatible with the "soft disable" behaviour AgDR-0015 ships for #5 —
	 * the handler still 404s when the toggle is off, but discovery of the
	 * toggle's presence is decoupled from discovery of the module's code.
	 */
	public static function is_module_enabled( string $module ): bool {
		$profile = self::get_profile();
		$key     = $module . '_enabled';

		if ( ! isset( $profile[ $key ] ) ) {
			return true;
		}

		return (bool) $profile[ $key ];
	}

	/**
	 * Decide whether a post may be exposed on agent-facing surfaces.
	 *
	 * Strict-inherit single-source-of-truth API per AgDR-0012: every consumer
	 * (Markdown Views, /llms.txt, /llms-full.txt, etc.) routes through this
	 * method rather than re-implementing the rule. A false return must yield
	 * a 404 in the public consumer — never a partial content leak.
	 *
	 * For admin-only consumers (REST preview endpoint, Gutenberg sidebar)
	 * that need the *reason* a post is hidden so the editor can fix it, use
	 * {@see self::get_exposure_reason()} which returns one of: 'cpt',
	 * 'status', 'password', 'noindex', 'excluded', 'sample', or null on exposable.
	 */
	public static function is_url_exposable( \WP_Post $post ): bool {
		return null === self::get_exposure_reason( $post );
	}

	/**
	 * Return null if the post is exposable; otherwise a short reason code
	 * naming the gate that denied it.
	 *
	 * Reason codes are stable strings safe to use as REST response values
	 * and as i18n message keys. Order matches the gate order in
	 * `is_url_exposable()`.
	 *
	 * @return string|null One of 'cpt' | 'status' | 'password' | 'noindex' | 'excluded' | 'sample', or null.
	 */
	public static function get_exposure_reason( \WP_Post $post ): ?string {
		$profile = self::get_profile();

		if ( ! \in_array( $post->post_type, $profile['exposed_cpts'], true ) ) {
			return 'cpt';
		}

		if ( ! \in_array( $post->post_status, $profile['exposed_statuses'], true ) ) {
			return 'status';
		}

		if ( '' !== $post->post_password ) {
			return 'password';
		}

		if ( self::is_noindexed( $post ) ) {
			return 'noindex';
		}

		if ( self::is_excluded( $post, $profile ) ) {
			return 'excluded';
		}

		if ( self::is_wp_sample( $post, $profile ) ) {
			return 'sample';
		}

		return null;
	}

	/**
	 * Hook point for SEO plugins / #12 to declare a post as noindex.
	 *
	 * Default false — v0.1 does not detect noindex without a coordinated SEO
	 * plugin layer. The filter is the supported extension surface.
	 */
	private static function is_noindexed( \WP_Post $post ): bool {
		/**
		 * Filter whether a post is considered noindex for agent-readiness purposes.
		 *
		 * #12 (Yoast / Rank Math / AIOSEO coordination) is the first hook
		 * subscriber. Returning true causes `is_url_exposable()` to deny the
		 * post regardless of CPT / status configuration.
		 *
		 * @param bool     $noindexed Default false.
		 * @param \WP_Post $post      Post being evaluated.
		 */
		return (bool) \apply_filters( 'agentready_post_is_noindexed', false, $post );
	}

	/**
	 * Whether the post sits on an operator-curated exclude list — the per-post
	 * `_agentready_excluded` meta toggle, the site-level `excluded_ids` /
	 * `excluded_slugs` deny-lists (#180), or a category / tag on the
	 * `excluded_term_ids` / `excluded_term_slugs` term deny-lists (#188).
	 *
	 * @param \WP_Post             $post    Post being evaluated.
	 * @param array<string, mixed> $profile Already-resolved profile (passed in
	 *                                      to avoid a second get_profile() on
	 *                                      the /llms.txt entry-loop hot path).
	 */
	private static function is_excluded( \WP_Post $post, array $profile ): bool {
		if ( '1' === (string) \get_post_meta( $post->ID, self::EXCLUDE_META_KEY, true ) ) {
			return true;
		}

		if ( \in_array( (int) $post->ID, $profile['excluded_ids'], true ) ) {
			return true;
		}

		$slug = (string) \get_post_field( 'post_name', $post, 'raw' );
		if ( '' !== $slug && \in_array( $slug, $profile['excluded_slugs'], true ) ) {
			return true;
		}

		return self::in_excluded_terms( $post, $profile );
	}

	/**
	 * Whether the post carries any category / tag on the term deny-lists (#188).
	 *
	 * Empty lists short-circuit before any term lookup, so sites that don't
	 * use term exclusion pay nothing on the /llms.txt entry loop. When lists
	 * are set, `has_term()` rides the object term cache (primed by WP_Query
	 * on the entry loop), so the per-post cost is one cached lookup per
	 * taxonomy, not a query.
	 *
	 * @param \WP_Post             $post    Post being evaluated.
	 * @param array<string, mixed> $profile Already-resolved profile.
	 */
	private static function in_excluded_terms( \WP_Post $post, array $profile ): bool {
		$terms = \array_merge( $profile['excluded_term_ids'], $profile['excluded_term_slugs'] );

		if ( array() === $terms ) {
			return false;
		}

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( \has_term( $terms, $taxonomy, $post ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the post is WordPress-seeded sample content ("Hello World" /
	 * "Sample Page") AND the `exclude_wp_samples` toggle is on (default). The
	 * match is by slug so it survives a renamed title. See #180.
	 *
	 * @param \WP_Post             $post    Post being evaluated.
	 * @param array<string, mixed> $profile Already-resolved profile.
	 */
	private static function is_wp_sample( \WP_Post $post, array $profile ): bool {
		if ( empty( $profile['exclude_wp_samples'] ) ) {
			return false;
		}

		$slug = (string) \get_post_field( 'post_name', $post, 'raw' );

		return \in_array( $slug, self::WP_SAMPLE_SLUGS, true );
	}

	/**
	 * Public reader. Returns the migrated + defaulted profile array.
	 *
	 * Downstream modules (#5–#11) MUST call this rather than touching
	 * `get_option(self::OPTION_KEY)` directly so migrations and future
	 * caching / instrumentation have a single chokepoint.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_profile(): array {
		$stored = \get_option( self::OPTION_KEY, array() );

		if ( ! \is_array( $stored ) ) {
			$stored = array();
		}

		return self::migrate( $stored );
	}

	/**
	 * Canonical programmatic setter for the two exposure keys.
	 *
	 * The Settings API form is the admin write path; this is the write path
	 * for non-form callers (the WP Abilities API `profile-set-exposure`
	 * ability, #21 / AgDR-0044). Merges ONLY `exposed_cpts` /
	 * `exposed_statuses` over the current profile — every other key
	 * (module flags, thresholds, schema_version) is preserved — then runs the
	 * merged array through the same internal whitelist the form uses, so an
	 * invalid CPT / status from a programmatic caller can never persist.
	 *
	 * The `update_option()` write fires `update_option_<key>` /
	 * `add_option_<key>`, which dispatch `agentready_context_profile_saved` —
	 * so Context Score recompute and /llms.txt regen cascade exactly as they
	 * do on an admin save.
	 *
	 * Does NOT perform its own capability check: callers are responsible for
	 * authorisation (the ability's `permission_callback` gates `manage_options`
	 * before reaching here). When `admin_init` has registered the Settings API
	 * sanitise callback, that callback's own cap check additionally applies.
	 *
	 * @param array<int|string, mixed> $cpts     Candidate CPT slugs (whitelisted).
	 * @param array<int|string, mixed> $statuses Candidate status slugs (whitelisted).
	 *
	 * @return array<string, mixed> The saved profile (post-whitelist).
	 */
	public static function set_exposure( array $cpts, array $statuses ): array {
		$merged                     = self::get_profile();
		$merged['exposed_cpts']     = $cpts;
		$merged['exposed_statuses'] = $statuses;

		\update_option( self::OPTION_KEY, self::sanitize_internal( $merged ) );

		return self::get_profile();
	}

	/**
	 * REST write path for the full profile (#142 / AgDR-0048).
	 *
	 * The Settings API form posts to options.php; the SPA posts here via
	 * `Context_Profile_Rest_Controller`. Mirrors `set_exposure()`'s
	 * `sanitize_internal()` + `update_option()` shape, but accepts the WHOLE
	 * profile (toggles, schema flag, exposure) rather than only the two
	 * exposure keys. Routing through `sanitize_internal()` means a hostile REST
	 * body can't persist an unknown key or an invalid CPT/status — identical to
	 * the form path.
	 *
	 * Does NOT cap-check: the REST controller's `permission_callback`
	 * (`manage_options`) gates the caller before this runs, matching
	 * `set_exposure()`. The `update_option()` write fires
	 * `update_option_<key>` / `add_option_<key>`, so the
	 * `agentready_context_profile_saved` cascade (Context Score recompute,
	 * /llms.txt regen) runs exactly as on an admin form save.
	 *
	 * @param array<int|string, mixed> $raw Raw profile payload from the SPA.
	 *
	 * @return array<string, mixed> The saved profile (post-whitelist + migrate).
	 */
	public static function save( array $raw ): array {
		\update_option( self::OPTION_KEY, self::sanitize_internal( $raw ) );

		return self::get_profile();
	}

	/**
	 * Migrate a stored profile to the current schema version.
	 *
	 * Pure function: returns a new array, never writes back. The write-back
	 * happens on the next admin save through `sanitize()`. Keeping migration
	 * write-free at read time avoids unexpected DB writes on the public
	 * front-end where the profile is hot-path read.
	 *
	 * @param array<string, mixed> $stored Raw option value (or empty array).
	 *
	 * @return array<string, mixed>
	 */
	public static function migrate( array $stored ): array {
		$defaults = self::get_defaults();

		// Merge with defaults so missing keys (new fields added after the
		// option was first written) get safe values without a destructive
		// migration step.
		$merged = \array_merge( $defaults, $stored );

		// schema_version is always normalised to an int.
		$merged['schema_version'] = isset( $stored['schema_version'] )
			? (int) $stored['schema_version']
			: self::CURRENT_SCHEMA_VERSION;

		// Future destructive migrations (rename / type-change / drop) live
		// here, gated on $merged['schema_version']. v1 has nothing to do.
		$merged['schema_version'] = self::CURRENT_SCHEMA_VERSION;

		// Re-sanitise the merged result so legacy stored values can't bypass
		// the whitelist (e.g. a CPT that was public on save but isn't now).
		return self::sanitize_internal( $merged );
	}

	/**
	 * Settings API sanitise callback.
	 *
	 * Runs on every `update_option(self::OPTION_KEY, ...)`. Wraps the internal
	 * sanitiser with a capability check so a forged nonce / direct call from
	 * a non-admin user cannot persist the option.
	 *
	 * NOTE: this method does NOT dispatch `agentready_context_profile_saved`.
	 * The Settings API runs `sanitize_callback` BEFORE the write, so dispatching
	 * here would expose listeners to the stale value via `get_profile()`. The
	 * action fires from `on_profile_updated()` / `on_profile_added()`, which
	 * run after WP's write completes — see {@see self::register_hooks()}.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die(
				\esc_html__( 'You do not have permission to save the Context Profile.', 'ai-readiness-kit' ),
				\esc_html__( 'Forbidden', 'ai-readiness-kit' ),
				array( 'response' => 403 )
			);
		}

		if ( ! \is_array( $input ) ) {
			$input = array();
		}

		return self::sanitize_internal( $input );
	}

	/**
	 * Fires the post-save action after WP has written an updated option.
	 *
	 * Hooked to `update_option_agentready_context_profile`. Re-runs the
	 * stored value through `migrate()` so the action payload matches what
	 * downstream readers (via `get_profile()`) will observe — listeners
	 * never see a half-migrated array.
	 *
	 * @param mixed $old_value Previous option value (raw, pre-migration).
	 * @param mixed $value     New option value (raw, just-written, pre-migration).
	 */
	public static function on_profile_updated( $old_value, $value ): void {
		$new = \is_array( $value ) ? self::migrate( $value ) : self::get_defaults();
		$old = \is_array( $old_value ) ? self::migrate( $old_value ) : self::get_defaults();

		/**
		 * Fires after the Context Profile has been written to the database.
		 *
		 * Listeners (#9 cache invalidation, #10 Context Score recompute,
		 * #11 LLM narrative regen) can safely call `Context_Profile_Settings::get_profile()`
		 * from here and observe the new value. The action runs even when the
		 * new value equals the old value — listeners that want to skip equal
		 * saves should diff themselves.
		 *
		 * @param array<string, mixed> $new The newly-saved profile.
		 * @param array<string, mixed> $old The previous profile (defaulted on first save).
		 */
		\do_action( 'agentready_context_profile_saved', $new, $old );
	}

	/**
	 * Fires the post-save action on the first write of the option.
	 *
	 * Hooked to `add_option_agentready_context_profile`. WP fires
	 * `add_option_<key>` (not `update_option_<key>`) the very first time the
	 * option is written, so without this hook the inaugural save would skip
	 * the listener chain. Payload uses `get_defaults()` for the old value —
	 * "old" is "what the system was implicitly showing pre-save."
	 *
	 * @param string $option Option name (`agentready_context_profile`).
	 * @param mixed  $value  New option value (raw, just-written).
	 */
	public static function on_profile_added( $option, $value ): void {
		unset( $option );

		$new = \is_array( $value ) ? self::migrate( $value ) : self::get_defaults();
		$old = self::get_defaults();

		/** This filter is documented in Context_Profile_Settings::on_profile_updated() */
		\do_action( 'agentready_context_profile_saved', $new, $old );
	}

	/**
	 * Pure-functional sanitiser. Drops unknown keys, type-coerces, whitelists.
	 *
	 * Separated from the public `sanitize()` so `migrate()` can re-run the
	 * whitelist without re-triggering the capability check / save action.
	 *
	 * @param array<string|int, mixed> $input Raw input.
	 *
	 * @return array<string, mixed>
	 */
	private static function sanitize_internal( array $input ): array {
		$defaults = self::get_defaults();
		$out      = array();

		$out['schema_version'] = isset( $input['schema_version'] )
			? (int) $input['schema_version']
			: $defaults['schema_version'];

		// Clamp schema_version to the known range — defence against a forged
		// future version on input (would otherwise bypass migrate()).
		if ( $out['schema_version'] < 1 || $out['schema_version'] > self::CURRENT_SCHEMA_VERSION ) {
			$out['schema_version'] = self::CURRENT_SCHEMA_VERSION;
		}

		$out['exposed_cpts'] = self::sanitize_cpts(
			isset( $input['exposed_cpts'] ) && \is_array( $input['exposed_cpts'] )
				? $input['exposed_cpts']
				: array()
		);

		$out['exposed_statuses'] = self::sanitize_statuses(
			isset( $input['exposed_statuses'] ) && \is_array( $input['exposed_statuses'] )
				? $input['exposed_statuses']
				: array()
		);

		$out['llm_descriptions_enabled'] = ! empty( $input['llm_descriptions_enabled'] );

		// Native JSON-LD emission opt-in (#73 / AgDR-0034). Inverted form of
		// the markdown_views_enabled pattern below: default FALSE, set only
		// when the input explicitly says so. Legacy profiles that pre-date
		// the field merge() to false via get_defaults() and stay safe-by-
		// default. Operators opt in from the Profile UI.
		$out['schema_emit_enabled'] = ! empty( $input['schema_emit_enabled'] );

		// Module enable flags follow a "default true, explicit false to disable"
		// convention. If the key isn't present in input (e.g. a save coming
		// from the legacy form that pre-dates this field, or from migrate()
		// before a UI checkbox exists), we keep the default-true state. When
		// the Phase-8 UI ships a real checkbox + hidden form marker, that
		// path will set the key explicitly and this branch will respect the
		// admin's choice.
		$out['markdown_views_enabled'] = ! \array_key_exists( 'markdown_views_enabled', $input )
			? true
			: ! empty( $input['markdown_views_enabled'] );

		// Agent-surface advertising (#178) — same "default true, explicit false
		// to disable" convention. No UI checkbox ships (the issue's "No UI
		// changes" note), so the array_key_exists guard keeps legacy and
		// form-less saves at the default-true state; the key is still settable
		// via the option directly, WP-CLI, or a filter.
		$out['advertise_alternates_enabled'] = ! \array_key_exists( 'advertise_alternates_enabled', $input )
			? true
			: ! empty( $input['advertise_alternates_enabled'] );

		// Content exclusions (#180). `excluded_ids` → unique positive ints;
		// `excluded_slugs` → unique sanitised post-name slugs. Both default to
		// empty (no exclusions). `exclude_wp_samples` follows the "default true,
		// explicit false to disable" convention so legacy profiles and
		// form-less saves keep WP sample content excluded.
		$out['excluded_ids'] = self::sanitize_id_list(
			isset( $input['excluded_ids'] ) && \is_array( $input['excluded_ids'] )
				? $input['excluded_ids']
				: array()
		);

		$out['excluded_slugs'] = self::sanitize_slug_list(
			isset( $input['excluded_slugs'] ) && \is_array( $input['excluded_slugs'] )
				? $input['excluded_slugs']
				: array()
		);

		// Term deny-lists (#188) — same shapes as the post lists above:
		// unique positive ints (term IDs) + unique sanitised term slugs.
		$out['excluded_term_ids'] = self::sanitize_id_list(
			isset( $input['excluded_term_ids'] ) && \is_array( $input['excluded_term_ids'] )
				? $input['excluded_term_ids']
				: array()
		);

		$out['excluded_term_slugs'] = self::sanitize_slug_list(
			isset( $input['excluded_term_slugs'] ) && \is_array( $input['excluded_term_slugs'] )
				? $input['excluded_term_slugs']
				: array()
		);

		$out['exclude_wp_samples'] = ! \array_key_exists( 'exclude_wp_samples', $input )
			? true
			: ! empty( $input['exclude_wp_samples'] );

		// Served discovery channels (#172 / AgDR-0056). The module toggle and
		// allow_inference follow the "default true, explicit false to disable"
		// convention; allow_training follows the inverted schema_emit_enabled
		// form — default FALSE, set only when the input explicitly opts in,
		// so legacy profiles and form-less saves never silently declare
		// training as allowed.
		$out['discovery_channels_enabled'] = ! \array_key_exists( 'discovery_channels_enabled', $input )
			? true
			: ! empty( $input['discovery_channels_enabled'] );

		$out['policy_allow_inference'] = ! \array_key_exists( 'policy_allow_inference', $input )
			? true
			: ! empty( $input['policy_allow_inference'] );

		$out['policy_allow_training'] = ! empty( $input['policy_allow_training'] );

		// Consolidated /llms-full.txt (#179) — "default true, explicit false
		// to disable" convention, matching advertise_alternates_enabled.
		$out['llms_full_txt_enabled'] = ! \array_key_exists( 'llms_full_txt_enabled', $input )
			? true
			: ! empty( $input['llms_full_txt_enabled'] );

		// Unknown keys are dropped by virtue of not being copied into $out.

		return $out;
	}

	/**
	 * Sanitise the exposed-CPTs list.
	 *
	 * Filters against the set of registered public post types so a stale or
	 * malicious slug can't survive a save. Empty list is preserved — that's
	 * the safe-by-default state per FR-9.
	 *
	 * @param array<int|string, mixed> $cpts Candidate CPT slugs.
	 *
	 * @return string[]
	 */
	private static function sanitize_cpts( array $cpts ): array {
		$public_types = \function_exists( 'get_post_types' )
			? \get_post_types( array( 'public' => true ), 'names' )
			: array( 'post', 'page' );

		$valid = array();
		foreach ( $cpts as $cpt ) {
			if ( ! \is_string( $cpt ) ) {
				continue;
			}

			$slug = \sanitize_key( $cpt );
			if ( '' === $slug ) {
				continue;
			}

			if ( ! \in_array( $slug, $public_types, true ) ) {
				// Drop unknown / non-public CPTs. Keeps the option clean if a
				// CPT-registering plugin is deactivated after a save.
				continue;
			}

			$valid[] = $slug;
		}

		return \array_values( \array_unique( $valid ) );
	}

	/**
	 * Sanitise the exposed-statuses list.
	 *
	 * Whitelists against {@see self::ALLOWED_STATUSES}. If the input is empty
	 * (no checkboxes ticked), falls back to `['publish']` — an "expose
	 * nothing" status list isn't a meaningful state; the way to expose
	 * nothing is to leave `exposed_cpts` empty.
	 *
	 * @param array<int|string, mixed> $statuses Candidate status slugs.
	 *
	 * @return string[]
	 */
	private static function sanitize_statuses( array $statuses ): array {
		$valid = array();
		foreach ( $statuses as $status ) {
			if ( ! \is_string( $status ) ) {
				continue;
			}

			$slug = \sanitize_key( $status );
			if ( \in_array( $slug, self::ALLOWED_STATUSES, true ) ) {
				$valid[] = $slug;
			}
		}

		$valid = \array_values( \array_unique( $valid ) );

		if ( array() === $valid ) {
			$valid = array( 'publish' );
		}

		return $valid;
	}

	/**
	 * Sanitise the excluded-IDs deny-list to a unique list of positive ints.
	 * Accepts ints or numeric strings from the SPA / REST body. See #180.
	 *
	 * @param array<int|string, mixed> $ids Candidate post IDs.
	 *
	 * @return int[]
	 */
	private static function sanitize_id_list( array $ids ): array {
		$valid = array();
		foreach ( $ids as $id ) {
			$int = \absint( $id );
			if ( $int > 0 ) {
				$valid[] = $int;
			}
		}

		return \array_values( \array_unique( $valid ) );
	}

	/**
	 * Sanitise the excluded-slugs deny-list to a unique list of post-name
	 * slugs. `sanitize_title()` makes the field forgiving — an operator can
	 * paste a title ("Sample Page") and it normalises to the slug. See #180.
	 *
	 * @param array<int|string, mixed> $slugs Candidate slugs.
	 *
	 * @return string[]
	 */
	private static function sanitize_slug_list( array $slugs ): array {
		$valid = array();
		foreach ( $slugs as $slug ) {
			if ( ! \is_string( $slug ) ) {
				continue;
			}

			$clean = \sanitize_title( $slug );
			if ( '' !== $clean ) {
				$valid[] = $clean;
			}
		}

		return \array_values( \array_unique( $valid ) );
	}
}
