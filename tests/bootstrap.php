<?php
/**
 * PHPUnit bootstrap.
 *
 * Two execution paths:
 *
 * 1. **Integration** — WP_TESTS_DIR is set (inside wp-env's tests-cli).
 *    wp-phpunit owns ABSPATH + the WP test bootstrap; we just hook
 *    `muplugins_loaded` to require mokhai-agent-readiness-kit.php so the plugin runs inside
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
 * @package Mokhai\Tests
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
			require __DIR__ . '/../mokhai-agent-readiness-kit.php';
		}
	);

	require $tests_dir . '/includes/bootstrap.php';

	/*
	 * Drop the agentready cache table created at wp-env env-boot.
	 *
	 * wp-env activates this plugin on the tests site at env-boot (the
	 * `lifecycleScripts.afterStart` activation in .wp-env.json, #195 —
	 * previously the auto-activated `plugins: ["."]` entry). That activation runs
	 * `Main::on_activate()` → `Schema::create_for_all_sites()` → emits
	 * `CREATE TABLE wp_mokhai_md_cache (...)` against the test database
	 * WITHOUT the per-test query filter active. The result is a REGULAR
	 * (non-temporary) table that persists across test classes.
	 *
	 * `WP_UnitTestCase_Base::set_up()` then adds two query filters via
	 * `add_filter('query', '_create_temporary_tables')` /
	 * `_drop_temporary_tables`. These rewrite every CREATE TABLE /
	 * DROP TABLE to CREATE TEMPORARY TABLE / DROP TEMPORARY TABLE so each
	 * test runs against its own session-scoped, transaction-friendly view.
	 *
	 * The cross-cut: tests asserting "the cache table is absent before
	 * Schema::create()" fail because the pre-existing regular table is
	 * always there, and the per-test DROP TABLE gets rewritten to
	 * DROP TEMPORARY TABLE — which `IF EXISTS`-no-ops because the regular
	 * table isn't a temporary table.
	 *
	 * Dropping the regular table HERE (after the wp-phpunit bootstrap but
	 * before any test class's `set_up()` runs and adds the filters) clears
	 * the env-boot residue exactly once. From that point on, every
	 * Schema::create() inside a test runs as a temporary table and every
	 * Schema::drop() drops it cleanly.
	 *
	 * See #39 for the full diagnosis chain.
	 */
	if ( isset( $GLOBALS['wpdb'] ) ) {
		$wpdb_pre_test = $GLOBALS['wpdb'];
		$wpdb_pre_test->query( 'DROP TABLE IF EXISTS ' . $wpdb_pre_test->prefix . 'mokhai_md_cache' );
		\delete_option( 'mokhai_md_cache_schema_version' );
		unset( $wpdb_pre_test );
	}
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
