<?php
/**
 * Plugin Name:       WP Context
 * Plugin URI:        https://github.com/Ref34t/wp-context
 * Description:       Agent Readiness for WordPress: context, policy, audit, analytics. Makes WP sites readable, discoverable, governable, and measurable for AI agents.
 * Version:           0.1.0-dev
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Anonymous
 * Author URI:        https://author.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-context
 * Domain Path:       /languages
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Define plugin-wide constants.
 *
 * Constants live in the global namespace by design so PHP code outside the
 * WPContext\ namespace can reference them without imports.
 */
function constants(): void {
	\define( 'WPCTX_VERSION', '0.1.0-dev' );
	\define( 'WPCTX_FILE', __FILE__ );
	\define( 'WPCTX_DIR', \plugin_dir_path( __FILE__ ) );
	\define( 'WPCTX_URL', \plugin_dir_url( __FILE__ ) );
	\define( 'WPCTX_REQUIRES_PHP', '7.4' );
	\define( 'WPCTX_REQUIRES_WP', '7.0' );
}

constants();

require_once \WPCTX_DIR . 'vendor/autoload.php';

// Runtime version-floor gate. Activation refusal lives in Requirements::check_activation
// (wired through Main::on_activate); this branch handles the case where WP or PHP
// fell below the floor AFTER activation — show an admin notice and don't boot.
if ( ! Requirements::meets_wp_floor() || ! Requirements::meets_php_floor() ) {
	Requirements::register_runtime_notice();
	return;
}

Main::get_instance();
