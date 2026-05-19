<?php
/**
 * Uninstall handler.
 *
 * Fires when the user explicitly deletes the plugin from wp-admin → Plugins
 * (not on deactivation). Removes plugin options behind the standard WordPress
 * uninstall flow — the admin has already confirmed deletion in the UI before
 * this file runs.
 *
 * Multisite: per-site options live in each site's own `wp_options`, so we
 * iterate `get_sites()` and `switch_to_blog()` for each site before deleting.
 * `delete_site_option` writes to the network's `wp_sitemeta`, which is a
 * different surface — it's called separately for any options that were
 * stored network-wide via `add_site_option()`. See AgDR-0022 § "Uninstall
 * behaviour" and the Markdown_Views\Schema multisite pattern.
 *
 * @package WPContext
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpctx_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $wpctx_autoload ) ) {
	require_once $wpctx_autoload;
}
unset( $wpctx_autoload );

/**
 * Single source-of-truth list of option keys AgentReady owns.
 *
 * Kept in sync with the Context Profile storage shape (#4 / AgDR-002),
 * the Markdown Views cache schema (#5 / AgDR-0011), the LLMs Index
 * cache + editorial entries (#7 / AgDR-0022), and the Context Score
 * cache (#9 / AgDR-0030).
 */
$wpctx_options = array(
	'agentready_settings',
	'agentready_version',
	'agentready_md_cache_schema_version',
	'agentready_llms_txt_cache',
	'agentready_llms_txt_editorial',
	'agentready_context_score_cache',
);

/*
 * Per-site cleanup tasks. Run once per site on multisite; once on
 * single-site. `delete_post_meta_by_key` and `delete_transient`
 * operate against the current site context, which is why the loop
 * has to do the switching rather than relying on a single network-
 * scope sweep.
 */
$wpctx_cleanup_meta_keys = array(
	'_agentready_md_cleanup_output',
	'_agentready_md_cleanup_diagnostics',
	'_agentready_md_cleanup_status',
	'_agentready_md_cleanup_hash',
);

$wpctx_per_site_cleanup = static function () use ( $wpctx_options, $wpctx_cleanup_meta_keys ): void {
	foreach ( $wpctx_options as $option_key ) {
		delete_option( $option_key );
	}
	foreach ( $wpctx_cleanup_meta_keys as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}
	// Drop the LLMs Index regen-lock transient (#7 / AgDR-0022). Stale
	// lock on uninstall is benign (the cache option is already deleted
	// above) but explicit cleanup keeps the wp_options footprint zero
	// after uninstall.
	delete_transient( 'agentready_llms_txt_regen_lock' );
};

if ( is_multisite() ) {
	/** @var array<int,int> $wpctx_site_ids */
	$wpctx_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $wpctx_site_ids as $wpctx_site_id ) {
		switch_to_blog( (int) $wpctx_site_id );
		$wpctx_per_site_cleanup();
		restore_current_blog();
	}
	unset( $wpctx_site_ids, $wpctx_site_id );
} else {
	$wpctx_per_site_cleanup();
}

/*
 * Network-wide site options live in wp_sitemeta, not per-site wp_options.
 * On single-site installs delete_site_option() is a no-op. On multisite
 * we still call it once (not inside the per-site loop) because the
 * network option exists in exactly one place.
 */
foreach ( $wpctx_options as $wpctx_option ) {
	delete_site_option( $wpctx_option );
}

unset( $wpctx_options, $wpctx_option, $wpctx_per_site_cleanup, $wpctx_cleanup_meta_keys );

/*
 * Drop the Markdown Views cache table on uninstall (#5 / AgDR-0011).
 *
 * The Schema helper iterates `get_sites()` internally on multisite, so
 * this call stays outside our per-site loop above to avoid double-drop.
 *
 * If the autoloader was missing (e.g. the user deleted vendor/ before
 * uninstalling) we skipped requiring it above — the per-site
 * `agentready_md_cache_schema_version` option deletion is then enough
 * to keep WP from re-initialising; an orphan table is dropped on a
 * future re-install via `dbDelta`'s `CREATE IF NOT EXISTS` path being a
 * no-op against the existing table.
 */
if ( class_exists( \WPContext\Markdown_Views\Schema::class ) ) {
	\WPContext\Markdown_Views\Schema::drop_for_all_sites();
}
