<?php
/**
 * Environment-requirements gate.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Checks the host environment against the plugin's hard floor.
 *
 * Scaffold-level stub. The full pre-activation gate (refuse activation on
 * WP < 7.0, graceful-degrade when WP AI Client is unconfigured, retry-with-
 * backoff on provider failures) is implemented in #2. This class exists so
 * #2 has a stable type to extend and so Main can reference it from day one.
 */
final class Requirements {

	/**
	 * Whether the WP AI Client (shipped with WP core 7.0) is available
	 * and configured at runtime.
	 *
	 * Real implementation lives in #2. Returning false here means every
	 * LLM-touching module degrades to its deterministic fallback path — the
	 * safe default until #2 lands.
	 */
	public static function has_ai_client(): bool {
		return false;
	}

	/**
	 * Whether the running WordPress version meets the hard floor.
	 *
	 * Real implementation lives in #2.
	 */
	public static function meets_wp_floor(): bool {
		return \version_compare( \get_bloginfo( 'version' ), \WPCTX_REQUIRES_WP, '>=' );
	}

	/**
	 * Whether the running PHP version meets the hard floor.
	 */
	public static function meets_php_floor(): bool {
		return \version_compare( \PHP_VERSION, \WPCTX_REQUIRES_PHP, '>=' );
	}
}
