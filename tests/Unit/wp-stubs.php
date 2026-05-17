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

if ( ! isset( $GLOBALS['wpctx_test_added_actions'] ) ) {
	$GLOBALS['wpctx_test_added_actions'] = array();
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub: record actions into $GLOBALS['wpctx_test_added_actions'] so tests
	 * can assert that hooks were wired. Doesn't dispatch — tests that need
	 * dispatch behaviour use the manual-callback pattern below or belong in
	 * the integration suite.
	 */
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['wpctx_test_added_actions'][] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'wpctx_test_get_added_actions_for' ) ) {
	/**
	 * Test helper: return every recorded add_action() entry for a given hook.
	 *
	 * Lives in the stubs file (not in a Test base class) so any unit test can
	 * inspect hook registrations without re-instantiating WP's hook system.
	 *
	 * @param string $hook Hook name to filter on.
	 *
	 * @return array<int, array{hook: string, callback: mixed, priority: int, accepted_args: int}>
	 */
	function wpctx_test_get_added_actions_for( string $hook ): array {
		$out = array();
		foreach ( $GLOBALS['wpctx_test_added_actions'] as $entry ) {
			if ( $entry['hook'] === $hook ) {
				$out[] = $entry;
			}
		}
		return $out;
	}
}

if ( ! isset( $GLOBALS['wpctx_test_did_action'] ) ) {
	$GLOBALS['wpctx_test_did_action'] = array();
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub: record action dispatches so tests can assert on them.
	 * Doesn't call listeners.
	 */
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['wpctx_test_did_action'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub mirroring WP's sanitize_key: lowercased, [a-z0-9_-] only.
	 */
	function sanitize_key( $key ): string {
		if ( ! is_string( $key ) ) {
			return '';
		}
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}
}

if ( ! isset( $GLOBALS['wpctx_test_post_types'] ) ) {
	$GLOBALS['wpctx_test_post_types'] = array( 'post', 'page' );
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * Stub: return whatever the test pre-populated in
	 * $GLOBALS['wpctx_test_post_types']. Default is post + page.
	 *
	 * @param array  $args   Filter args (ignored — return everything).
	 * @param string $output 'names' (default) or 'objects'. Unit tests only
	 *                       use 'names'; 'objects' returns a minimal stdClass.
	 */
	function get_post_types( array $args = array(), string $output = 'names' ): array {
		$names = $GLOBALS['wpctx_test_post_types'];
		if ( 'objects' === $output ) {
			$objects = array();
			foreach ( $names as $name ) {
				$obj                       = new stdClass();
				$obj->name                 = $name;
				$obj->label                = ucfirst( $name );
				$obj->labels               = new stdClass();
				$obj->labels->singular_name = ucfirst( $name );
				$objects[ $name ]          = $obj;
			}
			return $objects;
		}
		return array_combine( $names, $names );
	}
}

if ( ! isset( $GLOBALS['wpctx_test_capabilities'] ) ) {
	$GLOBALS['wpctx_test_capabilities'] = array( 'manage_options' => true );
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub: return capability from the per-test globals.
	 */
	function current_user_can( string $cap, ...$args ): bool {
		// $args is accepted but unused by the stub — WP's real signature is
		// variadic (e.g. `current_user_can('edit_post', $post_id)`), and
		// PHPStan picks the stub up as the function's source-of-truth signature
		// since `tests/Unit/` is in the analyse paths. Keep this matching the
		// WordPress core signature.
		unset( $args );
		return ! empty( $GLOBALS['wpctx_test_capabilities'][ $cap ] );
	}
}

if ( ! isset( $GLOBALS['wpctx_test_options'] ) ) {
	$GLOBALS['wpctx_test_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub: read from per-test options map.
	 */
	function get_option( string $key, $default_value = false ) {
		return array_key_exists( $key, $GLOBALS['wpctx_test_options'] )
			? $GLOBALS['wpctx_test_options'][ $key ]
			: $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub: write to per-test options map.
	 */
	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['wpctx_test_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	/**
	 * Stub: no-op. Settings API registration is verified at the integration
	 * level, not unit. We keep the function present so admin_init handlers
	 * can be exercised in unit tests without fatals.
	 */
	function register_setting( string $option_group, string $option_name, $args = array() ): void {
		// No-op stub.
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	/**
	 * Stub: read from $GLOBALS['wpctx_test_active_plugins'].
	 */
	function is_plugin_active( string $plugin_file ): bool {
		$active = $GLOBALS['wpctx_test_active_plugins'] ?? array();
		return in_array( $plugin_file, $active, true );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Stub: throw a WP_Die exception so tests can assert on it.
	 */
	function wp_die( $message = '', $title = '', $args = array() ): void {
		throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub: read from $GLOBALS['wpctx_test_filters'][$hook] (array of callables)
	 * and return the last filter's value, or pass `$value` through if no
	 * filters are registered.
	 */
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['wpctx_test_filters'][ $hook ] ?? array();
		foreach ( $filters as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Stub: append a filter callback to $GLOBALS['wpctx_test_filters'][$hook]
	 * so a test can simulate filter behaviour without booting WP.
	 */
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['wpctx_test_filters'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub: drop tags + collapse whitespace. Matches WP core behaviour
	 * closely enough for the Cleanup_Guard's allowlist build.
	 */
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ?? $text;
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? $text;
		}
		return trim( $text );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal stub of WP_Post. Tests construct one with the properties they
	 * need; missing properties stay null.
	 */
	class WP_Post {
		public int $ID                 = 0;
		public string $post_type       = 'post';
		public string $post_status     = 'publish';
		public string $post_password   = '';
		public string $post_content    = '';
		public string $post_title      = '';
		public string $post_modified_gmt = '';
	}
}
