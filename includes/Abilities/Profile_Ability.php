<?php
/**
 * `ai-readiness-kit/profile.read` + `ai-readiness-kit/profile.set_exposure`
 * abilities (#21 / AgDR-0044).
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Abilities;

use WPContext\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * Read and mutate the Context Profile's exposure configuration.
 *
 * `read()` returns the full migrated + defaulted profile (the FR-1 keystone).
 * `set_exposure()` writes ONLY the two exposure keys (`exposed_cpts`,
 * `exposed_statuses`) through the canonical setter, which re-applies the
 * whitelist and fires the `agentready_context_profile_saved` cascade.
 */
final class Profile_Ability {

	/**
	 * Stable ability IDs.
	 *
	 * @var string
	 */
	public const READ_ID         = 'ai-readiness-kit/profile-read';
	public const SET_EXPOSURE_ID = 'ai-readiness-kit/profile-set-exposure';

	/**
	 * Execute callback for `profile.read`. Readonly.
	 *
	 * @param mixed $input Validated ability input (unused).
	 *
	 * @return array<string, mixed> The current Context Profile.
	 */
	public static function read( $input = null ): array {
		unset( $input );

		return Context_Profile_Settings::get_profile();
	}

	/**
	 * Execute callback for `profile.set_exposure`.
	 *
	 * Accepts `exposed_cpts` and/or `exposed_statuses`. Any key absent from
	 * the input is preserved from the current profile (partial update). The
	 * write goes through `Context_Profile_Settings::set_exposure()`, which
	 * whitelists CPTs against public post types and statuses against
	 * ALLOWED_STATUSES — so an invalid value from an agent can never persist.
	 *
	 * @param array<string, mixed> $input Validated input (exposed_cpts / exposed_statuses).
	 *
	 * @return array<string, mixed> The saved profile (post-whitelist).
	 */
	public static function set_exposure( $input ): array {
		$input   = \is_array( $input ) ? $input : array();
		$current = Context_Profile_Settings::get_profile();

		$cpts = \array_key_exists( 'exposed_cpts', $input ) && \is_array( $input['exposed_cpts'] )
			? $input['exposed_cpts']
			: $current['exposed_cpts'];

		$statuses = \array_key_exists( 'exposed_statuses', $input ) && \is_array( $input['exposed_statuses'] )
			? $input['exposed_statuses']
			: $current['exposed_statuses'];

		return Context_Profile_Settings::set_exposure( $cpts, $statuses );
	}
}
