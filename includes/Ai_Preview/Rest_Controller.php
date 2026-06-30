<?php
/**
 * REST controller for the AI Assistant Preview pane (#45 / AgDR-0046).
 *
 * Three admin-only routes under `ai-readiness-kit/v1/ai-preview`:
 *
 *   GET  /ai-preview/posts            — selectable posts for the URL dropdown
 *   GET  /ai-preview/preview?post=    — the three panes + cached summary
 *   POST /ai-preview/summary?post=    — (re)generate + cache the Sample AI Summary
 *
 * Every route is gated by `manage_options` — this is a buyer-demonstration
 * surface on the Context Score Tools page, not a per-post editor tool, so it
 * matches the Context Score page's capability rather than the Markdown Views
 * preview endpoint's `edit_post`. No public-site surface (AgDR-0046).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Ai_Preview;

\defined( 'ABSPATH' ) || exit;

/**
 * Routes + permission gate + thin transport over Preview_Builder /
 * Summary_Generator. The aggregation and LLM logic live in those classes;
 * this controller only validates input and shapes responses.
 */
final class Rest_Controller {

	/**
	 * REST namespace shared with the rest of the plugin.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'mokhai/v1';

	/**
	 * Legacy REST namespace kept for back-compat (deprecated since 0.5.0, use `mokhai/v1`).
	 *
	 * @var string
	 */
	private const LEGACY_NAMESPACE = 'ai-readiness-kit/v1';

	/**
	 * Route base appended to the namespace.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = '/ai-preview';

	/**
	 * Default page size for the post dropdown.
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Hard cap on page size.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Wire WordPress hooks. Called from Main::register_hooks.
	 */
	public static function register_hooks(): void {
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the three routes under the current namespace, plus legacy aliases.
	 */
	public static function register_routes(): void {
		$routes = array(
			array(
				'path' => self::ROUTE_BASE . '/posts',
				'args' => array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_posts' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'search'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => self::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			array(
				'path' => self::ROUTE_BASE . '/preview',
				'args' => array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_preview' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'post' => self::post_id_arg(),
					),
				),
			),
			array(
				'path' => self::ROUTE_BASE . '/summary',
				'args' => array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_summary' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'post' => self::post_id_arg(),
					),
				),
			),
		);

		foreach ( array( self::NAMESPACE, self::LEGACY_NAMESPACE ) as $ns ) {
			foreach ( $routes as $route ) {
				\register_rest_route( $ns, $route['path'], $route['args'] );
			}
		}
	}

	/**
	 * Capability gate. `manage_options` — same as the Context Score page
	 * this panel lives on.
	 *
	 * @return bool
	 */
	public static function check_permission(): bool {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * GET /ai-preview/posts — paginated, optionally-searched list of
	 * selectable posts across all public post types.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Incoming request.
	 */
	public static function handle_posts( \WP_REST_Request $request ): \WP_REST_Response {
		$search   = (string) $request->get_param( 'search' );
		$page     = \max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}
		$per_page = \min( $per_page, self::MAX_PER_PAGE );

		$post_types = self::selectable_post_types();

		$query = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				's'                      => '' !== $search ? $search : '',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$posts[] = Preview_Builder::post_summary( $post );
		}

		\wp_reset_postdata();

		return new \WP_REST_Response(
			array(
				'posts'    => $posts,
				'total'    => (int) $query->found_posts,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * GET /ai-preview/preview?post= — the three panes + cached summary.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_preview( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post' ) );
		if ( \is_wp_error( $post ) ) {
			return $post;
		}

		return new \WP_REST_Response( Preview_Builder::build( $post ), 200 );
	}

	/**
	 * POST /ai-preview/summary?post= — (re)generate the Sample AI Summary.
	 *
	 * Resolves the Markdown View through the same gating the pane uses, then
	 * hands it to Summary_Generator. When there's no markdown to summarise
	 * (module disabled, post not exposable, empty conversion) the generator's
	 * `empty_input` degrade is returned rather than an error — the UI shows a
	 * hint, not a failure.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_summary( \WP_REST_Request $request ) {
		$post = self::resolve_post( (int) $request->get_param( 'post' ) );
		if ( \is_wp_error( $post ) ) {
			return $post;
		}

		$markdown_pane = Preview_Builder::markdown_for( $post );
		$markdown      = isset( $markdown_pane['markdown'] ) ? (string) $markdown_pane['markdown'] : '';

		$result = Summary_Generator::generate( (int) $post->ID, $markdown );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Post types offered in the dropdown: every public type except
	 * attachments (media has no agent-readable body worth previewing).
	 *
	 * @return array<int, string>
	 */
	private static function selectable_post_types(): array {
		$types = \get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		return \array_values( \array_map( 'strval', $types ) );
	}

	/**
	 * Resolve a post ID to a WP_Post, or a 404 WP_Error.
	 *
	 * @param int $post_id Post ID from the request.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private static function resolve_post( int $post_id ) {
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'rest_post_not_found',
				\__( 'Post not found.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Shared arg spec for the required `post` parameter.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_id_arg(): array {
		return array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ): bool {
				return \is_numeric( $value ) && (int) $value > 0;
			},
		);
	}
}
