<?php
/**
 * Markdown Views orchestration service.
 *
 * Single backend used by the public route, the REST endpoint, and the WP-CLI
 * command per AgDR-0014. Glues the Walker (AgDR-0010) to the cache table
 * (AgDR-0011), enforces the Context Profile exposure verdict (AgDR-0012),
 * and respects the module-enabled toggle (AgDR-0015).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

use Mokhai\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * Service::get_markdown_for_post() is the single integration point between a
 * caller and the Markdown Views feature. Returning a `WP_Error` is how the
 * service communicates each gate's outcome (module disabled, post not
 * exposable, post not found); callers translate that to either a 404 (public
 * route) or a structured error response (REST / CLI).
 */
final class Service {

	/**
	 * Error code: the `markdown_views_enabled` flag is false. Returned by
	 * `get_markdown_for_post()` so the public route can 404 silently while
	 * the REST and CLI surfaces can distinguish "off" from "not exposable".
	 *
	 * @var string
	 */
	public const ERROR_MODULE_DISABLED = 'module_disabled';

	/**
	 * Error code: the post fails Context Profile's exposure check.
	 *
	 * @var string
	 */
	public const ERROR_NOT_EXPOSABLE = 'not_exposable';

	/**
	 * Wire the cache-invalidation hooks. Called from `Main::register_hooks()`.
	 *
	 * Per AgDR-0011, invalidation is eager (hook-driven on the four post
	 * lifecycle events) and complementary to the lazy content-hash check on
	 * read. If a future filter bypasses these hooks (programmatic post
	 * writes that suppress them), the content-hash check catches the staleness
	 * on the next read.
	 */
	public static function register_hooks(): void {
		\add_action( 'save_post', array( self::class, 'invalidate' ), 10, 1 );
		\add_action( 'wp_trash_post', array( self::class, 'invalidate' ), 10, 1 );
		\add_action( 'before_delete_post', array( self::class, 'invalidate' ), 10, 1 );
		\add_action( 'wp_after_insert_post', array( self::class, 'invalidate' ), 10, 1 );
	}

	/**
	 * Resolve the Markdown rendering for a post.
	 *
	 * Returns the MD string on success, or a `\WP_Error` with one of the
	 * `ERROR_*` codes on a denied request. Never returns `null`; callers may
	 * assume the response is one of these two shapes.
	 *
	 * @param \WP_Post $post Post to render.
	 *
	 * @return string|\WP_Error
	 */
	public static function get_markdown_for_post( \WP_Post $post ) {
		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return new \WP_Error(
				self::ERROR_MODULE_DISABLED,
				\__( 'Markdown Views is disabled in the Context Profile.', 'mokhai-agent-readiness-kit' )
			);
		}

		if ( ! Context_Profile_Settings::is_url_exposable( $post ) ) {
			return new \WP_Error(
				self::ERROR_NOT_EXPOSABLE,
				\__( 'This post is not exposable.', 'mokhai-agent-readiness-kit' )
			);
		}

		$hash    = self::content_hash( $post );
		$post_id = (int) $post->ID;

		$cached = self::read_cache( $post_id, $hash );

		if ( null !== $cached ) {
			return $cached;
		}

		$conversion = Walker::convert( self::render_source_html( $post ) );
		$md         = $conversion->get_markdown();

		self::write_cache( $post_id, $hash, $conversion );

		return $md;
	}

	/**
	 * Build the HTML that the Walker converts to Markdown for a post.
	 *
	 * The single source-building helper, shared by `get_markdown_for_post()`
	 * (live `.md` / REST / CLI) and `regenerate_conversion_for()` (the
	 * preview ability) so the two paths can never drift (#252).
	 *
	 * The base is `the_content` over `post_content` — the canonical render
	 * path through every registered formatter. The result then passes through
	 * the `agentready_markdown_source_html` filter, which is the extension
	 * point post types whose canonical copy lives elsewhere hook into. The
	 * bundled WooCommerce adapter ({@see Woocommerce_Source}) uses it to
	 * prepend a product's short description, which is otherwise absent from
	 * `the_content` and left products rendering an empty body.
	 *
	 * @param \WP_Post $post Post to source HTML for.
	 *
	 * @return string Rendered HTML, pre-conversion.
	 */
	private static function render_source_html( \WP_Post $post ): string {
		// `the_content` is a WordPress core filter — this is the canonical way
		// to render post content through all registered formatters before
		// converting to MD. The PrefixAllGlobals sniff only applies to hooks
		// our plugin DEFINES, not core hooks we CONSUME.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$html = (string) \apply_filters( 'the_content', $post->post_content );

		/**
		 * Filter the HTML sourced for a post's Markdown View before conversion.
		 *
		 * Lets integrations augment the body for post types whose canonical
		 * copy is not in `post_content` (e.g. WooCommerce products, whose
		 * description is the short description / `post_excerpt`). Anything a
		 * callback reads to build the body MUST also be reflected in the cache
		 * key — see the `agentready_markdown_content_hash` filter on
		 * {@see self::content_hash()}.
		 *
		 * @param string   $html The `the_content`-rendered HTML.
		 * @param \WP_Post $post The post being rendered.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$html = (string) \apply_filters( 'mokhai_markdown_source_html', $html, $post );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$html = (string) \apply_filters_deprecated( 'agentready_markdown_source_html', array( $html, $post ), '0.5.0', 'mokhai_markdown_source_html' );
		return $html;
	}

	/**
	 * Public re-runner used by the md-view-preview ability to produce a fresh
	 * deterministic conversion. Returns a fresh `Conversion_Result` for the
	 * post, or null if the post is no longer eligible for Markdown Views
	 * (module disabled, not exposable, walker rejected the input).
	 */
	public static function regenerate_conversion_for( \WP_Post $post ): ?Conversion_Result {
		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return null;
		}

		if ( ! Context_Profile_Settings::is_url_exposable( $post ) ) {
			return null;
		}

		return Walker::convert( self::render_source_html( $post ) );
	}

	/**
	 * Delete the cache row for a post. Invoked by the WordPress hooks above
	 * and exposed publicly so the REST / CLI / Tools admin can force a
	 * regeneration.
	 *
	 * @param int $post_id Post identifier.
	 */
	public static function invalidate( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Schema::table_name(),
			array( 'post_id' => $post_id ),
			array( '%d' )
		);
	}

	/**
	 * Compute the content hash used for cache validation.
	 *
	 * sha1 over the raw post_content + post_excerpt + post_modified_gmt +
	 * post_title. Cheap to compute (no `the_content` filter run required), and
	 * reliably bumps on every edit because WP updates `post_modified_gmt` on
	 * each save. `post_excerpt` is included because the source builder
	 * ({@see self::render_source_html()}) can draw from it — for WooCommerce
	 * products the short description IS `post_excerpt`, so an excerpt-only edit
	 * must invalidate the cached render (#252). Pathological case (a shortcode
	 * rendering different output without the post itself being saved) is
	 * covered by the walker-version bump path.
	 *
	 * Integrations that source the body from fields beyond post_content /
	 * post_excerpt extend the key via the `agentready_markdown_content_hash`
	 * filter so their edits invalidate too.
	 */
	private static function content_hash( \WP_Post $post ): string {
		$base = $post->post_content . $post->post_excerpt . $post->post_modified_gmt . $post->post_title;

		/**
		 * Filter the string hashed into the Markdown View cache key.
		 *
		 * Lets adapters that source body content from extra fields (product
		 * meta, ACF, etc.) append those values so an edit to them invalidates
		 * the cached `.md` render.
		 *
		 * @param string   $base The default hash input.
		 * @param \WP_Post $post The post being rendered.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$base = (string) \apply_filters( 'mokhai_markdown_content_hash', $base, $post );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$base = (string) \apply_filters_deprecated( 'agentready_markdown_content_hash', array( $base, $post ), '0.5.0', 'mokhai_markdown_content_hash' );

		return \sha1( $base );
	}

	/**
	 * Look up a cached row. Returns the markdown string on a valid hit, or
	 * null on miss / walker-version mismatch / hash mismatch.
	 */
	private static function read_cache( int $post_id, string $hash ): ?string {
		global $wpdb;

		$table = Schema::table_name();

		// Table name is interpolated from `Schema::table_name()`, which is
		// built from `$wpdb->prefix` plus a hardcoded suffix — trusted source,
		// not user input. Disabling the sniff for the SQL string + prepare
		// call only.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT markdown, walker_version FROM {$table} WHERE post_id = %d AND content_hash = %s",
				$post_id,
				$hash
			),
			\ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! \is_array( $row ) ) {
			return null;
		}

		// AgDR-0011: walker_version mismatch invalidates the row lazily. We
		// treat the read as a miss and let `get_markdown_for_post()` rewrite.
		if ( ( $row['walker_version'] ?? '' ) !== Walker::WALKER_VERSION ) {
			return null;
		}

		return (string) ( $row['markdown'] ?? '' );
	}

	/**
	 * Persist a freshly-generated MD row. `REPLACE` semantics: a row with
	 * the same `post_id` is overwritten in place (the PK is `post_id`).
	 * Stores the quality score and JSON-encoded signal map alongside the
	 * markdown per AgDR-0017 — these feed the cleanup-trigger decision
	 * and the admin "why did this trigger cleanup" panel without
	 * re-running the walker.
	 */
	private static function write_cache( int $post_id, string $hash, Conversion_Result $conversion ): void {
		global $wpdb;

		$signals_json = (string) \wp_json_encode( $conversion->get_signals() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			Schema::table_name(),
			array(
				'post_id'        => $post_id,
				'content_hash'   => $hash,
				'markdown'       => $conversion->get_markdown(),
				'generated_at'   => \current_time( 'mysql', true ),
				'walker_version' => Walker::WALKER_VERSION,
				'quality_score'  => $conversion->get_quality_score(),
				'signals'        => $signals_json,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
