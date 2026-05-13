<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Top-level plugin singleton.
 *
 * Owns the lifecycle hooks (activation / deactivation), wires the public
 * subsystems (Profile, Markdown, LlmsTxt, Audit, Admin, REST, CLI), and is
 * the single entry-point the main plugin file calls into.
 */
final class Main {

	/**
	 * Singleton instance.
	 *
	 * @var Main|null
	 */
	private static ?Main $instance = null;

	/**
	 * Return the singleton, constructing it on first call.
	 */
	public static function get_instance(): Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {}

	/**
	 * Wire the WordPress action / filter hooks owned by the plugin core.
	 *
	 * Subsystems (Profile, Markdown, LlmsTxt, Audit, Admin, REST, CLI) wire
	 * their own hooks from their own bootstraps — Main only owns plugin-level
	 * lifecycle and the i18n loader.
	 */
	private function register_hooks(): void {
		\register_activation_hook( \WPCTX_FILE, array( $this, 'on_activate' ) );
		\register_deactivation_hook( \WPCTX_FILE, array( $this, 'on_deactivate' ) );

		\add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Activation callback.
	 *
	 * Runs once when the plugin is activated. No schema changes here — the
	 * Context Profile (#4 / AgDR-002) stores settings in a single versioned
	 * wp_options entry. Future tickets that need a schema migration must
	 * file a migration ticket per `/migration` first.
	 */
	public function on_activate(): void {
		// Requirements gate is implemented in #2 — until then the scaffold
		// just records the installed version so #2 has something to read on
		// upgrade-path tests.
		if ( false === \get_option( 'wp_context_version' ) ) {
			\add_option( 'wp_context_version', \WPCTX_VERSION, '', false );
		} else {
			\update_option( 'wp_context_version', \WPCTX_VERSION, false );
		}
	}

	/**
	 * Deactivation callback.
	 *
	 * Clears transient caches; does not touch persistent options (those are
	 * removed by uninstall.php only). Re-activation should be cheap and
	 * lossless.
	 */
	public function on_deactivate(): void {
		// Transient cleanup hooks will be added by the modules that own them
		// (Markdown views, llms.txt generator, Context Score). No transients
		// exist yet in the scaffold.
	}

	/**
	 * Load the plugin's translations.
	 */
	public function load_textdomain(): void {
		\load_plugin_textdomain(
			'wp-context',
			false,
			\dirname( \plugin_basename( \WPCTX_FILE ) ) . '/languages'
		);
	}
}
