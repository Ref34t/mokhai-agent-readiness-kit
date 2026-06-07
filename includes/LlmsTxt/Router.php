<?php
/**
 * `/llms.txt` URL routing.
 *
 * Owns the rewrite rule (AgDR-0021) plus the activation / deactivation
 * rewrite-flush lifecycle, and dispatches the `template_redirect` request to
 * the response builder.
 *
 * Behaviour-vs-side-effects split mirrors `Markdown_Views\Handler`:
 *   - `build_response()` is a pure decision function returning a response
 *     array — testable without mocking `exit`.
 *   - `maybe_serve()` is the WordPress entry point that calls `dispatch()`.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

use WPContext\Support\Output_Buffer;

\defined( 'ABSPATH' ) || exit;

/**
 * Rewrite-rule lifecycle + dispatch for `/llms.txt`.
 */
final class Router {

	/**
	 * Query var carrying the "llms.txt request" flag from the rewrite rule
	 * through to the template_redirect handler.
	 *
	 * @var string
	 */
	public const REWRITE_VAR = 'agentready_llms_txt';

	/**
	 * Wire the WordPress hooks owned by this class.
	 */
	public static function register_hooks(): void {
		\add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		\add_filter( 'query_vars', array( self::class, 'register_query_var' ) );
		\add_action( 'template_redirect', array( self::class, 'maybe_serve' ), 0 );
	}

	/**
	 * Register the `/llms.txt` rewrite rule on `init`.
	 *
	 * `'top'` precedence ensures we match before WP's permalink rules try to
	 * resolve a page slugged `llms.txt`. The trailing-slash variant
	 * (`/llms.txt/`) is also accepted via the `/?$` suffix.
	 */
	public static function add_rewrite_rule(): void {
		\add_rewrite_tag( '%' . self::REWRITE_VAR . '%', '1' );
		\add_rewrite_rule(
			'^llms\.txt/?$',
			'index.php?' . self::REWRITE_VAR . '=1',
			'top'
		);
	}

	/**
	 * Register the query var so WordPress doesn't strip it during request parsing.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 *
	 * @return array<int, string>
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::REWRITE_VAR;
		return $vars;
	}

	/**
	 * Activation lifecycle: register the rule, then flush so the rewrite is
	 * persisted into the `rewrite_rules` option.
	 */
	public static function flush_on_activation(): void {
		self::add_rewrite_rule();
		\flush_rewrite_rules();
	}

	/**
	 * Deactivation lifecycle: flush after our init hook is unregistered so the
	 * rule disappears from the persisted set.
	 */
	public static function flush_on_deactivation(): void {
		\flush_rewrite_rules();
	}

	/**
	 * `template_redirect` callback. Returns silently if the request isn't
	 * the llms.txt route; otherwise dispatches the response.
	 */
	public static function maybe_serve(): void {
		if ( ! self::is_llms_txt_request() ) {
			return;
		}

		self::dispatch( self::build_response() );
	}

	/**
	 * Decide whether the current request is the llms.txt route. The rewrite
	 * rule sets the query var to `1`; absence (or any other value) means
	 * not-our-request.
	 */
	public static function is_llms_txt_request(): bool {
		return '1' === (string) \get_query_var( self::REWRITE_VAR );
	}

	/**
	 * Build the response shape — pure, no globals, no headers emitted. Tests
	 * inspect the returned array directly.
	 *
	 * Empty body is a valid response: per AgDR-0021 § "Public route is
	 * uniformly 200 or 404, never 500", an empty composition (fresh install
	 * with no exposed CPTs) returns 200 + empty body. Agents that fetch
	 * /llms.txt on a fresh install see an empty document and move on.
	 *
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	public static function build_response(): array {
		$body = Service::get_composed_body();

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'  => 'text/plain; charset=' . self::charset(),
				'X-Robots-Tag'  => 'noindex, nofollow',
				'Cache-Control' => 'no-store, must-revalidate',
			),
			'body'    => $body,
		);
	}

	/**
	 * Resolve the response charset. Honours the `blog_charset` option so
	 * non-UTF-8 sites (rare but legal) get the matching declaration.
	 */
	private static function charset(): string {
		$charset = \get_option( 'blog_charset', 'UTF-8' );
		return is_string( $charset ) && '' !== $charset ? $charset : 'UTF-8';
	}

	/**
	 * Emit the response and terminate. Wrapped so tests can call
	 * `build_response()` directly without the `exit`.
	 *
	 * @param array{status:int, headers:array<string,string>, body:string} $response Response shape.
	 */
	private static function dispatch( array $response ): void {
		// Discard any BOM / whitespace leaked into the output buffer by the
		// theme or another plugin BEFORE we send headers, so our text/plain
		// body starts at the intended first byte and the headers below still
		// send (a flushed buffer would have already sent them). See #175.
		Output_Buffer::discard_pending();

		\status_header( $response['status'] );
		\nocache_headers();

		foreach ( $response['headers'] as $name => $value ) {
			\header( $name . ': ' . $value );
		}

		// Raw text/plain body — no HTML rendering context applies, so the
		// HTML-escape sniff doesn't apply either. Strip a leading BOM in case
		// the composed body itself begins with one (#175).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Output_Buffer::strip_leading_bom( $response['body'] );

		exit;
	}
}
