<?php
/**
 * REST controller for the Markdown Views admin preview endpoint.
 *
 * Registers `GET /wp-json/ai-readiness-kit/v1/markdown-views/preview?post=<id>`.
 * The Gutenberg sidebar (Phase 7) is the primary consumer; the WP-CLI
 * command (Phase 6) and any third-party admin tooling can use it too.
 *
 * The endpoint is **admin-only** — gated by `current_user_can('edit_post',
 * $post_id)`. Per AgDR-0015 the public route returns uniform 404 with no
 * body to never leak the denial reason; this endpoint, being behind the
 * edit-post capability, intentionally surfaces the reason so the editor
 * can fix the underlying Context Profile or post setting.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

use WPContext\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * Markdown Views REST controller.
 *
 * Response shape on 200:
 *
 *     {
 *         "markdown": string,           // empty when visibility.verdict === "not_exposable"
 *         "visibility": {
 *             "verdict": "exposable" | "not_exposable",
 *             "reason":  null | "cpt" | "status" | "password" | "noindex"
 *         },
 *         "cache_state": {
 *             "cached":         bool,   // true on cache hit, false on miss-then-regen
 *             "content_hash":   string, // sha1 used as the cache key
 *             "walker_version": string, // tag that invalidates on walker change
 *             "generated_at":   string  // ISO-8601 UTC timestamp
 *         } | null                      // null when post is not exposable (no row)
 *     }
 *
 * Response shape on 403 (module disabled):
 *
 *     { "code": "module_disabled", "message": "..." }
 */
final class Rest_Controller {

	/**
	 * REST namespace shared with the rest of the agentready plugin.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'ai-readiness-kit/v1';

	/**
	 * Route appended to the namespace.
	 *
	 * @var string
	 */
	public const ROUTE = '/markdown-views/preview';

	/**
	 * Wire the WordPress hooks owned by this class. Called from
	 * Main::register_hooks().
	 */
	public static function register_hooks(): void {
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the preview route.
	 */
	public static function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_preview' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'post' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return \is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Permission gate: must have `edit_post` on the specific post being
	 * previewed. The 403 here is "you're logged in but can't edit this
	 * post"; the 401 case (no auth) is handled by WP-REST's standard
	 * cookie-auth resolution.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Incoming request.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post' );

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'rest_invalid_post',
				\__( 'A valid post ID is required.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 400 )
			);
		}

		if ( ! \current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You are not allowed to preview this post.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Handle the preview request.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_preview( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post' );
		$post    = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'rest_post_not_found',
				\__( 'Post not found.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return new \WP_Error(
				Service::ERROR_MODULE_DISABLED,
				\__( 'Markdown Views is disabled in the Context Profile.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 403 )
			);
		}

		$reason = Context_Profile_Settings::get_exposure_reason( $post );

		if ( null !== $reason ) {
			return new \WP_REST_Response(
				array(
					'markdown'    => '',
					'visibility'  => array(
						'verdict' => 'not_exposable',
						'reason'  => $reason,
					),
					'cache_state' => null,
				),
				200
			);
		}

		$result = Service::get_markdown_for_post( $post );

		if ( \is_wp_error( $result ) ) {
			// Defence-in-depth: Service should not return WP_Error here since
			// we already gated on `is_module_enabled` and `get_exposure_reason`.
			// If it does (e.g. a race condition where the toggle flipped between
			// our check and Service's check), surface the structured error.
			return $result;
		}

		$cache_state = self::cache_state_for_post( $post_id );

		return new \WP_REST_Response(
			array(
				'markdown'    => $result,
				'visibility'  => array(
					'verdict' => 'exposable',
					'reason'  => null,
				),
				'cache_state' => $cache_state,
			),
			200
		);
	}

	/**
	 * Read the cache row for a post and shape it for the response. Returns
	 * null if no row exists (caller treats as "fresh regeneration just
	 * happened but the row hasn't materialised", which shouldn't happen on
	 * the success path but is handled defensively).
	 *
	 * @return array{cached:bool, content_hash:string, walker_version:string, generated_at:string}|null
	 */
	private static function cache_state_for_post( int $post_id ): ?array {
		global $wpdb;

		$table = Schema::table_name();

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
			return null;
		}

		return array(
			'cached'         => true,
			'content_hash'   => (string) $row['content_hash'],
			'walker_version' => (string) $row['walker_version'],
			'generated_at'   => self::format_timestamp( (string) $row['generated_at'] ),
		);
	}

	/**
	 * Convert MySQL DATETIME ("2026-05-14 12:18:43") to ISO-8601 UTC
	 * ("2026-05-14T12:18:43Z"). Cache rows are written via
	 * `current_time( 'mysql', true )` so the source value is already UTC.
	 */
	private static function format_timestamp( string $mysql_datetime ): string {
		if ( '' === $mysql_datetime ) {
			return '';
		}

		$normalized = \str_replace( ' ', 'T', $mysql_datetime ) . 'Z';
		return $normalized;
	}
}
