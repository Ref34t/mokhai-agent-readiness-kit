<?php
/**
 * Plugin Name:       Mokhai - Agent Readiness Kit
 * Plugin URI:        https://github.com/Ref34t/mokhai-agent-readiness-kit
 * Description:       Help AI agents read your WordPress site correctly: llms.txt, clean Markdown views, structured data, and a readiness score — from one Context Profile.
 * Version:           0.4.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Mohamed Khaled
 * Author URI:        https://profiles.wordpress.org/mokhaled
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mokhai-agent-readiness-kit
 *
 * Translations: managed by wp.org under plugin slug 'mokhai-agent-readiness-kit'
 * (no manual textdomain loading needed since WP 4.6). See AgDR-0009 (slug value
 * updated per AgDR-0036 and the AI Readiness Kit rebrand, #101; renamed to
 * 'AgentReady' per AgDR-0059, #224; renamed to 'Agentable' per AgDR-0060, #230 —
 * the 'agentready' brand collided with the existing agentready.org; renamed to
 * 'Mokhai - Agent Readiness Kit' per AgDR-0062, #259 — wp.org review rejected
 * 'Agentable' as a third-party brand collision and required a distinctive
 * leading term that is clearly ours).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai;

\defined( 'ABSPATH' ) || exit;

/**
 * Define plugin-wide constants.
 *
 * Constants live in the global namespace by design so PHP code outside the
 * Mokhai\ namespace can reference them without imports.
 */
function constants(): void {
	\define( 'MOKHAI_VERSION', '0.4.0' );
	\define( 'MOKHAI_FILE', __FILE__ );
	\define( 'MOKHAI_DIR', \plugin_dir_path( __FILE__ ) );
	\define( 'MOKHAI_URL', \plugin_dir_url( __FILE__ ) );
	\define( 'MOKHAI_REQUIRES_PHP', '7.4' );
	\define( 'MOKHAI_REQUIRES_WP', '6.9' );
}

constants();

require_once \MOKHAI_DIR . 'vendor/autoload.php';

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
require_once \MOKHAI_DIR . 'includes/Ai/helpers.php';

Main::get_instance();
