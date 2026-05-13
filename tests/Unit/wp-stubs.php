<?php
/**
 * WordPress function stubs for unit tests.
 *
 * Loaded only when WP_TESTS_DIR is unset (i.e. running unit tests outside
 * wp-env). Integration tests use the real WP functions and never load this
 * file.
 *
 * Each stub is guarded by function_exists so this file is safe to re-require.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

if ( ! isset( $GLOBALS['wpctx_test_cron_queue'] ) ) {
	$GLOBALS['wpctx_test_cron_queue'] = array();
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Stub: report nothing scheduled. Tests that need the "already scheduled"
	 * branch can prepopulate $GLOBALS['wpctx_test_cron_queue'].
	 */
	function wp_next_scheduled( string $hook, array $args = array() ) {
		foreach ( $GLOBALS['wpctx_test_cron_queue'] as $entry ) {
			if ( $entry['hook'] === $hook && $entry['args'] === $args ) {
				return $entry['timestamp'];
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Stub: record the scheduled event into the global queue so tests can
	 * assert cron-queue side effects.
	 */
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$GLOBALS['wpctx_test_cron_queue'][] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub: record actions but don't dispatch. Tests that need dispatch
	 * behaviour belong in the integration suite, not the unit suite.
	 */
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}
