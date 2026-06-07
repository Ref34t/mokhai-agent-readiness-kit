<?php
/**
 * Output-buffer hygiene for the plugin's agent-facing surfaces.
 *
 * Some sites leak a UTF-8 BOM (`EF BB BF`) or stray whitespace from a theme
 * file or mu-plugin BEFORE any plugin gets to emit its own response. When that
 * leaked output sits in a PHP output buffer it gets prepended to whatever the
 * plugin echoes next, which:
 *   - puts a `\n\n` (or BOM) before `/llms.txt`'s first `# H1` line, and
 *   - corrupts REST JSON so the admin's `JSON.parse` throws "not a valid JSON
 *     response" even though the body in the Network tab looks correct.
 *
 * The pollution is upstream — the plugin can't fix it at the source (the site's
 * own `/wp-json/` is equally affected) — but it CAN refuse to emit polluted
 * output from its own surfaces. This helper is the shared seam used by all
 * three agent-facing emit points (`/llms.txt`, the `.md` views, and the REST
 * controllers). It mirrors WP core's own `wp_send_json` ob-clean discipline.
 *
 * No AI, no external calls. Deterministic; zero behaviour change on a site
 * whose output is already clean.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Support;

\defined( 'ABSPATH' ) || exit;

/**
 * Discards upstream-leaked output and strips leading BOM/whitespace so the
 * plugin's response body starts at exactly the intended first byte.
 */
final class Output_Buffer {

	/**
	 * REST route prefix the buffer-clean filter is scoped to. Only requests
	 * under the plugin's own namespace are touched — other plugins' REST
	 * routes are left entirely alone.
	 */
	private const REST_ROUTE_PREFIX = '/ai-readiness-kit/';

	/**
	 * Wire the REST hardening hook.
	 *
	 * A single namespace-scoped `rest_pre_serve_request` filter covers every
	 * one of the plugin's REST controllers without per-controller edits: WP
	 * serializes + echoes `WP_REST_Response` objects centrally in
	 * `WP_REST_Server::serve_request()` and does no ob-clean of its own, so
	 * this filter is the one place to discard upstream pollution before the
	 * JSON body is written.
	 */
	public static function register_hooks(): void {
		\add_filter( 'rest_pre_serve_request', array( self::class, 'clean_before_rest_serve' ), 10, 3 );
	}

	/**
	 * `rest_pre_serve_request` callback. Discards any pending output buffer
	 * immediately before WP echoes the JSON body, but ONLY for the plugin's
	 * own routes. Returns `$served` unchanged — the filter cleans, it does
	 * not take over serving.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param mixed            $result  Response data to send (unused).
	 * @param \WP_REST_Request $request The request being served.
	 *
	 * @return bool The unchanged `$served` value.
	 */
	public static function clean_before_rest_serve( $served, $result, $request ) {
		if ( $request instanceof \WP_REST_Request && self::is_plugin_rest_route( (string) $request->get_route() ) ) {
			self::discard_pending();
		}

		return $served;
	}

	/**
	 * Whether a REST route belongs to the plugin's own namespace — the scope
	 * of the buffer-clean filter. Pure predicate, broken out so the scoping
	 * decision is testable without touching the output-buffer stack.
	 *
	 * @param string $route The REST route, e.g. `/ai-readiness-kit/v1/...`.
	 *
	 * @return bool True for the plugin's own routes only.
	 */
	public static function is_plugin_rest_route( string $route ): bool {
		return 0 === \strpos( $route, self::REST_ROUTE_PREFIX );
	}

	/**
	 * Discard every pending output buffer so the next bytes written are the
	 * plugin's own response.
	 *
	 * Skipped entirely once headers have been sent: at that point any leaked
	 * BOM has already been flushed to the client and is unrecoverable (the
	 * out-of-scope upstream case). Acting then would only emit "headers
	 * already sent" warnings, so we degrade silently instead.
	 *
	 * The loop ends a buffer per iteration and stops if a buffer can't be
	 * removed (e.g. a non-removable zlib compression handler returns `false`
	 * from `ob_end_clean()`), so it can never spin.
	 */
	public static function discard_pending(): void {
		if ( \headers_sent() ) {
			return;
		}

		while ( \ob_get_level() > 0 ) {
			if ( ! \ob_end_clean() ) {
				break;
			}
		}
	}

	/**
	 * Strip a single leading UTF-8 BOM and any leading whitespace from a body
	 * the plugin is about to emit.
	 *
	 * Belt-and-suspenders alongside {@see discard_pending()}: it guards the
	 * rare case where the composed body ITSELF begins with a BOM (e.g. source
	 * post content saved with one) rather than the upstream-buffer case.
	 * Trailing content is never touched. Pure string transform.
	 *
	 * @param string $body The response body.
	 *
	 * @return string The body with a leading BOM + whitespace removed.
	 */
	public static function strip_leading_bom( string $body ): string {
		// Drop one leading UTF-8 BOM if present, then trim leading whitespace.
		if ( 0 === \strncmp( $body, "\xEF\xBB\xBF", 3 ) ) {
			$body = \substr( $body, 3 );
		}

		return \ltrim( $body );
	}
}
