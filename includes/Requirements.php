<?php
/**
 * Environment-requirements gate.
 *
 * Activation refusal on WP < 7.0 / PHP < 7.4, runtime degrade when the WP AI
 * Client is unconfigured, and the admin notices that explain both states.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext;

\defined( 'ABSPATH' ) || exit;

use WPContext\Ai\Client_Wrapper;

/**
 * Checks the host environment against the plugin's hard floor.
 */
final class Requirements {

	/**
	 * Whether the running WordPress version meets the hard floor.
	 */
	public static function meets_wp_floor(): bool {
		return \version_compare( \get_bloginfo( 'version' ), \WPCTX_REQUIRES_WP, '>=' );
	}

	/**
	 * Whether the running PHP version meets the hard floor.
	 */
	public static function meets_php_floor(): bool {
		return \version_compare( \PHP_VERSION, \WPCTX_REQUIRES_PHP, '>=' );
	}

	/**
	 * Whether the WP AI Client (shipped with WP core 7.0) is available
	 * and configured at runtime.
	 *
	 * Thin delegate so callers outside the Ai namespace don't have to
	 * reach into Client_Wrapper directly.
	 */
	public static function has_ai_client(): bool {
		return Client_Wrapper::has_ai_client();
	}

	/**
	 * Activation-time gate.
	 *
	 * Called from register_activation_hook BEFORE Main::on_activate writes
	 * the version option. Refuses activation with wp_die() if WP or PHP
	 * falls below the floor — see AgDR-0003 for the runtime/activation split.
	 */
	public static function check_activation(): void {
		if ( ! self::meets_php_floor() ) {
			\deactivate_plugins( \plugin_basename( \WPCTX_FILE ) );
			\wp_die(
				\sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					\esc_html__( 'WP Context requires PHP %1$s or higher. The current PHP version is %2$s. The plugin has not been activated.', 'wp-context' ),
					\esc_html( \WPCTX_REQUIRES_PHP ),
					\esc_html( \PHP_VERSION )
				),
				\esc_html__( 'Plugin activation error', 'wp-context' ),
				array( 'back_link' => true )
			);
		}

		if ( ! self::meets_wp_floor() ) {
			\deactivate_plugins( \plugin_basename( \WPCTX_FILE ) );
			\wp_die(
				\sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version */
					\esc_html__( 'WP Context requires WordPress %1$s or higher. The current WordPress version is %2$s. The plugin has not been activated.', 'wp-context' ),
					\esc_html( \WPCTX_REQUIRES_WP ),
					\esc_html( \get_bloginfo( 'version' ) )
				),
				\esc_html__( 'Plugin activation error', 'wp-context' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Runtime gate.
	 *
	 * Registers an admin notice if WP / PHP slid below the floor after the
	 * plugin was activated (the user downgraded core, switched servers, etc.).
	 * Bootstrapping code in wp-context.php returns early when this fires so
	 * no subsystem boots against an unsupported host.
	 */
	public static function register_runtime_notice(): void {
		\add_action( 'admin_notices', array( self::class, 'render_runtime_notice' ) );
	}

	/**
	 * Print the runtime version-floor admin notice.
	 */
	public static function render_runtime_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_ok  = self::meets_wp_floor();
		$php_ok = self::meets_php_floor();

		if ( $wp_ok && $php_ok ) {
			return;
		}

		$messages = array();
		if ( ! $php_ok ) {
			$messages[] = \sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				\esc_html__( 'PHP %1$s or higher is required. Current: %2$s.', 'wp-context' ),
				\esc_html( \WPCTX_REQUIRES_PHP ),
				\esc_html( \PHP_VERSION )
			);
		}
		if ( ! $wp_ok ) {
			$messages[] = \sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				\esc_html__( 'WordPress %1$s or higher is required. Current: %2$s.', 'wp-context' ),
				\esc_html( \WPCTX_REQUIRES_WP ),
				\esc_html( \get_bloginfo( 'version' ) )
			);
		}

		\printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			\esc_html__( 'WP Context is inactive:', 'wp-context' ),
			\esc_html( \implode( ' ', $messages ) )
		);
	}

	/**
	 * Soft-degrade notice: WP AI Client unconfigured.
	 *
	 * Less severe than the version gate — the plugin still functions, just
	 * with deterministic fallbacks. Only renders on the plugin's own admin
	 * screens to avoid noise on unrelated pages.
	 */
	public static function register_ai_client_notice(): void {
		\add_action( 'admin_notices', array( self::class, 'render_ai_client_notice' ) );
	}

	/**
	 * Print the unconfigured-AI-Client admin notice on plugin pages.
	 */
	public static function render_ai_client_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::has_ai_client() ) {
			return;
		}

		// Only show on WP Context admin screens. Screen IDs are added by
		// #4 (Profile screen) and #10 (Context Score Tools page); until they
		// land, this notice stays off-screen, which is the correct degrade
		// for the scaffold-only state.
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
		if ( null === $screen || false === \strpos( (string) $screen->id, 'wp-context' ) ) {
			return;
		}

		\printf(
			'<div class="notice notice-info"><p>%1$s</p></div>',
			\esc_html__( 'WP Context is running in deterministic-only mode. Configure WP AI Client to enable LLM-powered cleanup, descriptions, and score narratives.', 'wp-context' )
		);
	}
}
