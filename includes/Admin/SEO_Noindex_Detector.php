<?php
/**
 * SEO-plugin noindex detector — subscribes to `mokhai_post_is_noindexed`.
 *
 * Folds in #176 (closed): when a supported SEO plugin marks a post `noindex`,
 * Mokhai honours that signal and drops the post from every agent-facing
 * surface (the `noindex` gate in {@see Context_Profile_Settings::get_exposure_reason()}).
 * If a human told search engines "do not index this", agents should not ingest
 * it either.
 *
 * Read-only: reads the active SEO plugin's per-post robots state, never
 * writes. Yoast and Rank Math store it in post-meta (#180); AIOSEO v4 stores
 * it in its own `wp_aioseo_posts` table with a `robots_default` defer flag,
 * so that path is a guarded table read (#187).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Bridges the active SEO plugin's noindex meta into mokhai's exposure gate.
 *
 * Coordinates with {@see Schema_Coordination_Detector} for posture detection so
 * stale meta from a since-deactivated plugin can't leak a false noindex: only
 * the *currently active* plugin's meta is consulted.
 */
final class SEO_Noindex_Detector {

	/**
	 * Yoast per-post robots-noindex meta key. Value '1' = noindex, '2' = index,
	 * '' / '0' = follow the site-wide default (treated as index here).
	 *
	 * @var string
	 */
	private const YOAST_META_KEY = '_yoast_wpseo_meta-robots-noindex';

	/**
	 * Rank Math per-post robots meta key. Stored as an array of robots tokens;
	 * noindex when the array contains 'noindex'.
	 *
	 * @var string
	 */
	private const RANK_MATH_META_KEY = 'rank_math_robots';

	/**
	 * Wire the filter subscriber. Called from Main::register_hooks().
	 */
	public static function register_hooks(): void {
		\add_filter( 'mokhai_post_is_noindexed', array( self::class, 'filter_is_noindexed' ), 10, 2 );
	}

	/**
	 * Report whether the active SEO plugin marks this post noindex.
	 *
	 * Short-circuits to the incoming value when a higher-priority subscriber
	 * already decided noindex — mokhai never flips a true back to false.
	 *
	 * @param bool     $noindexed Current verdict from prior subscribers.
	 * @param \WP_Post $post      Post being evaluated.
	 */
	public static function filter_is_noindexed( bool $noindexed, \WP_Post $post ): bool {
		if ( $noindexed ) {
			return true;
		}

		switch ( Schema_Coordination_Detector::detect()['posture'] ) {
			case 'yoast':
				return '1' === (string) \get_post_meta( $post->ID, self::YOAST_META_KEY, true );

			case 'rank_math':
				$robots = \get_post_meta( $post->ID, self::RANK_MATH_META_KEY, true );
				return \is_array( $robots ) && \in_array( 'noindex', $robots, true );

			case 'aioseo':
				return self::aioseo_is_noindexed( $post->ID );

			default:
				return false;
		}
	}

	/**
	 * Whether the wp_aioseo_posts table exists, cached per request — the
	 * exposure gate runs once per entry on /llms.txt regen, and a SHOW TABLES
	 * round-trip per entry would be pure waste. Null = not yet checked.
	 *
	 * @var bool|null
	 */
	private static $aioseo_table_exists = null;

	/**
	 * Read the AIOSEO v4 per-post robots flags from its custom table.
	 *
	 * AIOSEO does not use post-meta: each post gets a row in
	 * `{$wpdb->prefix}aioseo_posts` carrying `robots_default` (truthy = defer
	 * to the plugin's global settings — treated as index here, matching how
	 * the Yoast branch treats the site-default meta value) and
	 * `robots_noindex` (truthy = explicit per-post noindex).
	 *
	 * Degrades safely: missing table (plugin active but migrations not run)
	 * or missing row (post never edited under AIOSEO) → false.
	 *
	 * @param int $post_id Post being evaluated.
	 */
	private static function aioseo_is_noindexed( int $post_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party table read; no WP API exists for it, the table name is bound via the %i identifier placeholder, and the per-request static is the cache.
		if ( null === self::$aioseo_table_exists ) {
			self::$aioseo_table_exists = $table === $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
		}

		if ( ! self::$aioseo_table_exists ) {
			return false;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT robots_default, robots_noindex FROM %i WHERE post_id = %d',
				$table,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $row || ! empty( $row->robots_default ) ) {
			return false;
		}

		return ! empty( $row->robots_noindex );
	}

	/**
	 * Reset the per-request table-existence cache. Test seam only — the cache
	 * would otherwise leak state across tests that create/drop the table.
	 */
	public static function reset_runtime_cache(): void {
		self::$aioseo_table_exists = null;
	}
}
