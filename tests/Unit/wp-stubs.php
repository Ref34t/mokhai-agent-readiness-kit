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
 * @package Mokhai\Tests
 */

declare(strict_types=1);

if ( ! isset( $GLOBALS['wpctx_test_cron_queue'] ) ) {
	$GLOBALS['wpctx_test_cron_queue'] = array();
}

if ( ! isset( $GLOBALS['wpctx_test_post_meta'] ) ) {
	$GLOBALS['wpctx_test_post_meta'] = array();
}

if ( ! isset( $GLOBALS['wpctx_test_post_terms'] ) ) {
	$GLOBALS['wpctx_test_post_terms'] = array();
}

if ( ! function_exists( 'has_term' ) ) {
	/**
	 * Stub: report whether a post carries any of the given terms in a
	 * taxonomy. Seam: $GLOBALS['wpctx_test_post_terms'][post_id][taxonomy]
	 * holds a mixed array of assigned term IDs (int) and slugs (string),
	 * mirroring core's mixed-needle acceptance.
	 *
	 * @param string|int|array<int, string|int> $term     Term needle(s).
	 * @param string                            $taxonomy Taxonomy slug.
	 * @param \WP_Post|int|null                 $post     Post or post ID.
	 */
	function has_term( $term = '', string $taxonomy = '', $post = null ): bool {
		$post_id  = is_object( $post ) ? (int) $post->ID : (int) $post;
		$assigned = $GLOBALS['wpctx_test_post_terms'][ $post_id ][ $taxonomy ] ?? array();

		foreach ( (array) $term as $needle ) {
			$needle = is_numeric( $needle ) ? (int) $needle : (string) $needle;
			if ( in_array( $needle, $assigned, true ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Stub: store a single post-meta value in the global map. Single-value
	 * semantics (no add-vs-update distinction) — enough for the plugin's
	 * scalar-meta usage. Tests reset $GLOBALS['wpctx_test_post_meta'] in
	 * setUp to isolate.
	 *
	 * @param mixed $meta_value Value to store.
	 * @return bool Always true.
	 */
	function update_post_meta( int $post_id, string $meta_key, $meta_value ): bool {
		$GLOBALS['wpctx_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Stub: read a single post-meta value, mirroring core's `$single=true`
	 * shape (returns '' when absent).
	 *
	 * @return mixed Stored value, or '' when unset.
	 */
	function get_post_meta( int $post_id, string $meta_key = '', bool $single = false ) {
		$value = $GLOBALS['wpctx_test_post_meta'][ $post_id ][ $meta_key ] ?? '';
		return $value;
	}
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

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Stub approximating WP's sanitize_title: lowercased, non-alphanumerics
	 * collapsed to single hyphens, trimmed. Sufficient for the exclude-slug
	 * deny-list sanitiser (#180).
	 */
	function sanitize_title( $title ): string {
		if ( ! is_string( $title ) ) {
			return '';
		}
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub mirroring WP's absint: non-negative integer.
	 */
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	/**
	 * Stub: read a field off a WP_Post test double. Mirrors the subset of
	 * core's get_post_field() the exposure predicate uses (#180) — given a
	 * WP_Post object, return the named property as a string.
	 *
	 * @param string $field   Field name (e.g. 'post_name').
	 * @param mixed  $post    WP_Post test double.
	 * @param string $context Unused in the stub (core applies filters here).
	 * @return string
	 */
	function get_post_field( string $field, $post = null, string $context = 'display' ): string {
		unset( $context );
		if ( is_object( $post ) && isset( $post->$field ) ) {
			return (string) $post->$field;
		}
		return '';
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

if ( ! function_exists( 'wp_sprintf_l' ) ) {
	/**
	 * Stub: locale-aware list join matching WP core's English `%l` output
	 * ("a", "a and b", "a, b, and c"). Only the `%l` pattern is supported.
	 */
	function wp_sprintf_l( string $pattern, array $args ): string {
		$args = array_values( $args );
		$n    = count( $args );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return (string) $args[0];
		}
		if ( 2 === $n ) {
			return $args[0] . ' and ' . $args[1];
		}
		$last = array_pop( $args );
		return implode( ', ', $args ) . ', and ' . $last;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub: minimal HTML escape matching WP core behaviour.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
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
	 * Stub: read from $GLOBALS['mokhai_test_filters'][$hook] (array of callables)
	 * and return the last filter's value, or pass `$value` through if no
	 * filters are registered.
	 */
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['mokhai_test_filters'][ $hook ] ?? array();
		foreach ( $filters as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Stub: append a filter callback to $GLOBALS['mokhai_test_filters'][$hook]
	 * so a test can simulate filter behaviour without booting WP.
	 */
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['mokhai_test_filters'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub: detect a stub WP_Error instance by class.
	 *
	 * @param mixed $thing
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stub of WP_Error. Tests construct one with a code +
	 * message; missing constructor args default to empty strings.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;

		/**
		 * @param mixed $data
		 */
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			unset( $data );
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Stub: returns a fluent test builder driven by
	 * `$GLOBALS['wpctx_test_ai_response']`. Per-test the response is
	 * either a string (success) or a `WP_Error` (failure). The builder
	 * collects every `->using_*()` / `->as_*()` chain call into
	 * `$GLOBALS['wpctx_test_ai_calls']` so tests can assert option
	 * propagation.
	 */
	function wp_ai_client_prompt( string $prompt ): object {
		$GLOBALS['wpctx_test_ai_calls'] = array(
			array( 'method' => 'wp_ai_client_prompt', 'args' => array( $prompt ) ),
		);

		return new class {
			/**
			 * @param array<int, mixed> $args
			 */
			public function __call( string $name, array $args ) {
				$GLOBALS['wpctx_test_ai_calls'][] = array( 'method' => $name, 'args' => $args );

				if ( 'generate_text' === $name ) {
					$response = $GLOBALS['wpctx_test_ai_response'] ?? '';
					return $response;
				}

				return $this;
			}
		};
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub: drop tags + collapse whitespace. Matches WP core behaviour
	 * closely enough for allowlist builds.
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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub: collapse whitespace, strip tags. Matches the real function's
	 * behaviour closely enough for unit-test purposes.
	 */
	function sanitize_text_field( string $value ): string {
		$value = strip_tags( $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_replace( '/\s+/', ' ', (string) $value ) ?? '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub: scheme allowlist check + trim. Real function does more
	 * normalisation, but for unit-test purposes we only need to verify
	 * that the scheme guard works and known schemes pass through.
	 *
	 * @param string|null   $url      URL to validate.
	 * @param string[]|null $protocols Allowed schemes (e.g. ['http','https','mailto']).
	 */
	function esc_url_raw( $url, $protocols = null ): string {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$allowed = is_array( $protocols ) ? $protocols : array( 'http', 'https' );
		$scheme  = '';
		if ( preg_match( '#^([a-zA-Z][a-zA-Z0-9+.-]*):#', $url, $m ) ) {
			$scheme = strtolower( $m[1] );
		}
		if ( '' === $scheme ) {
			// Schemeless — treat as relative path; not a valid editorial URL.
			return '';
		}
		if ( ! in_array( $scheme, $allowed, true ) ) {
			return '';
		}
		return $url;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub: WordPress's `wp_parse_url` is `parse_url` with PHP-version
	 * normalisation. For unit-test purposes plain `parse_url` (full component
	 * mode) is faithful enough — callers read `path` / `query` keys.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Component to return (-1 = all, as an array).
	 *
	 * @return array<string, int|string>|string|int|null|false
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub: display-context URL escaper. The real function HTML-encodes
	 * ampersands (`&` → `&#038;`) among other things; for unit-test purposes
	 * we reproduce that one transform on top of the `esc_url_raw` scheme guard
	 * so attribute-context assertions are meaningful.
	 *
	 * @param string|null   $url       URL to escape.
	 * @param string[]|null $protocols Allowed schemes.
	 */
	function esc_url( $url, $protocols = null ): string {
		$raw = esc_url_raw( $url, $protocols );
		if ( '' === $raw ) {
			return '';
		}
		return str_replace( '&', '&#038;', $raw );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub: delegate to PHP's json_encode. The real wp_json_encode adds
	 * UTF-8 fallback handling but tests don't exercise that branch.
	 *
	 * @param mixed $value Value to encode.
	 * @param int   $options json_encode flags.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( $value, int $options = 0, int $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}

if ( ! isset( $GLOBALS['wpctx_test_bloginfo'] ) ) {
	$GLOBALS['wpctx_test_bloginfo'] = array(
		'name'        => 'Test Site',
		'description' => 'Just another WordPress site',
		'language'    => 'en-US',
	);
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Stub: read from $GLOBALS['wpctx_test_bloginfo'].
	 *
	 * Tests can override individual fields by mutating the global directly.
	 *
	 * Return type is intentionally omitted: szepeviktor/phpstan-wordpress
	 * models get_bloginfo() as a broader union (the real function can
	 * return values shaped by the requested field). Tightening the stub
	 * to `: string` would invalidate defensive `is_string()` checks
	 * elsewhere in the codebase.
	 */
	function get_bloginfo( string $field = 'name', string $filter = 'raw' ) {
		unset( $filter );
		$values = $GLOBALS['wpctx_test_bloginfo'];
		return array_key_exists( $field, $values ) ? (string) $values[ $field ] : '';
	}
}

if ( ! isset( $GLOBALS['wpctx_test_home_url'] ) ) {
	$GLOBALS['wpctx_test_home_url'] = 'https://example.test';
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub: concatenate the per-test base URL with the supplied path. The
	 * real home_url() does scheme + permalink normalisation; the unit tests
	 * here only care about the path stitching.
	 */
	function home_url( string $path = '', $scheme = null ) {
		unset( $scheme );
		$base = rtrim( (string) $GLOBALS['wpctx_test_home_url'], '/' );
		if ( '' === $path ) {
			return $base;
		}
		return $base . '/' . ltrim( $path, '/' );
	}
}

if ( ! isset( $GLOBALS['wpctx_test_query_context'] ) ) {
	$GLOBALS['wpctx_test_query_context'] = array(
		'is_singular_type' => '', // '' | 'post' | 'page' | …
		'is_front_page'    => false,
		'queried_object_id' => 0,
	);
}

if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * Stub: matches when the test's `is_singular_type` is in $post_types
	 * (or when $post_types is empty and any type is set).
	 *
	 * @param string|string[] $post_types Post type or list of types to match.
	 */
	function is_singular( $post_types = '' ): bool {
		$type = (string) ( $GLOBALS['wpctx_test_query_context']['is_singular_type'] ?? '' );
		if ( '' === $type ) {
			return false;
		}
		if ( '' === $post_types || array() === $post_types ) {
			return true;
		}
		$types = is_array( $post_types ) ? $post_types : array( $post_types );
		return in_array( $type, $types, true );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool {
		return ! empty( $GLOBALS['wpctx_test_query_context']['is_front_page'] );
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int {
		return (int) ( $GLOBALS['wpctx_test_query_context']['queried_object_id'] ?? 0 );
	}
}

if ( ! isset( $GLOBALS['wpctx_test_posts'] ) ) {
	$GLOBALS['wpctx_test_posts'] = array();
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Stub: return whichever WP_Post stub the test stored at
	 * $GLOBALS['wpctx_test_posts'][$id]. When no $id is supplied, fall
	 * back to the queried-object id.
	 *
	 * @param int|null $post_id Post ID to fetch.
	 */
	function get_post( $post_id = null ) {
		$id = null === $post_id
			? (int) ( $GLOBALS['wpctx_test_query_context']['queried_object_id'] ?? 0 )
			: (int) $post_id;
		return $GLOBALS['wpctx_test_posts'][ $id ] ?? null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Stub: home_url() + '?p=<id>' so tests can assert a permalink-like
	 * string is composed without booting the rewrite engine.
	 *
	 * @param int|\WP_Post $post Post id or post object.
	 */
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		if ( 0 === $id ) {
			$id = (int) ( $GLOBALS['wpctx_test_query_context']['queried_object_id'] ?? 0 );
		}
		return home_url( '/?p=' . $id );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * Stub: read post title off the stored WP_Post stub.
	 *
	 * @param int|\WP_Post $post Post id or post object.
	 */
	function get_the_title( $post = 0 ) {
		$resolved = is_object( $post ) ? $post : ( $GLOBALS['wpctx_test_posts'][ (int) $post ] ?? null );
		if ( ! is_object( $resolved ) ) {
			return '';
		}
		return isset( $resolved->post_title ) ? (string) $resolved->post_title : '';
	}
}

if ( ! function_exists( 'get_post_time' ) ) {
	/**
	 * Stub: returns a fixed ISO timestamp the test can assert against,
	 * driven by the post's `post_date_gmt` field. The real WP signature is
	 * `( $format, $gmt = false, $post = null, $translate = false )`.
	 *
	 * @param string       $format   Date format spec (e.g. 'c').
	 * @param bool         $gmt      Whether to use GMT.
	 * @param int|\WP_Post $post     Post id or post object.
	 */
	function get_post_time( $format = 'U', $gmt = false, $post = 0 ) {
		unset( $gmt );
		$resolved = is_object( $post ) ? $post : ( $GLOBALS['wpctx_test_posts'][ (int) $post ] ?? null );
		if ( ! is_object( $resolved ) ) {
			return false;
		}
		$raw = $resolved->post_date_gmt ?? '2026-01-01T00:00:00+00:00';
		if ( 'c' === $format || 'U' === $format ) {
			return (string) $raw;
		}
		return (string) $raw;
	}
}

if ( ! function_exists( 'get_post_modified_time' ) ) {
	/**
	 * Stub: see get_post_time. Reads `post_modified_gmt` instead.
	 *
	 * @param int|\WP_Post $post Post id or post object.
	 */
	function get_post_modified_time( $format = 'U', $gmt = false, $post = 0 ) {
		unset( $gmt );
		$resolved = is_object( $post ) ? $post : ( $GLOBALS['wpctx_test_posts'][ (int) $post ] ?? null );
		if ( ! is_object( $resolved ) ) {
			return false;
		}
		$raw = $resolved->post_modified_gmt ?? '2026-01-01T00:00:00+00:00';
		return (string) $raw;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub: WP's wp_unslash strips slashes added by magic_quotes-style
	 * input handling. For unit-test inputs we just return the value.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
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
		public string $post_name       = '';
		public string $post_content    = '';
		public string $post_excerpt    = '';
		public string $post_title      = '';
		public string $post_date_gmt   = '';
		public string $post_modified_gmt = '';
	}
}
