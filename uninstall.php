<?php
/**
 * Uninstall handler.
 *
 * Fires when the user explicitly deletes the plugin from wp-admin → Plugins
 * (not on deactivation). Removes plugin options behind the standard WordPress
 * uninstall flow — the admin has already confirmed deletion in the UI before
 * this file runs.
 *
 * @package WPContext
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Single source-of-truth list of option keys WP Context owns.
 *
 * Kept in sync with the Context Profile storage shape (#4 / AgDR-002).
 */
$wpctx_options = array(
	'wp_context_settings',
	'wp_context_version',
);

foreach ( $wpctx_options as $wpctx_option ) {
	delete_option( $wpctx_option );
	// Multisite: also clean up the site option in case the plugin
	// was activated network-wide. delete_site_option is a no-op on
	// single-site installs.
	delete_site_option( $wpctx_option );
}

unset( $wpctx_options, $wpctx_option );
