<?php
/**
 * Plugin Name:       AI Readiness Kit
 * Plugin URI:        https://github.com/Ref34t/agentready
 * Description:       AI Readiness for WordPress: context, policy, audit, analytics. Makes sites readable, discoverable, governable, and measurable for AI agents.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Mohamed Khaled
 * Author URI:        https://profiles.wordpress.org/mokhaled
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-readiness-kit
 *
 * Translations: managed by wp.org under plugin slug 'ai-readiness-kit' (no manual
 * textdomain loading needed since WP 4.6). See AgDR-0009 (slug value updated
 * per AgDR-0036 and the AI Readiness Kit rebrand, #101).
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
	\define( 'WPCTX_VERSION', '0.2.0' );
	\define( 'WPCTX_FILE', __FILE__ );
	\define( 'WPCTX_DIR', \plugin_dir_path( __FILE__ ) );
	\define( 'WPCTX_URL', \plugin_dir_url( __FILE__ ) );
	\define( 'WPCTX_REQUIRES_PHP', '7.4' );
	\define( 'WPCTX_REQUIRES_WP', '6.9' );
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

// Load global-namespace helpers. Done here (not via Composer's `files:`
// autoload) so PHPUnit / dev-tool autoload chains don't trip the `defined(
// 'ABSPATH' ) || exit;` guard inside the helper file before they've had a
// chance to define ABSPATH. See #15 and the test bootstrap for the
// non-WP loading paths.
require_once \WPCTX_DIR . 'includes/Ai/helpers.php';

Main::get_instance();
