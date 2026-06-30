<?php
/**
 * REST controller for the Context Score admin UI (#10 / AgDR-0031).
 *
 * Two routes under `ai-readiness-kit/v1/context-score/*`, both gated by
 * `manage_options`:
 *
 *   GET  /ai-readiness-kit/v1/context-score            — read cached breakdown
 *   POST /ai-readiness-kit/v1/context-score/recompute  — force synchronous recompute
 *
 * Both routes return the same payload shape — the breakdown emitted by
 * `Context_Score\Service` (and ultimately by `Context_Score\Engine`).
 * The UI can therefore refresh state from either response without
 * branching on which endpoint produced it.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Context_Score;

\defined( 'ABSPATH' ) || exit;

/**
 * Static REST controller. Mirrors the shape of
 * `LlmsTxt\Descriptions_Rest_Controller` so the controllers across
 * modules read uniformly.
 */
final class Rest_Controller {

	/**
	 * REST namespace shared with the rest of agentready.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'ai-readiness-kit/v1';

	/**
	 * Base path under the namespace. The recompute endpoint appends
	 * `/recompute`.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = '/context-score';

	/**
	 * Wire the registration hook. Called from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the two routes.
	 */
	public static function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			self::ROUTE_BASE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_read' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(),
			)
		);

		\register_rest_route(
			self::NAMESPACE,
			self::ROUTE_BASE . '/recompute',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_recompute' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Capability gate shared by both routes.
	 *
	 * Matches the rest of the admin REST surface — Context Profile,
	 * LLMs Index editorial, LLM descriptions. `manage_options` is the
	 * coarse-grained "site administrator" capability already required to
	 * see the Tools page that mounts the UI.
	 *
	 * @return bool|\WP_Error `true` when authorised, `WP_Error(403)` otherwise.
	 */
	public static function check_permission() {
		if ( \current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'agentready_context_score_forbidden',
			\__( 'You do not have permission to view the Context Score.', 'mokhai-agent-readiness-kit' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * GET handler. Returns the cached breakdown, recomputing on miss.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_read(): \WP_REST_Response {
		$payload = Service::get_breakdown();
		if ( null === $payload ) {
			$payload = Service::recompute_now();
		}

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * POST /recompute handler. Always recomputes synchronously.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_recompute(): \WP_REST_Response {
		$payload = Service::recompute_now();

		return new \WP_REST_Response( $payload, 200 );
	}
}
