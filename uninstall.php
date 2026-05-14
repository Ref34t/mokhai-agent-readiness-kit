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
 * Single source-of-truth list of option keys AgentReady owns.
 *
 * Kept in sync with the Context Profile storage shape (#4 / AgDR-002) and
 * the Markdown Views cache schema (#5 / AgDR-0011).
 */
$wpctx_options = array(
	'agentready_settings',
	'agentready_version',
	'agentready_md_cache_schema_version',
);

foreach ( $wpctx_options as $wpctx_option ) {
	delete_option( $wpctx_option );
	// Multisite: also clean up the site option in case the plugin
	// was activated network-wide. delete_site_option is a no-op on
	// single-site installs.
	delete_site_option( $wpctx_option );
}

unset( $wpctx_options, $wpctx_option );

/*
 * Drop the Markdown Views cache table on uninstall (#5 / AgDR-0011).
 *
 * Loaded through the plugin's Composer autoloader so the canonical table
 * name + drop logic lives in one place (Schema::drop). On multisite the
 * helper iterates every site so each per-site table is dropped.
 *
 * If the autoloader is missing (e.g. the user deleted vendor/ before
 * uninstalling) the schema-version option deletion above is enough to
 * keep WP from re-initialising; the orphan table is then dropped on a
 * future re-install via dbDelta's CREATE IF NOT EXISTS path being a
 * no-op against the existing table. We choose not to inline a raw
 * `DROP TABLE` here to avoid duplicating the table name in two places.
 */
$wpctx_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $wpctx_autoload ) ) {
	require_once $wpctx_autoload;
	\WPContext\Markdown_Views\Schema::drop_for_all_sites();
}

unset( $wpctx_autoload );
