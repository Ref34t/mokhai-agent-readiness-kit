<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader and (for integration tests) the WP test
 * library shipped by wp-phpunit/wp-phpunit. Unit tests don't need WP — they
 * use the Provider injection seam from AgDR-0003 to test the wrapper without
 * loading WordPress core.
 *
 * @package WPContext
 */

declare(strict_types=1);

// ABSPATH stub for unit tests so the `defined('ABSPATH') || exit;` guards in
// every includes/*.php (and the global helpers.php loaded via Composer's
// `files:` autoload) don't terminate the test process. Matches the fix
// landed in #15 for tools/autoload-check.php.
//
// MUST come before require autoload.php — Composer's `files:` autoload runs
// during that require and the guarded files will exit if ABSPATH is unset.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

// Composer autoload — required for every test type.
require __DIR__ . '/../vendor/autoload.php';

// Global-namespace helpers — moved out of Composer's `files:` autoload to
// avoid the `defined('ABSPATH') || exit;` guard tripping during PHPUnit's
// own autoload chain (before our bootstrap can define ABSPATH). Safe to
// require here because ABSPATH is defined just above.
require __DIR__ . '/../includes/Ai/helpers.php';

// Integration tests load the WP test library; unit tests don't.
$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( $tests_dir && file_exists( $tests_dir . '/includes/functions.php' ) ) {
	require_once $tests_dir . '/includes/functions.php';

	// Load the plugin into the WP test instance.
	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			require __DIR__ . '/../wp-context.php';
		}
	);

	require $tests_dir . '/includes/bootstrap.php';
} else {
	// Unit-test path: WP isn't loaded. Stub the handful of WP functions the
	// production code reaches for, so unit tests can exercise the wrapper /
	// retry logic without booting WordPress. Integration tests use the real
	// functions inside wp-env. Each stub guards on function_exists so the
	// bootstrap is idempotent across re-includes.
	require __DIR__ . '/Unit/wp-stubs.php';
}
