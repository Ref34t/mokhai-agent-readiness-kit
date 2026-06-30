<?php
/**
 * Shared permission gate for the Abilities API surface (#21 / AgDR-0044).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Abilities;

\defined( 'ABSPATH' ) || exit;

/**
 * Capability checks shared by every registered ability.
 *
 * `manage_options` is the coarse-grained "site administrator" capability
 * already required to reach the admin screens these abilities wrap.
 *
 * Ability permission callbacks return a **bool**, not a `WP_Error`: core's
 * `WP_Ability::execute()` flags a `WP_Error` return as incorrect usage (it
 * deliberately masks the reason so a caller without permission can't probe
 * it) and itself returns a generic `ability_invalid_permissions` error on a
 * non-`true` result. Returning bool keeps us on the documented contract.
 */
final class Permissions {

	/**
	 * Permission callback usable directly as an ability `permission_callback`.
	 *
	 * The Abilities API passes the (validated) input to permission callbacks;
	 * we ignore it — authorisation here is user-capability based, not
	 * input-dependent.
	 *
	 * @param mixed $input Validated ability input (unused).
	 *
	 * @return bool True when the current user may invoke the ability.
	 */
	public static function require_manage_options( $input = null ): bool {
		unset( $input );

		return \current_user_can( 'manage_options' );
	}
}
