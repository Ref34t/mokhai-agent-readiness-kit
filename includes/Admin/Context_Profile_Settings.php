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
	public const CURRENT_SCHEMA_VERSION = 1;

	/**
	 * Post statuses the admin is allowed to expose. `publish` is the only
	 * status enabled by default — exposing `private` / `draft` / `pending`
	 * to agents requires deliberate opt-in.
	 *
	 * @var string[]
	 */
	public const ALLOWED_STATUSES = array( 'publish', 'private', 'password', 'draft', 'pending' );

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
			'schema_version'                     => self::CURRENT_SCHEMA_VERSION,
			'exposed_cpts'                       => array(),
			'exposed_statuses'                   => array( 'publish' ),
			'llm_cleanup_enabled'                => true,
			'llm_descriptions_enabled'           => true,
			// Native JSON-LD emission opt-in (#73 / AgDR-0034). Default
			// false: FR-9 safe-by-default. Operator flips this on to
			// satisfy Context Score's schema_coverage without a third-
			// party SEO plugin. Site-identity nodes always emit when on;
			// per-content nodes additionally gate on exposed_cpts /
			// exposed_statuses.
			'schema_emit_enabled'                => false,
			// Per-module enable flags. Adding modules here is an additive
			// schema change (legacy stored profiles default true via merge()).
			'markdown_views_enabled'             => true,
			// Markdown Views LLM cleanup configuration (AgDR-0017, #6).
			// `markdown_views_cleanup_threshold` is the quality-score cutoff
			// below which the cleanup pass auto-triggers. Tunable 0–100.
			// `markdown_views_cleanup_max_per_run` caps cron-batch size so
			// a flood of low-score posts can't burn the LLM budget in one
			// tick.
			'markdown_views_cleanup_threshold'   => 70,
			'markdown_views_cleanup_max_per_run' => 10,
		);
	}

	/**
	 * Resolve the cleanup threshold for Markdown Views.
	 *
	 * Clamps to [0, 100]; out-of-range stored values fall back to the
	 * default (70). Returned by `Cleanup_Orchestrator::should_clean()`
	 * when comparing the walker's quality score.
	 */
	public static function get_md_cleanup_threshold(): int {
		$profile = self::get_profile();
		$raw     = $profile['markdown_views_cleanup_threshold'] ?? 70;
		$value   = \is_numeric( $raw ) ? (int) $raw : 70;

		if ( $value < 0 || $value > 100 ) {
			return 70;
		}

		return $value;
	}

	/**
	 * Resolve the per-cron-tick cleanup cap. Defence against an
	 * unbounded LLM-cost spike if a site has many low-score posts.
	 * Clamps to [1, 100]; out-of-range values fall back to the default.
	 */
	public static function get_md_cleanup_max_per_run(): int {
		$profile = self::get_profile();
		$raw     = $profile['markdown_views_cleanup_max_per_run'] ?? 10;
		$value   = \is_numeric( $raw ) ? (int) $raw : 10;

		if ( $value < 1 || $value > 100 ) {
			return 10;
		}

		return $value;
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
	 * 'status', 'password', 'noindex', or null on exposable.
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
	 * @return string|null One of 'cpt' | 'status' | 'password' | 'noindex', or null.
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

		$out['llm_cleanup_enabled']      = ! empty( $input['llm_cleanup_enabled'] );
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

		// Cleanup config: clamp to safe ranges, fall back to default on any
		// non-numeric input. Defence against forged or hand-edited options
		// without erroring the admin form.
		$threshold_raw = $input['markdown_views_cleanup_threshold'] ?? $defaults['markdown_views_cleanup_threshold'];
		$threshold     = \is_numeric( $threshold_raw ) ? (int) $threshold_raw : $defaults['markdown_views_cleanup_threshold'];
		if ( $threshold < 0 || $threshold > 100 ) {
			$threshold = $defaults['markdown_views_cleanup_threshold'];
		}
		$out['markdown_views_cleanup_threshold'] = $threshold;

		$max_raw = $input['markdown_views_cleanup_max_per_run'] ?? $defaults['markdown_views_cleanup_max_per_run'];
		$max     = \is_numeric( $max_raw ) ? (int) $max_raw : $defaults['markdown_views_cleanup_max_per_run'];
		if ( $max < 1 || $max > 100 ) {
			$max = $defaults['markdown_views_cleanup_max_per_run'];
		}
		$out['markdown_views_cleanup_max_per_run'] = $max;

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
}
