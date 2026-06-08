<?php
/**
 * SEO-plugin noindex detector — subscribes to `agentready_post_is_noindexed`.
 *
 * Folds in #176 (closed): when a supported SEO plugin marks a post `noindex`,
 * agentready honours that signal and drops the post from every agent-facing
 * surface (the `noindex` gate in {@see Context_Profile_Settings::get_exposure_reason()}).
 * If a human told search engines "do not index this", agents should not ingest
 * it either.
 *
 * Read-only: reads the active SEO plugin's per-post meta, never writes. v1
 * supports the two post-meta-based plugins (Yoast, Rank Math). AIOSEO stores
 * robots flags in its own `wp_aioseo_posts` table rather than post-meta and is
 * deferred to a follow-up — see AgDR-0055 § "AIOSEO deferral".
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Bridges the active SEO plugin's noindex meta into agentready's exposure gate.
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
		\add_filter( 'agentready_post_is_noindexed', array( self::class, 'filter_is_noindexed' ), 10, 2 );
	}

	/**
	 * Report whether the active SEO plugin marks this post noindex.
	 *
	 * Short-circuits to the incoming value when a higher-priority subscriber
	 * already decided noindex — agentready never flips a true back to false.
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

			// 'aioseo' deferred — robots flags live in the wp_aioseo_posts
			// custom table, not post-meta. See AgDR-0055.
			default:
				return false;
		}
	}
}
