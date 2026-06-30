<?php
/**
 * One-shot data migration: sweep the dead Markdown Views cleanup post-meta.
 *
 * The Markdown Views LLM cleanup pass was retired in #153 (code removed in
 * PR #158). While it was live it wrote four post-meta keys per processed post;
 * those keys are now orphaned — no surviving code path reads or writes them.
 * This command deletes them. See AgDR-0050.
 *
 * Idempotent and forward-only: a second run finds nothing and reports zero.
 * Rollback is a no-op because the data is already inert.
 *
 * The sweep logic lives in the static `run()` so it is unit-testable without
 * WP-CLI and reusable if a future release wants to fire it from a versioned
 * upgrade routine (AgDR-0050 § Options, option B).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Cli;

\defined( 'ABSPATH' ) || exit;

/**
 * Sweep the orphaned `_agentready_md_cleanup_*` post-meta.
 *
 * ## EXAMPLES
 *
 *     # Delete the dead cleanup post-meta, reporting rows removed per key.
 *     $ wp mokhai cleanup-meta sweep
 *
 *     # Count what would be deleted without touching the database.
 *     $ wp mokhai cleanup-meta sweep --dry-run
 */
final class Cleanup_Meta_Migration_Command {

	/**
	 * The orphaned post-meta keys written by the retired cleanup pass.
	 *
	 * @var array<int, string>
	 */
	public const META_KEYS = array(
		'_agentready_md_cleanup_status',
		'_agentready_md_cleanup_hash',
		'_agentready_md_cleanup_output',
		'_agentready_md_cleanup_diagnostics',
	);

	/**
	 * Register the command. Guarded so it is a no-op outside WP-CLI.
	 */
	public static function register(): void {
		if ( ! \defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'mokhai cleanup-meta', self::class );
	}

	/**
	 * Sweep the dead cleanup post-meta keys.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count the rows that would be deleted for each key without deleting.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function sweep( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$counts  = self::run( $dry_run );

		$total = 0;
		foreach ( $counts as $key => $count ) {
			$total += $count;
			\WP_CLI::log( \sprintf( '%s: %d', $key, $count ) );
		}

		$verb = $dry_run ? 'would delete' : 'deleted';
		\WP_CLI::success( \sprintf( '%s %d orphaned cleanup post-meta row(s) across %d key(s).', \ucfirst( $verb ), $total, \count( self::META_KEYS ) ) );
	}

	/**
	 * Delete (or, when $dry_run, count) every orphaned cleanup post-meta key.
	 *
	 * Uses `delete_post_meta_by_key()` — the canonical WordPress API for
	 * removing a meta key across all objects, which also flushes the affected
	 * object-cache entries (unlike a raw `DELETE` on `wp_postmeta`).
	 *
	 * @param bool $dry_run When true, only count; do not delete.
	 *
	 * @return array<string, int> Map of meta key → rows found (deleted unless dry-run).
	 */
	public static function run( bool $dry_run = false ): array {
		global $wpdb;

		$results = array();

		foreach ( self::META_KEYS as $key ) {
			// Count first so we can report per-key totals — `delete_post_meta_by_key`
			// returns only a bool. Direct query against the core postmeta table by
			// an exact meta_key; not user input, no caching concern for a one-shot
			// maintenance sweep.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$key
				)
			);

			if ( ! $dry_run && $count > 0 ) {
				\delete_post_meta_by_key( $key );
			}

			$results[ $key ] = $count;
		}

		return $results;
	}
}
