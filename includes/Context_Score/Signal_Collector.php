<?php
/**
 * WP-side signal collector for the Context Score (#9 / AgDR-0030).
 *
 * Bridges WordPress state (Context Profile, /llms.txt cache, MD cache
 * aggregates, SEO plugin posture, AI client config) into the pure
 * `Engine`'s input shape. Every WP call lives here so the engine itself
 * has zero WordPress dependencies and can be unit-tested standalone.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Context_Score;

use WPContext\Admin\Context_Profile_Settings;
use WPContext\Admin\Multi_Channel_Provider_Detector;
use WPContext\Admin\Schema_Coordination_Detector;
use WPContext\Ai\Client_Wrapper;
use WPContext\Discovery\Channel_Router;
use WPContext\LlmsTxt\Conflict_Detector;
use WPContext\LlmsTxt\Entry_Source;
use WPContext\LlmsTxt\Service as Llms_Txt_Service;
use WPContext\Markdown_Views\Schema as Md_Schema;
use WPContext\Markdown_Views\Walker;

\defined( 'ABSPATH' ) || exit;

/**
 * Gather the signal bundle that `Engine::compute()` expects.
 *
 * One public entry point — `Signal_Collector::collect(): array`. Returns the
 * shape documented in AgDR-0030 § "Pure engine pattern".
 */
final class Signal_Collector {

	/**
	 * Trimmed-body length (chars) below which a cached `.md` body is treated
	 * as empty/near-empty for the Context Score body-quality sampling (#255,
	 * AgDR-0064). Sized to catch 0-byte and header-only bodies while letting
	 * a genuine one-line answer through.
	 *
	 * @var int
	 */
	public const MIN_BODY_CHARS = 48;

	/**
	 * Non-prose char fraction above which a sampled body is "noise-dominated"
	 * (encoded builder blobs / leaked script). See AgDR-0064.
	 *
	 * @var float
	 */
	public const MAX_NOISE_RATIO = 0.30;

	/**
	 * Upper bound on cached bodies read per audit for the empty/noise rate
	 * estimate. Bounds the LONGTEXT reads regardless of cache size (no
	 * all-rows scan). See AgDR-0064.
	 *
	 * @var int
	 */
	public const SAMPLE_LIMIT = 50;

	/**
	 * Number of worst-quality failing URLs named in the score narrative (AC4
	 * of #255). Resolved in one batched `get_posts( post__in )` lookup.
	 *
	 * @var int
	 */
	public const WORST_URL_LIMIT = 5;

	/**
	 * Build the signals array from current WP state.
	 *
	 * Safe to call repeatedly — the MD aggregate SQL query is a single
	 * indexed aggregate against the cache table, and every other read is
	 * either the options cache (Context Profile, LLMs Index cache) or a
	 * cheap function-exists check (AI client).
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		$profile  = Context_Profile_Settings::get_profile();
		$llms_txt = self::llms_txt_signals();

		return array(
			'profile'                 => self::profile_signals( $profile ),
			'llms_txt'                => $llms_txt,
			'md_cache'                => self::md_cache_signals( Engine::MD_QUALITY_THRESHOLD ),
			'schema'                  => self::schema_signals(),
			'ai_client'               => self::ai_client_signals(),
			'descriptions'            => self::description_signals(),
			'multi_channel_discovery' => self::multi_channel_signals( (bool) ( $llms_txt['cache_populated'] ?? false ) ),
		);
	}

	/**
	 * Reduce the Context Profile to the slice the engine reads.
	 *
	 * @param array<string, mixed> $profile
	 *
	 * @return array<string, mixed>
	 */
	private static function profile_signals( array $profile ): array {
		$cpts     = isset( $profile['exposed_cpts'] ) && is_array( $profile['exposed_cpts'] )
			? array_values( array_map( 'strval', $profile['exposed_cpts'] ) )
			: array();
		$statuses = isset( $profile['exposed_statuses'] ) && is_array( $profile['exposed_statuses'] )
			? array_values( array_map( 'strval', $profile['exposed_statuses'] ) )
			: array( 'publish' );

		return array(
			'exposed_cpts'             => $cpts,
			'exposed_statuses'         => $statuses,
			'llm_descriptions_enabled' => (bool) ( $profile['llm_descriptions_enabled'] ?? false ),
		);
	}

	/**
	 * Resolve the public web root where root-served agent files live. On a
	 * subdirectory install this differs from ABSPATH (the WP core dir);
	 * `get_home_path()` returns the document root for both layouts.
	 */
	private static function web_root(): string {
		if ( ! \function_exists( 'get_home_path' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
		}
		return \get_home_path();
	}

	/**
	 * Read the cached /llms.txt payload + conflict detection.
	 *
	 * @return array<string, mixed>
	 */
	private static function llms_txt_signals(): array {
		$cache = Llms_Txt_Service::get_cache_payload();

		return array(
			// "Populated" means the index carries discoverable ENTRIES — keyed
			// off entry_count, not body length. Since #244 an empty-content
			// site still emits a non-blank identity-header body, so a body-
			// length check would falsely read as populated and over-credit the
			// discoverability sub-score.
			'cache_populated'   => null !== $cache && isset( $cache['entry_count'] ) && (int) $cache['entry_count'] > 0,
			'entry_count'       => null !== $cache && isset( $cache['entry_count'] )
				? (int) $cache['entry_count']
				: 0,
			'body_bytes'        => null !== $cache && isset( $cache['body'] )
				? strlen( (string) $cache['body'] )
				: 0,
			'conflicts'         => Conflict_Detector::detect(),
			// A static robots.txt at the web root bypasses WordPress's
			// `robots_txt` filter, so Discovery\Alternate_Advertiser can't add
			// the /llms.txt reference there (AgDR-0053 limitation, #245). Probed
			// from get_home_path() — the public root, not ABSPATH.
			'static_robots_txt' => \file_exists( self::web_root() . 'robots.txt' ),
			'llms_txt_url'      => \home_url( '/llms.txt' ),
		);
	}

	/**
	 * Aggregate the Markdown Views cache table in one indexed query.
	 *
	 * Returns zero-counts when the table doesn't exist yet (fresh activation,
	 * pre-#5 install).
	 *
	 * @return array<string, mixed>
	 */
	private static function md_cache_signals( int $threshold ): array {
		global $wpdb;

		$table = Md_Schema::table_name();

		// Defensive: if the cache table hasn't been provisioned, return
		// zero-counts rather than letting wpdb emit a "no such table" warning.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $table_exists ) {
			return array(
				'rows_total'           => 0,
				'rows_with_score'      => 0,
				'mean_quality'         => 0.0,
				'rows_above_threshold' => 0,
				'md_quality_threshold' => $threshold,
			);
		}

		// One indexed aggregate over the cache table. `quality_score` is
		// nullable on rows written before AgDR-0017 — those count toward
		// `rows_total` but not `rows_with_score`. The walker-version bump
		// invalidates pre-#6 rows on next read, so the null state is
		// transient.
		//
		// Table name is interpolated from `Markdown_Views\Schema::table_name()`,
		// which is built from `$wpdb->prefix` plus a hardcoded suffix —
		// trusted source, not user input. Same disable-pattern used by
		// `Markdown_Views\Service::read_cache()` (AgDR-0011).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS rows_total,
					SUM(CASE WHEN quality_score IS NOT NULL THEN 1 ELSE 0 END) AS rows_with_score,
					AVG(quality_score) AS mean_quality,
					SUM(CASE WHEN quality_score IS NOT NULL AND quality_score >= %d THEN 1 ELSE 0 END) AS rows_above_threshold
				FROM {$table}",
				$threshold
			),
			\ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $row ) ) {
			return array(
				'rows_total'           => 0,
				'rows_with_score'      => 0,
				'mean_quality'         => 0.0,
				'rows_above_threshold' => 0,
				'md_quality_threshold' => $threshold,
			);
		}

		$aggregate = array(
			'rows_total'           => (int) ( $row['rows_total'] ?? 0 ),
			'rows_with_score'      => (int) ( $row['rows_with_score'] ?? 0 ),
			'mean_quality'         => (float) ( $row['mean_quality'] ?? 0.0 ),
			'rows_above_threshold' => (int) ( $row['rows_above_threshold'] ?? 0 ),
			'md_quality_threshold' => $threshold,
		);

		// #255: sample the rendered bodies (not just the aggregate scores) so
		// empty/noise output is detected. Bounded read — never an all-rows scan.
		return array_merge( $aggregate, self::md_body_quality_signals( $table ) );
	}

	/**
	 * Sample cached Markdown bodies to estimate the empty/noise rate and name
	 * the worst-quality failing URLs (#255, AgDR-0064).
	 *
	 * Two bounded queries — no all-rows LONGTEXT scan:
	 *   1. Rate sample — up to SAMPLE_LIMIT rows ordered by `post_id` (a
	 *      representative, deterministic, *unbiased* slice). Each body is
	 *      classified empty / noisy in PHP; the ratios are sample-wide.
	 *   2. Worst-URL list — the WORST_URL_LIMIT lowest-quality rows' post_ids,
	 *      with titles/permalinks resolved in ONE batched `get_posts()`.
	 *
	 * @param string $table Fully-qualified cache table name (trusted source).
	 *
	 * @return array<string, mixed>
	 */
	private static function md_body_quality_signals( string $table ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sample = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, markdown FROM {$table} ORDER BY post_id ASC LIMIT %d",
				self::SAMPLE_LIMIT
			),
			\ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $sample ) || array() === $sample ) {
			return array(
				'sampled'     => 0,
				'empty_ratio' => 0.0,
				'noisy_ratio' => 0.0,
				'worst_urls'  => array(),
			);
		}

		$sampled = 0;
		$empty   = 0;
		$noisy   = 0;

		foreach ( $sample as $r ) {
			$body = (string) ( $r['markdown'] ?? '' );
			++$sampled;
			if ( self::body_is_empty( $body ) ) {
				++$empty;
			} elseif ( self::body_is_noisy( $body ) ) {
				// Empty takes precedence — an empty body isn't "noisy".
				++$noisy;
			}
		}

		// $sampled is guaranteed >= 1 here — the empty-sample case returned early.
		// Cast to float: PHP's `/` yields an int when evenly divisible (0/2 → 0),
		// and the ratio contract is a float in [0.0, 1.0].
		return array(
			'sampled'     => $sampled,
			'empty_ratio' => (float) ( $empty / $sampled ),
			'noisy_ratio' => (float) ( $noisy / $sampled ),
			'worst_urls'  => self::worst_failing_urls( $table ),
		);
	}

	/**
	 * A body is empty/near-empty when its trimmed length is below
	 * MIN_BODY_CHARS — the #252 symptom (0-byte / header-only output).
	 */
	private static function body_is_empty( string $body ): bool {
		return self::mb_len( trim( $body ) ) < self::MIN_BODY_CHARS;
	}

	/**
	 * A body is noise-dominated when the fraction of its characters belonging
	 * to non-prose runs exceeds MAX_NOISE_RATIO — the #253 symptom. The
	 * non-prose patterns reuse `Walker::BUILDER_BLOB_MIN_LEN` (the same
	 * constant the #253 stripper uses) so the score and the renderer cannot
	 * drift.
	 */
	private static function body_is_noisy( string $body ): bool {
		$total = self::mb_len( $body );
		if ( $total <= 0 ) {
			return false;
		}

		$min_blob = (int) Walker::BUILDER_BLOB_MIN_LEN;
		$noise    = 0;

		// Long base64 builder-blob runs.
		if ( preg_match_all( '/[A-Za-z0-9+\/]{' . $min_blob . ',}={0,2}/', $body, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$noise += self::mb_len( $hit );
			}
		}
		// Leaked script/style residue + slider init tokens.
		if ( preg_match_all( '/<\/?(?:script|style)\b[^>]*>|setREVStartSize\s*\([^)]*\)/i', $body, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$noise += self::mb_len( $hit );
			}
		}
		// Surviving shortcode-residue tokens.
		if ( preg_match_all( '/\[[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', $body, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$noise += self::mb_len( $hit );
			}
		}

		return ( $noise / $total ) > self::MAX_NOISE_RATIO;
	}

	/**
	 * Resolve the WORST_URL_LIMIT lowest-quality cached posts to a display
	 * list. One indexed query for the ids, one batched `get_posts()` for the
	 * titles/permalinks — no per-URL N+1.
	 *
	 * @param string $table Fully-qualified cache table name (trusted source).
	 *
	 * @return array<int, array{post_id:int, title:string, url:string}>
	 */
	private static function worst_failing_urls( string $table ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE quality_score IS NOT NULL ORDER BY quality_score ASC, post_id ASC LIMIT %d",
				self::WORST_URL_LIMIT
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$posts = \get_posts(
			array(
				'post__in'               => $ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => self::WORST_URL_LIMIT,
				'post_type'              => 'any',
				'post_status'            => 'any',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'post_id' => (int) $post->ID,
				'title'   => (string) \get_the_title( $post ),
				'url'     => (string) \get_permalink( $post ),
			);
		}

		return $out;
	}

	/**
	 * Multibyte-safe string length with a single-byte fallback when the
	 * mbstring extension isn't loaded.
	 */
	private static function mb_len( string $s ): int {
		return \function_exists( 'mb_strlen' ) ? (int) mb_strlen( $s ) : strlen( $s );
	}

	/**
	 * Resolve the SEO plugin posture. `null` when no recognised plugin is
	 * active (so the engine's `seo_plugin` signal stays empty string instead
	 * of the literal `'none'` slug — keeps fixture comparisons simpler).
	 *
	 * @return array<string, mixed>
	 */
	private static function schema_signals(): array {
		$detect  = Schema_Coordination_Detector::detect();
		$slug    = (string) ( $detect['posture'] ?? Schema_Coordination_Detector::POSTURE_NONE );
		$profile = Context_Profile_Settings::get_profile();

		return array(
			'seo_plugin'          => Schema_Coordination_Detector::POSTURE_NONE === $slug ? '' : $slug,
			// Native JSON-LD emission opt-in (#73 / AgDR-0034). When true, the
			// score engine credits schema_coverage even with no SEO plugin
			// active. Read live from the Profile rather than persisted in
			// `seo_plugin`'s slug space so the two signals stay independent.
			'native_emit_enabled' => ! empty( $profile['schema_emit_enabled'] ),
		);
	}

	/**
	 * Resolve AI client posture. `has_ai_client()` is the same probe the
	 * existing degrade-silently codepaths use (#6 / #8) so the score and the
	 * runtime behaviour agree.
	 *
	 * @return array<string, mixed>
	 */
	private static function ai_client_signals(): array {
		return array(
			'configured' => Client_Wrapper::has_ai_client(),
		);
	}

	/**
	 * Count exposed entries and how many have a curated description.
	 *
	 * Re-uses `Entry_Source::get_sections()` so the count matches the actual
	 * /llms.txt output — anything that's a "real" agent-facing entry counts,
	 * anything filtered out (password-protected, noindex, draft when not
	 * exposed) doesn't.
	 *
	 * @return array<string, mixed>
	 */
	private static function description_signals(): array {
		$sections         = Entry_Source::get_sections();
		$total            = 0;
		$with_description = 0;

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['entries'] ) || ! is_array( $section['entries'] ) ) {
				continue;
			}
			foreach ( $section['entries'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				++$total;
				if ( isset( $entry['description'] ) && '' !== (string) $entry['description'] ) {
					++$with_description;
				}
			}
		}

		return array(
			'total_entries'            => $total,
			'entries_with_description' => $with_description,
		);
	}

	/**
	 * Multi-channel discovery signals for #22 / AgDR-0043.
	 *
	 * Probes a small fixed set of well-known discovery surfaces from the
	 * public web root (`get_home_path()`), not the WordPress install dir —
	 * the two differ on subdirectory installs, and these files are served
	 * from the public root. Filesystem probes (not HTTP) — fast,
	 * deterministic, and zero round-trip cost even on cron-less wp-env.
	 *
	 * Limitations (documented in AgDR-0043):
	 *   - The OpenAPI probe credits a static spec only; the always-present
	 *     `/wp-json/` REST root is intentionally NOT credited because it
	 *     would zero out the signal across every WP site.
	 *
	 * @param bool $llms_txt_cache_populated Re-uses the value from
	 *                                       `llms_txt_signals()` so a single
	 *                                       option read drives both sub-scores.
	 *
	 * @return array<string, mixed>
	 */
	private static function multi_channel_signals( bool $llms_txt_cache_populated ): array {
		$root = self::web_root();

		$ai_txt          = \file_exists( $root . 'ai.txt' );
		$wk_ai_layer     = \file_exists( $root . '.well-known/ai-layer' );
		$wk_llms_policy  = \file_exists( $root . '.well-known/llms-policy.json' );
		$openapi         = \file_exists( $root . 'openapi.json' )
			|| \file_exists( $root . 'openapi.yaml' )
			|| \file_exists( $root . 'swagger.json' );
		$active_provider = Multi_Channel_Provider_Detector::detect_active();

		// Sibling provider counts as a `.well-known/ai-layer` presence even
		// when the file isn't on disk (the plugin serves it dynamically via
		// rewrite rules).
		if ( null !== $active_provider ) {
			$wk_ai_layer = true;
		}

		// This plugin's own served channels (#172 / AgDR-0056) count the same
		// way: a channel emitted via Discovery\Channel_Router is present even
		// though no file is on disk. The file_exists probes above are kept so
		// operator-static files are still credited when the module is off.
		if ( Context_Profile_Settings::is_module_enabled( Channel_Router::MODULE ) ) {
			$ai_txt         = true;
			$wk_ai_layer    = true;
			$wk_llms_policy = true;
		}

		return array(
			'llms_txt_present'       => $llms_txt_cache_populated,
			'ai_txt_present'         => $ai_txt,
			'well_known_ai_layer'    => $wk_ai_layer,
			'well_known_llms_policy' => $wk_llms_policy,
			'openapi_spec_present'   => $openapi,
			'active_provider'        => $active_provider,
		);
	}
}
