<?php
/**
 * WP-CLI command surface for Markdown Views.
 *
 * Registers `wp ai-readiness-kit md preview <target> [...]` as a power-user and
 * scripted-workflow path that bypasses the wp-admin UI per AgDR-0014.
 * Same backend as the public route + REST endpoint — every surface
 * funnels into `Service::get_markdown_for_post()`.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Cli;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Service;

\defined( 'ABSPATH' ) || exit;

/**
 * Manage Markdown Views from the command line.
 *
 * ## EXAMPLES
 *
 *     # Preview the MD for a post by ID, raw output to stdout.
 *     $ wp ai-readiness-kit md preview 42
 *
 *     # Preview by permalink instead of ID.
 *     $ wp ai-readiness-kit md preview https://example.com/about-us/
 *
 *     # Wrap the MD with a YAML-ish header (title, canonical URL,
 *     # generated_at). Useful for piping into LLM tooling that wants
 *     # source metadata.
 *     $ wp ai-readiness-kit md preview 42 --format=wrapped
 *
 *     # Print cache-state diagnostics to stderr alongside the MD body.
 *     $ wp ai-readiness-kit md preview 42 --show-meta
 *
 *     # Force preview a hidden post (draft, password-protected, etc.).
 *     # Requires manage_options capability. The output is NOT served
 *     # publicly — this flag only affects CLI introspection.
 *     $ wp ai-readiness-kit md preview 42 --bypass-exposure
 */
final class Markdown_Views_Command {

	/**
	 * Register the command tree with WP-CLI. Called from Main::register_hooks()
	 * when the WP_CLI constant is defined.
	 */
	public static function register(): void {
		if ( ! \defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'mokhai md', self::class );
		\WP_CLI::add_command( 'ai-readiness-kit md', self::class );
	}

	/**
	 * Preview the Markdown rendering for a post.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : Post ID (numeric) or permalink URL.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: raw
	 * options:
	 *   - raw
	 *   - wrapped
	 * ---
	 *
	 * [--show-meta]
	 * : Print cache-state diagnostics to stderr (cached vs regenerated,
	 *   content_hash, generated_at). Stdout stays pure MD.
	 *
	 * [--bypass-exposure]
	 * : Render even when the post is hidden by Context Profile (draft,
	 *   password-protected, unexposed CPT, noindex). Requires `manage_options`.
	 *   Output is for CLI introspection only — never served publicly.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-readiness-kit md preview 42
	 *     wp ai-readiness-kit md preview /about-us/ --format=wrapped
	 *     wp ai-readiness-kit md preview 42 --show-meta
	 *
	 * @param array<int, string>      $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 */
	public function preview( array $args, array $assoc_args ): void {
		$target = $args[0] ?? '';
		$post   = self::resolve_post( $target );

		if ( null === $post ) {
			\WP_CLI::error( \sprintf( /* translators: %s is the user-supplied target. */ \__( 'No post matches "%s".', 'mokhai-agent-readiness-kit' ), $target ) );
		}

		$bypass = isset( $assoc_args['bypass-exposure'] ) && (bool) $assoc_args['bypass-exposure'];

		if ( $bypass && ! \current_user_can( 'manage_options' ) ) {
			\WP_CLI::error(
				\__(
					'--bypass-exposure requires the manage_options capability. Run with `--user=<id-or-login>` to authenticate as an administrator (e.g. `wp --user=admin agentready md preview <id> --bypass-exposure`).',
					'mokhai-agent-readiness-kit'
				)
			);
		}

		// Module-disabled is reported regardless of --bypass-exposure: the
		// toggle is an explicit admin choice and CLI output for a disabled
		// module would be confusing.
		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			\WP_CLI::error( \__( 'Markdown Views is disabled in the Context Profile.', 'mokhai-agent-readiness-kit' ) );
		}

		if ( ! $bypass ) {
			$reason = Context_Profile_Settings::get_exposure_reason( $post );
			if ( null !== $reason ) {
				\WP_CLI::error(
					\sprintf(
						/* translators: 1: post ID, 2: reason code (cpt|status|password|noindex). */
						\__( 'Post #%1$d is not exposable (reason: %2$s). Use --bypass-exposure to preview anyway (requires manage_options).', 'mokhai-agent-readiness-kit' ),
						(int) $post->ID,
						$reason
					)
				);
			}
		}

		// On the bypass path, route through the walker directly so we
		// don't pollute the cache table with rendered content for a hidden
		// post. On the normal path, Service handles caching + invalidation.
		if ( $bypass ) {
			$markdown = self::render_bypass( $post );
		} else {
			$result = Service::get_markdown_for_post( $post );
			if ( \is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			$markdown = $result;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'raw';

		if ( 'wrapped' === $format ) {
			$markdown = self::wrap_with_header( $post, $markdown );
		}

		\WP_CLI::log( $markdown );

		if ( isset( $assoc_args['show-meta'] ) && (bool) $assoc_args['show-meta'] ) {
			self::print_cache_meta( (int) $post->ID );
		}
	}

	/**
	 * Resolve a target string (ID or URL) into a WP_Post.
	 */
	private static function resolve_post( string $target ): ?\WP_Post {
		if ( '' === $target ) {
			return null;
		}

		if ( \ctype_digit( $target ) ) {
			$post = \get_post( (int) $target );
			return $post instanceof \WP_Post ? $post : null;
		}

		$post_id = \url_to_postid( $target );
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = \get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Render via the walker directly, bypassing the cache table. Used only
	 * on the --bypass-exposure path so hidden-post output isn't persisted.
	 */
	private static function render_bypass( \WP_Post $post ): string {
		// Same filter pipeline the public route uses, so the bypass output
		// matches what would be served if the post were exposable.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$html = (string) \apply_filters( 'the_content', $post->post_content );
		return \Mokhai\Markdown_Views\Walker::convert( $html )->get_markdown();
	}

	/**
	 * Prefix the MD body with a YAML header containing post metadata.
	 * Used by `--format=wrapped`. Header is bracketed by `---` lines so
	 * downstream LLM tooling that parses YAML front matter works directly.
	 */
	private static function wrap_with_header( \WP_Post $post, string $markdown ): string {
		return self::format_yaml_header(
			(int) $post->ID,
			(string) $post->post_title,
			(string) \get_permalink( $post ),
			\current_time( 'c', true )
		) . $markdown;
	}

	/**
	 * Build the YAML front-matter header from pre-fetched scalar values.
	 * Internal: split out from `wrap_with_header()` so the YAML emission
	 * shape is unit-testable without a `WP_Post` / `get_permalink()` /
	 * `current_time()` dependency.
	 *
	 * String scalars are emitted via `wp_json_encode()` with
	 * `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. JSON string
	 * scalars are a strict subset of YAML 1.2 double-quoted scalars, so
	 * this handles backslashes, embedded quotes, literal newlines, tabs,
	 * and other control characters that the prior naïve `str_replace`
	 * approach mis-escaped (see AgDR-0038, GH#42).
	 *
	 * @param int    $id            Post ID.
	 * @param string $title         Post title; may contain any character.
	 * @param string $canonical_url Permalink; may contain any URL character.
	 * @param string $generated_at  ISO-8601 timestamp.
	 * @return string YAML header including leading + trailing `---` lines and a blank line.
	 */
	public static function format_yaml_header(
		int $id,
		string $title,
		string $canonical_url,
		string $generated_at
	): string {
		$flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

		return "---\n"
			. 'id: ' . $id . "\n"
			. 'title: ' . \wp_json_encode( $title, $flags ) . "\n"
			. 'canonical_url: ' . \wp_json_encode( $canonical_url, $flags ) . "\n"
			. 'generated_at: ' . \wp_json_encode( $generated_at, $flags ) . "\n"
			. "---\n\n";
	}

	/**
	 * Emit cache-state diagnostics to stderr (`WP_CLI::log` writes to stdout,
	 * `WP_CLI::warning` writes to stderr — we use the warning channel here
	 * deliberately so `--show-meta` doesn't pollute the MD body on stdout).
	 */
	private static function print_cache_meta( int $post_id ): void {
		global $wpdb;

		$table = \Mokhai\Markdown_Views\Schema::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT content_hash, walker_version, generated_at FROM {$table} WHERE post_id = %d",
				$post_id
			),
			\ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! \is_array( $row ) ) {
			\WP_CLI::warning( \__( 'cache: miss (no row)', 'mokhai-agent-readiness-kit' ) );
			return;
		}

		\WP_CLI::warning(
			\sprintf(
				/* translators: 1: content hash, 2: walker version, 3: generated_at timestamp. */
				\__( 'cache: hit | hash=%1$s | walker_version=%2$s | generated_at=%3$s', 'mokhai-agent-readiness-kit' ),
				(string) $row['content_hash'],
				(string) $row['walker_version'],
				(string) $row['generated_at']
			)
		);
	}
}
