<?php
/**
 * REST controller for the Context Profile SPA (#142 / AgDR-0048).
 *
 * Replaces the legacy `options.php` form POST with a no-reload REST write path
 * so the rebuilt Tools → Context screen saves via `apiFetch` instead of a full
 * page navigation. Two routes under `ai-readiness-kit/v1/context-profile`, both
 * gated by `manage_options`:
 *
 *   GET ai-readiness-kit/v1/context-profile  → { profile }
 *   PUT ai-readiness-kit/v1/context-profile  → { profile }   (persists, then returns)
 *
 * The write delegates to `Context_Profile_Settings::save()`, which routes the
 * payload through the same `sanitize_internal()` whitelist the form path used —
 * so the REST surface introduces no new validation logic and the
 * `agentready_context_profile_saved` cascade fires identically.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Static REST controller. Same shape conventions as
 * `LlmsTxt\Descriptions_Rest_Controller`.
 */
final class Context_Profile_Rest_Controller {

	/**
	 * REST namespace shared with the rest of agentready.
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
	 * Base path under the namespace.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = '/context-profile';

	/**
	 * Wire the registration hook. Called from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the read + write routes under the current namespace, plus legacy aliases.
	 */
	public static function register_routes(): void {
		$route_args = array(
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'handle_save' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			),
		);

		foreach ( array( self::NAMESPACE, self::LEGACY_NAMESPACE ) as $ns ) {
			\register_rest_route( $ns, self::ROUTE_BASE, $route_args );
		}
	}

	/**
	 * GET handler: return the current (migrated) profile so the SPA can
	 * refetch state without a page reload.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Unused.
	 */
	public static function handle_get( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response(
			array( 'profile' => Context_Profile_Settings::get_profile() ),
			200
		);
	}

	/**
	 * PUT handler: persist the whole profile, then return the saved value.
	 *
	 * The JSON body is the profile object. `Context_Profile_Settings::save()`
	 * applies the `sanitize_internal()` whitelist, so unknown keys and invalid
	 * CPTs/statuses are dropped — no validation lives here.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 */
	public static function handle_save( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! \is_array( $body ) ) {
			$body = array();
		}

		$saved = Context_Profile_Settings::save( $body );

		return new \WP_REST_Response( array( 'profile' => $saved ), 200 );
	}

	/**
	 * Capability gate. `manage_options` matches the rest of the Context
	 * Profile screen.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'You are not allowed to manage the Context Profile.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}
		return true;
	}
}
