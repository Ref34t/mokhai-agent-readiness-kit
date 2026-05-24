<?php
/**
 * REST controller for the Markdown Views cleanup admin actions
 * (Phase B of `Ref34t/agentready#6`, per AgDR-0020).
 *
 * Four routes under the `ai-readiness-kit/v1` namespace drive the sidebar
 * UI's cleanup panel: read state, approve, reject, regenerate. All
 * gated by `edit_post` on the target post.
 *
 *   GET  /ai-readiness-kit/v1/markdown-views/cleanup?post=<id>
 *   POST /ai-readiness-kit/v1/markdown-views/cleanup/approve
 *   POST /ai-readiness-kit/v1/markdown-views/cleanup/reject
 *   POST /ai-readiness-kit/v1/markdown-views/cleanup/regenerate
 *
 * The mutation routes return the same shape as GET so the UI can
 * refresh state from the action response without a second fetch.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

use WPContext\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * Static REST controller. Mirrors the existing
 * `WPContext\Markdown_Views\Rest_Controller` registration shape.
 */
final class Cleanup_Rest_Controller {

	/**
	 * REST namespace shared with the rest of the agentready plugin.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'ai-readiness-kit/v1';

	/**
	 * GET route: read full cleanup state for a post.
	 *
	 * @var string
	 */
	public const ROUTE_STATE = '/markdown-views/cleanup';

	/**
	 * POST route: transition a `done` cleanup to `approved`.
	 *
	 * @var string
	 */
	public const ROUTE_APPROVE = '/markdown-views/cleanup/approve';

	/**
	 * POST route: transition a `done` cleanup to `rejected`.
	 *
	 * @var string
	 */
	public const ROUTE_REJECT = '/markdown-views/cleanup/reject';

	/**
	 * POST route: invalidate + reschedule cleanup for a post.
	 *
	 * @var string
	 */
	public const ROUTE_REGENERATE = '/markdown-views/cleanup/regenerate';

	/**
	 * Wire the registration hook. Called from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register all four routes.
	 */
	public static function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			self::ROUTE_STATE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_state' ),
				'permission_callback' => array( self::class, 'check_permission_from_query' ),
				'args'                => array(
					'post' => self::post_arg(),
				),
			)
		);

		foreach (
			array(
				self::ROUTE_APPROVE    => 'handle_approve',
				self::ROUTE_REJECT     => 'handle_reject',
				self::ROUTE_REGENERATE => 'handle_regenerate',
			) as $route => $callback
		) {
			\register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'check_permission_from_body' ),
					'args'                => array(
						'post_id' => self::post_arg(),
					),
				)
			);
		}
	}

	/**
	 * GET handler: return the full state blob.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_state( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post' ) );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		return new \WP_REST_Response( self::build_state_response( $post ), 200 );
	}

	/**
	 * POST handler: approve.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_approve( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post_id' ) );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		try {
			Cleanup_Orchestrator::approve( (int) $post->ID );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error(
				'cleanup_not_done',
				\esc_html( $e->getMessage() ),
				array( 'status' => 409 )
			);
		}

		return new \WP_REST_Response( self::build_state_response( $post ), 200 );
	}

	/**
	 * POST handler: reject.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_reject( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post_id' ) );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		try {
			Cleanup_Orchestrator::reject( (int) $post->ID );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error(
				'cleanup_not_done',
				\esc_html( $e->getMessage() ),
				array( 'status' => 409 )
			);
		}

		return new \WP_REST_Response( self::build_state_response( $post ), 200 );
	}

	/**
	 * POST handler: regenerate.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_regenerate( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post_id' ) );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		Cleanup_Orchestrator::regenerate( $post );

		return new \WP_REST_Response( self::build_state_response( $post ), 200 );
	}

	/**
	 * Permission gate for GET (post id in query string).
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission_from_query( \WP_REST_Request $request ) {
		return self::check_permission_for_post( (int) $request->get_param( 'post' ) );
	}

	/**
	 * Permission gate for POST (post id in body).
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission_from_body( \WP_REST_Request $request ) {
		return self::check_permission_for_post( (int) $request->get_param( 'post_id' ) );
	}

	/**
	 * Shared `edit_post` capability check.
	 *
	 * @return bool|\WP_Error
	 */
	private static function check_permission_for_post( int $post_id ) {
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'rest_invalid_post',
				\__( 'A valid post ID is required.', 'ai-readiness-kit' ),
				array( 'status' => 400 )
			);
		}

		if ( ! \current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You are not allowed to manage cleanup for this post.', 'ai-readiness-kit' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return new \WP_Error(
				Service::ERROR_MODULE_DISABLED,
				\__( 'Markdown Views is disabled in the Context Profile.', 'ai-readiness-kit' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate + resolve a post ID into a `WP_Post`, or return an
	 * error to surface as the REST response.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private static function resolve_post( int $post_id ) {
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'rest_post_not_found',
				\__( 'Post not found.', 'ai-readiness-kit' ),
				array( 'status' => 404 )
			);
		}
		return $post;
	}

	/**
	 * Standard arg-spec for a post-id parameter (used by both GET and
	 * POST routes).
	 *
	 * @return array<string, mixed>
	 */
	private static function post_arg(): array {
		return array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ): bool {
				return \is_numeric( $value ) && (int) $value > 0;
			},
		);
	}

	/**
	 * Build the unified state response — same shape for GET and for
	 * each action's response. Joins the orchestrator's post-meta state
	 * with the cache-table row's quality_score + signals + deterministic
	 * markdown so the UI gets everything in one round-trip.
	 *
	 * Read-only by contract: this function MUST NOT mutate state.
	 * Earlier versions delegated to `Service::get_markdown_for_post()`
	 * for the deterministic markdown, but that path's natural side
	 * effect is to schedule cleanup when `should_clean()` returns true
	 * — so every GET would flip a `done` cleanup back to `pending` on
	 * a site with cleanup enabled. We now read the cache row directly
	 * for both the markdown and the score/signals; if there's no cache
	 * row yet (post never read on the public route), deterministic
	 * returns empty and the UI shows just the cleanup state.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_state_response( \WP_Post $post ): array {
		$post_id = (int) $post->ID;

		$state = Cleanup_Orchestrator::get_state( $post_id );

		$deterministic = '';
		$quality_score = null;
		$signals       = null;

		$cache_row = self::cache_row_for_post( $post_id );
		if ( null !== $cache_row ) {
			if ( isset( $cache_row['markdown'] ) && \is_string( $cache_row['markdown'] ) ) {
				$deterministic = $cache_row['markdown'];
			}
			if ( isset( $cache_row['quality_score'] ) && \is_numeric( $cache_row['quality_score'] ) ) {
				$quality_score = (int) $cache_row['quality_score'];
			}
			if ( ! empty( $cache_row['signals'] ) && \is_string( $cache_row['signals'] ) ) {
				$decoded = \json_decode( $cache_row['signals'], true );
				if ( \is_array( $decoded ) ) {
					$signals = $decoded;
				}
			}
		}

		return array(
			'status'                 => $state['status'],
			'content_hash'           => $state['content_hash'],
			'deterministic_markdown' => $deterministic,
			'cleaned_markdown'       => $state['cleaned_markdown'],
			'diagnostics'            => $state['diagnostics'],
			'quality_score'          => $quality_score,
			'signals'                => $signals,
		);
	}

	/**
	 * Read the cache row for a post (markdown + quality_score + signals).
	 * Returns null on miss. Pure read; never mutates state.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function cache_row_for_post( int $post_id ): ?array {
		global $wpdb;

		$table = Schema::table_name();

		// Table name comes from a hardcoded suffix + $wpdb->prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT markdown, quality_score, signals FROM {$table} WHERE post_id = %d",
				$post_id
			),
			\ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return \is_array( $row ) ? $row : null;
	}
}
