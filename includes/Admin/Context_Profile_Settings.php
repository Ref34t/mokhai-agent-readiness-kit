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
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_init', array( self::class, 'register_setting' ) );
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
				'description'       => \__( 'AgentReady Context Profile (single source of truth for agent-facing surfaces).', 'agentready' ),
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
			'schema_version'           => self::CURRENT_SCHEMA_VERSION,
			'exposed_cpts'             => array(),
			'exposed_statuses'         => array( 'publish' ),
			'llm_cleanup_enabled'      => true,
			'llm_descriptions_enabled' => true,
		);
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
	 * @param mixed $input Raw form input.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die(
				\esc_html__( 'You do not have permission to save the Context Profile.', 'agentready' ),
				\esc_html__( 'Forbidden', 'agentready' ),
				array( 'response' => 403 )
			);
		}

		if ( ! \is_array( $input ) ) {
			$input = array();
		}

		$sanitized = self::sanitize_internal( $input );

		// Dispatch the post-save action so #9 / #10 / #11 listeners (cache
		// invalidation, Context Score recompute, LLM narrative regen) can
		// react. Uses the previously-stored profile as the "old" value so
		// listeners can diff (e.g. "did the exposed_cpts list change?").
		$old = self::get_profile();

		/**
		 * Fires after the Context Profile passes sanitisation but before
		 * `update_option()` writes it. Listeners use this to invalidate
		 * caches keyed on the prior exposure rules (#9), trigger a Context
		 * Score recompute (#10), and regenerate the LLM score narrative
		 * (#11). The action runs even when the new value equals the old
		 * value — listeners that want to skip equal saves should diff
		 * themselves.
		 *
		 * @param array<string, mixed> $sanitized The new profile about to be saved.
		 * @param array<string, mixed> $old       The previous profile (defaulted).
		 */
		\do_action( 'agentready_context_profile_saved', $sanitized, $old );

		return $sanitized;
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
