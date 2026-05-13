<?php
/**
 * PHPUnit bootstrap.
 *
 * Two execution paths:
 *
 * 1. **Integration** — WP_TESTS_DIR is set (inside wp-env's tests-cli).
 *    wp-phpunit owns ABSPATH + the WP test bootstrap; we just hook
 *    `muplugins_loaded` to require wp-context.php so the plugin runs inside
 *    the WP test instance.
 *
 * 2. **Unit** — WP_TESTS_DIR is unset (running outside wp-env). Define a
 *    stub ABSPATH, manually require the global helpers, load WP function
 *    stubs from tests/Unit/wp-stubs.php. No WordPress is booted.
 *
 * The conditional ABSPATH define is load-bearing — in the integration path,
 * wp-phpunit's bootstrap defines ABSPATH to the WP root (/var/www/html/).
 * If we eagerly defined it here, wp-phpunit would see ABSPATH=plugin-dir
 * and the subsequent `require ABSPATH . 'wp-settings.php'` would fail.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

// Composer autoload — required for every test type. Lazy autoloader; doesn't
// touch ABSPATH-guarded files until a class is actually used.
require __DIR__ . '/../vendor/autoload.php';

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( $tests_dir && file_exists( $tests_dir . '/includes/functions.php' ) ) {
	// Integration path — wp-phpunit owns ABSPATH.
	require_once $tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			require __DIR__ . '/../wp-context.php';
		}
	);

	require $tests_dir . '/includes/bootstrap.php';
} else {
	// Unit path — define ABSPATH stub before any guarded file is required.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../' );
	}

	// helpers.php and includes/*.php all have `defined('ABSPATH') || exit;`
	// guards. ABSPATH is defined above, so they load cleanly here.
	require __DIR__ . '/../includes/Ai/helpers.php';
	require __DIR__ . '/Unit/wp-stubs.php';
}
