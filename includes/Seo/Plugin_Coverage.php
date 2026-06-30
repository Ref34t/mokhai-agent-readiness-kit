<?php
/**
 * Schema-coverage matrix for the supported SEO plugins.
 *
 * Encodes which JSON-LD types each plugin already emits, so the gap-fill
 * emitter (`Schema_Emitter`) can compute `emit = baseline ∖ covered` per
 * detected plugin. v0.1 declares all three supported plugins (Yoast, Rank
 * Math, AIOSEO) cover the entire baseline — the gap is therefore empty,
 * Mokhai emits nothing, and Plugin Check Tool reports no duplicate-
 * schema warnings (AC #6).
 *
 * Full design rationale: docs/agdr/AgDR-0033-seo-defer-gap-fill-emitter.md.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Seo;

\defined( 'ABSPATH' ) || exit;

/**
 * Pure matrix + gap computation. No WordPress dependencies — safe to unit-
 * test without booting WP.
 *
 * @phpstan-type CoverageMatrix array<string, array<int, string>>
 */
final class Plugin_Coverage {

	/**
	 * Default per-plugin coverage. Keyed by the slugs returned by
	 * `Schema_Coordination_Detector::detect()['posture']`.
	 *
	 * v0.1 conservatively treats all three plugins as fully covering the
	 * baseline. Partial coverage (e.g. a Yoast site that disables
	 * `Organization`) is not modeled — over-deferring is safe for wp.org
	 * review; under-deferring is a review blocker.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const DEFAULT_COVERAGE = array(
		'yoast'     => array( 'WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList' ),
		'rank_math' => array( 'WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList' ),
		'aioseo'    => array( 'WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList' ),
	);

	/**
	 * Baseline types Mokhai knows how to emit when no SEO plugin is
	 * active. `BreadcrumbList` is intentionally absent — we'd need theme
	 * cooperation to know the trail, and a fabricated trail is worse than
	 * none.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_BASELINE = array( 'WebSite', 'Organization', 'WebPage', 'Article' );

	/**
	 * Filter name applied to the coverage matrix before gap computation.
	 *
	 * @var string
	 */
	public const FILTER_COVERAGE_MATRIX = 'agentready_schema_coverage_matrix';

	/**
	 * Filter name applied to the baseline type list before gap computation.
	 *
	 * @var string
	 */
	public const FILTER_BASELINE_TYPES = 'agentready_schema_baseline_types';

	/**
	 * Resolve the active coverage matrix, applying any filter override.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function coverage_matrix(): array {
		// Hook name resolves to `agentready_schema_coverage_matrix` — the
		// constant is prefixed; phpcs can't see through the constant ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$filtered = \apply_filters( self::FILTER_COVERAGE_MATRIX, self::DEFAULT_COVERAGE );
		return self::sanitize_matrix( $filtered );
	}

	/**
	 * Resolve the active baseline list, applying any filter override.
	 *
	 * @return array<int, string>
	 */
	public static function baseline_types(): array {
		// Hook name resolves to `agentready_schema_baseline_types` — the
		// constant is prefixed; phpcs can't see through the constant ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$filtered = \apply_filters( self::FILTER_BASELINE_TYPES, self::DEFAULT_BASELINE );
		return self::sanitize_type_list( $filtered );
	}

	/**
	 * Compute the gap to fill given a posture slug.
	 *
	 * When `$posture_slug` is empty / unknown, the gap equals the full
	 * baseline (no SEO plugin detected → emit everything in the baseline).
	 *
	 * @param string $posture_slug Slug returned by Schema_Coordination_Detector.
	 *
	 * @return array<int, string> Types to emit, preserving baseline order.
	 */
	public static function compute_gap( string $posture_slug ): array {
		$baseline = self::baseline_types();

		if ( '' === $posture_slug || 'none' === $posture_slug ) {
			return $baseline;
		}

		$matrix  = self::coverage_matrix();
		$covered = $matrix[ $posture_slug ] ?? array();
		$covered = \array_values( \array_unique( $covered ) );
		$gap     = array();

		foreach ( $baseline as $type ) {
			if ( ! \in_array( $type, $covered, true ) ) {
				$gap[] = $type;
			}
		}

		return $gap;
	}

	/**
	 * Resolve the deferred set for a posture (the intersection of baseline
	 * and covered). Used by the admin UI to render "deferred to <plugin>"
	 * type lists.
	 *
	 * @param string $posture_slug Slug returned by Schema_Coordination_Detector.
	 *
	 * @return array<int, string>
	 */
	public static function compute_deferred( string $posture_slug ): array {
		if ( '' === $posture_slug || 'none' === $posture_slug ) {
			return array();
		}

		$baseline = self::baseline_types();
		$matrix   = self::coverage_matrix();
		$covered  = $matrix[ $posture_slug ] ?? array();

		$deferred = array();
		foreach ( $baseline as $type ) {
			if ( \in_array( $type, $covered, true ) ) {
				$deferred[] = $type;
			}
		}

		return $deferred;
	}

	/**
	 * Coerce arbitrary filter output into a `slug => string[]` shape. Drops
	 * non-string keys, non-array values, and non-string members so callers
	 * never have to defensively re-check the matrix.
	 *
	 * @param mixed $value Filter output.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function sanitize_matrix( $value ): array {
		if ( ! \is_array( $value ) ) {
			return self::DEFAULT_COVERAGE;
		}

		$clean = array();
		foreach ( $value as $slug => $types ) {
			if ( ! \is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$clean[ $slug ] = self::sanitize_type_list( $types );
		}

		return $clean;
	}

	/**
	 * Coerce arbitrary filter output into a `string[]` of non-empty type
	 * names.
	 *
	 * @param mixed $value Filter output.
	 *
	 * @return array<int, string>
	 */
	private static function sanitize_type_list( $value ): array {
		if ( ! \is_array( $value ) ) {
			return self::DEFAULT_BASELINE;
		}

		$clean = array();
		foreach ( $value as $type ) {
			if ( \is_string( $type ) && '' !== $type ) {
				$clean[] = $type;
			}
		}

		return \array_values( \array_unique( $clean ) );
	}
}
