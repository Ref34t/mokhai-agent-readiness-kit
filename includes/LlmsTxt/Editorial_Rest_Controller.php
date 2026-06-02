<?php
/**
 * REST controller for the LLMs Index editorial entries SPA (#142 / AgDR-0048).
 *
 * Replaces the legacy `options.php` form POST for the editorial repeater with a
 * no-reload REST write path. Two routes under
 * `ai-readiness-kit/v1/llms-txt/editorial`, both gated by `manage_options`:
 *
 *   GET ai-readiness-kit/v1/llms-txt/editorial  → { schema_version, entries, sections }
 *   PUT ai-readiness-kit/v1/llms-txt/editorial  → { schema_version, entries, sections }
 *
 * The write delegates to `Editorial_Settings::save()`, which routes the payload
 * through the same `sanitize()` source-of-truth the form path used — so the
 * REST surface adds no new validation, and the
 * `agentready_llms_txt_editorial_saved` cascade fires identically.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

\defined( 'ABSPATH' ) || exit;

/**
 * Static REST controller. Same shape conventions as
 * `Descriptions_Rest_Controller`.
 */
final class Editorial_Rest_Controller {

	/**
	 * REST namespace shared with the rest of agentready.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'ai-readiness-kit/v1';

	/**
	 * Base path under the namespace.
	 *
	 * @var string
	 */
	public const ROUTE_BASE = '/llms-txt/editorial';

	/**
	 * Wire the registration hook. Called from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the read + write routes on one URL with two methods.
	 */
	public static function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			self::ROUTE_BASE,
			array(
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
			)
		);
	}

	/**
	 * GET handler: return current editorial settings + the allowed section
	 * vocabulary so the SPA can render the section dropdown.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request Unused.
	 */
	public static function handle_get( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( self::projection(), 200 );
	}

	/**
	 * PUT handler: persist the editorial entries, then return the saved value.
	 *
	 * The JSON body carries `entries` (and an ignored `schema_version`).
	 * `Editorial_Settings::save()` applies the `sanitize()` whitelist — drops
	 * entries missing a title/URL, clamps sections, validates URL schemes.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request
	 */
	public static function handle_save( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! \is_array( $body ) ) {
			$body = array();
		}

		Editorial_Settings::save( $body );

		return new \WP_REST_Response( self::projection(), 200 );
	}

	/**
	 * Shared response projection: the saved settings plus the section
	 * vocabulary the UI needs. Used by GET and the PUT response so the SPA can
	 * refresh from a save reply without a second fetch.
	 *
	 * @return array<string, mixed>
	 */
	private static function projection(): array {
		$settings             = Editorial_Settings::get_settings();
		$settings['sections'] = Editorial_Settings::SECTIONS;
		return $settings;
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
				\__( 'You are not allowed to manage editorial entries.', 'ai-readiness-kit' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}
		return true;
	}
}
