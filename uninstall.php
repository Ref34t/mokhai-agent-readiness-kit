<?php
/**
 * Uninstall handler.
 *
 * Fires when the user explicitly deletes the plugin from wp-admin → Plugins
 * (not on deactivation). The admin has already confirmed deletion in the UI
 * before this file runs.
 *
 * All cleanup logic — option keys, post-meta, user-meta, transients, the
 * Markdown Views cache table, and the multisite sweep — lives in
 * `Mokhai\Support\Uninstaller`, where each key is referenced from the
 * constant on the class that owns the write. This file used to carry its own
 * literal key lists; they drifted from the real write sites and left the
 * Context Profile plus most post-meta behind on delete (#189).
 *
 * If the autoloader is missing (the user deleted vendor/ by hand before
 * uninstalling), the class can't load and cleanup is skipped — the same
 * graceful degradation the table drop already had. A broken install can be
 * cleaned manually; a wrong-list "successful" cleanup cannot be noticed.
 *
 * @package Mokhai
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpctx_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $wpctx_autoload ) ) {
	require_once $wpctx_autoload;
}
unset( $wpctx_autoload );

if ( class_exists( \Mokhai\Support\Uninstaller::class ) ) {
	\Mokhai\Support\Uninstaller::run();
}
