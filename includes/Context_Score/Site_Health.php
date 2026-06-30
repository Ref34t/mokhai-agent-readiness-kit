<?php
/**
 * Site Health integration for the Context Score (#10 / AgDR-0031).
 *
 * Registers a single direct test on `site_status_tests` that surfaces
 * the cached Context Score breakdown to site administrators inside WP
 * core's Site Health screen. Reads the cached payload only — never
 * recomputes synchronously on the Site Health page render — so opening
 * Site Health on a quiet site costs one option read.
 *
 * The `description` paragraph names the single highest-leverage
 * sub-score (the same axis the React "What's missing" list uses) so
 * Site Health and the admin page tell the same story.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Context_Score;

\defined( 'ABSPATH' ) || exit;

/**
 * Direct-test integration with WordPress core Site Health.
 *
 * Single public entry point is `register_hooks()` which subscribes
 * `add_test()` to the `site_status_tests` filter. The test itself
 * (`run_test()`) is a static method so the filter callback resolves
 * without instantiating the class.
 */
final class Site_Health {

	/**
	 * Stable test identifier. Used as both the filter array key and
	 * the slug Site Health attaches to the test result. Kept short
	 * because Site Health hashes long identifiers for the in-page
	 * anchor.
	 *
	 * @var string
	 */
	public const TEST_ID = 'mokhai_context_score';

	/**
	 * Threshold for the `good` (green) badge.
	 *
	 * @var int
	 */
	public const GOOD_THRESHOLD = 80;

	/**
	 * Threshold for the `critical` (red) badge. Scores below this
	 * threshold get the critical status; scores between this and
	 * GOOD_THRESHOLD get `recommended` (orange).
	 *
	 * @var int
	 */
	public const CRITICAL_THRESHOLD = 50;

	/**
	 * Wire the filter. Called once from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_filter( 'site_status_tests', array( self::class, 'add_test' ) );
	}

	/**
	 * Register the direct test entry under `direct`.
	 *
	 * @param array<string, mixed> $tests Existing Site Health tests filter payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function add_test( $tests ): array {
		if ( ! \is_array( $tests ) ) {
			$tests = array();
		}
		if ( ! isset( $tests['direct'] ) || ! \is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct'][ self::TEST_ID ] = array(
			'label' => \__( 'Mokhai Context Score', 'mokhai-agent-readiness-kit' ),
			'test'  => array( self::class, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Direct-test callback.
	 *
	 * Reads `Service::get_breakdown()` only — never recomputes here. A
	 * null cache means the score has not been computed yet (fresh
	 * install pre-first-cron, or invalidated by `wp mokhai
	 * context-score reset`) and surfaces as a `recommended` prompt to
	 * visit the admin page.
	 *
	 * @return array<string, mixed> Site Health result payload.
	 */
	public static function run_test(): array {
		$panel_url = \admin_url( 'tools.php?page=' . \Mokhai\Admin\Context_Score_Page::PAGE_SLUG );

		$breakdown = Service::get_breakdown();
		if ( null === $breakdown ) {
			return self::result_payload(
				'recommended',
				'gray',
				\__( 'Context Score has not been computed yet.', 'mokhai-agent-readiness-kit' ),
				\__( 'Visit the Mokhai Context Score admin page or run <code>wp mokhai context-score recompute</code> to generate the first audit.', 'mokhai-agent-readiness-kit' ),
				$panel_url
			);
		}

		$overall    = isset( $breakdown['overall'] ) ? (int) $breakdown['overall'] : 0;
		$sub_scores = ( isset( $breakdown['sub_scores'] ) && \is_array( $breakdown['sub_scores'] ) )
			? $breakdown['sub_scores']
			: array();

		$worst_name = self::highest_leverage_sub_score( $sub_scores );

		$below_target_count = 0;
		foreach ( $sub_scores as $sub ) {
			if ( \is_array( $sub ) && isset( $sub['value'] ) && (int) $sub['value'] < 100 ) {
				++$below_target_count;
			}
		}

		if ( $overall >= self::GOOD_THRESHOLD ) {
			$status = 'good';
			$badge  = 'green';
			$label  = \sprintf(
				/* translators: %d: overall score 0-100. */
				\__( 'Mokhai Context Score: %d/100 — site is well-prepared for AI agent traffic.', 'mokhai-agent-readiness-kit' ),
				$overall
			);
		} elseif ( $overall >= self::CRITICAL_THRESHOLD ) {
			$status = 'recommended';
			$badge  = 'orange';
			$label  = \sprintf(
				/* translators: 1: overall score 0-100. 2: count of sub-scores below 100. */
				\__( 'Mokhai Context Score: %1$d/100 — %2$d sub-score(s) below target.', 'mokhai-agent-readiness-kit' ),
				$overall,
				$below_target_count
			);
		} else {
			$status = 'critical';
			$badge  = 'red';
			$label  = \sprintf(
				/* translators: 1: overall score 0-100. 2: count of sub-scores below 100. */
				\__( 'Mokhai Context Score: %1$d/100 — %2$d sub-score(s) below target.', 'mokhai-agent-readiness-kit' ),
				$overall,
				$below_target_count
			);
		}

		$description = '' !== $worst_name
			? \sprintf(
				/* translators: %s: human-readable sub-score name (e.g. "discoverability"). */
				\__( 'The highest-leverage area to improve is <strong>%s</strong>. Open the Mokhai Context Score admin page for the full breakdown and actionable suggestions.', 'mokhai-agent-readiness-kit' ),
				Sub_Score_Names::label( $worst_name )
			)
			: \__( 'Open the Mokhai Context Score admin page for the full breakdown.', 'mokhai-agent-readiness-kit' );

		// When the worst sub-score has an LLM narrative attached (#11 /
		// AgDR-0032), surface its one-line "why" so Site Health and the
		// React panel tell the same story. Rule-based lines are
		// intentionally NOT surfaced here — Site Health already names
		// the sub-score, and stacking a deterministic template on top
		// would just repeat that information.
		if ( '' !== $worst_name ) {
			$narrative_line = self::llm_narrative_why_for( $breakdown, $worst_name );
			if ( '' !== $narrative_line ) {
				$description .= ' ' . \wp_kses(
					$narrative_line,
					array()
				);
			}
		}

		return self::result_payload( $status, $badge, $label, $description, $panel_url );
	}

	/**
	 * Build the Site Health result envelope.
	 *
	 * The shape matches WP core's documented contract — every key is
	 * present so Site Health does not need to default any field.
	 *
	 * @param string $status      One of `good`, `recommended`, `critical`.
	 * @param string $badge_color One of `blue`, `green`, `orange`, `red`, `gray`.
	 * @param string $label       Short test label rendered as the result title.
	 * @param string $description Longer description, may contain limited HTML (Site Health passes through `wp_kses_post`).
	 * @param string $panel_url   URL the "View full breakdown" actions link should target.
	 *
	 * @return array<string, mixed>
	 */
	private static function result_payload(
		string $status,
		string $badge_color,
		string $label,
		string $description,
		string $panel_url
	): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => \__( 'Mokhai', 'mokhai-agent-readiness-kit' ),
				'color' => $badge_color,
			),
			'description' => '<p>' . $description . '</p>',
			'actions'     => \sprintf(
				'<p><a href="%1$s">%2$s</a></p><p><a href="%3$s">%4$s</a></p>',
				\esc_url( $panel_url ),
				\esc_html__( 'View full breakdown', 'mokhai-agent-readiness-kit' ),
				\esc_url( $panel_url . '#agentready-ai-preview-root' ),
				\esc_html__( 'See what AI assistants read on your site', 'mokhai-agent-readiness-kit' )
			),
			'test'        => self::TEST_ID,
		);
	}

	/**
	 * Pick the sub-score with the highest improvement leverage.
	 *
	 * Leverage = (100 - value) × weight. Ties broken by alphabetical
	 * order on the sub-score name for deterministic output across
	 * recomputes. Empty input returns the empty string — callers
	 * fall back to a generic description.
	 *
	 * @param array<string, mixed> $sub_scores Per-sub-score arrays keyed by name.
	 *
	 * @return string Name of the highest-leverage sub-score, or '' when none qualify.
	 */
	private static function highest_leverage_sub_score( array $sub_scores ): string {
		$best_name    = '';
		$best_score   = -1;
		$names_sorted = \array_keys( $sub_scores );
		\sort( $names_sorted );

		foreach ( $names_sorted as $name ) {
			$sub = $sub_scores[ $name ];
			if ( ! \is_array( $sub ) ) {
				continue;
			}
			$value  = isset( $sub['value'] ) ? (int) $sub['value'] : 0;
			$weight = isset( $sub['weight'] ) ? (int) $sub['weight'] : 0;
			if ( $value >= 100 ) {
				continue;
			}
			$leverage = ( 100 - $value ) * $weight;
			if ( $leverage > $best_score ) {
				$best_score = $leverage;
				$best_name  = (string) $name;
			}
		}

		return $best_name;
	}

	/**
	 * Pull the LLM-sourced "why" line for the named sub-score, if one is
	 * cached on the score-record. Returns '' when no narrative is present
	 * or the line came from the deterministic fallback (Site Health's own
	 * description already covers the deterministic case).
	 *
	 * @param array<string, mixed> $breakdown The cached score-record.
	 * @param string               $name      Sub-score machine name.
	 *
	 * @return string The plain-text "why" line, or '' to skip the append.
	 */
	private static function llm_narrative_why_for( array $breakdown, string $name ): string {
		$narrative  = isset( $breakdown['narrative'] ) && \is_array( $breakdown['narrative'] )
			? $breakdown['narrative']
			: array();
		$sub_scores = isset( $narrative['sub_scores'] ) && \is_array( $narrative['sub_scores'] )
			? $narrative['sub_scores']
			: array();

		$entry = isset( $sub_scores[ $name ] ) && \is_array( $sub_scores[ $name ] )
			? $sub_scores[ $name ]
			: array();
		if ( ( $entry['source'] ?? '' ) !== 'llm' ) {
			return '';
		}

		$why = isset( $entry['why'] ) && \is_string( $entry['why'] ) ? $entry['why'] : '';
		return \trim( $why );
	}
}
