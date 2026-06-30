<?php
/**
 * Global-namespace helpers for the WP AI Client wrapper.
 *
 * Loaded via Composer's `files` autoload so non-namespaced template code
 * (themes, mu-plugins, drop-ins) has a clean call site without needing
 * `use` statements.
 *
 * @package Mokhai
 */

declare(strict_types=1);

\defined( 'ABSPATH' ) || exit;

if ( ! \function_exists( 'mokhai_has_ai_client' ) ) {
	/**
	 * Whether the WP AI Client (shipped with WP core 7.0) is available
	 * and configured at runtime.
	 *
	 * Mirrors `Mokhai\Ai\Client_Wrapper::has_ai_client()` for callers
	 * that prefer the WordPress functional idiom.
	 */
	function mokhai_has_ai_client(): bool {
		return \Mokhai\Ai\Client_Wrapper::has_ai_client();
	}
}
