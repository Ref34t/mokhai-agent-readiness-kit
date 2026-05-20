<?php
/**
 * Deterministic per-sub-score narrative templates (#11 / AgDR-0032).
 *
 * Pure functions over the breakdown shape produced by Engine::compute().
 * Used in three modes:
 *   1. WP AI Client unconfigured — whole narrative falls back here.
 *   2. LLM call failed / overshot the budget — whole narrative falls
 *      back here.
 *   3. LLM produced a line that failed Narrative_Guard — that line is
 *      replaced from here, others survive (mixed mode).
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Context_Score;

\defined( 'ABSPATH' ) || exit;

/**
 * Deterministic narrative templates keyed off the score-record's per-sub-score
 * `value` + `signals`. Three buckets per sub-score:
 *
 *   - value === 100 → "Working well" + maintenance suggestion
 *   - value >=  50  → "Partial"      + named largest gap
 *   - value <   50  → "Critical"     + named gap + Context Profile hint
 *
 * Public API:
 *
 *   - compose( array $breakdown ): array{
 *         <name>: array{ why: string, fix: string }
 *     }
 *     Builds all six pairs.
 *
 *   - compose_one( string $name, array $sub ): array{ why: string, fix: string }
 *     Builds a single pair — used by Narrative_Generator when an LLM line
 *     fails the guard.
 *
 * Output strings are translatable but kept under 140 chars so the
 * `mode === 'mixed'` UI doesn't show visibly heterogeneous line lengths
 * (LLM lines also cap at 140 — AgDR-0032).
 */
final class Rule_Based_Narrative {

	/**
	 * Hard ceiling per line. Mirrors the LLM-side cap so the two
	 * generation modes produce visually consistent rows. Templates below
	 * are written tight enough to stay under this without truncation;
	 * the cap is a backstop, not the design target.
	 *
	 * @var int
	 */
	public const MAX_OUTPUT_CHARS = 140;

	/**
	 * Compose the full narrative for every sub-score in the breakdown.
	 *
	 * @param array<string, mixed> $breakdown Output of Engine::compute or Service::get_breakdown.
	 *
	 * @return array<string, array{why: string, fix: string}> Per-sub-score pairs.
	 */
	public static function compose( array $breakdown ): array {
		$sub_scores = ( isset( $breakdown['sub_scores'] ) && \is_array( $breakdown['sub_scores'] ) )
			? $breakdown['sub_scores']
			: array();

		$out = array();
		foreach ( $sub_scores as $name => $sub ) {
			if ( ! \is_array( $sub ) ) {
				continue;
			}
			$out[ (string) $name ] = self::compose_one( (string) $name, $sub );
		}

		return $out;
	}

	/**
	 * Compose a single sub-score's pair. Public so Narrative_Generator
	 * can swap in the deterministic line when an LLM line fails the
	 * guard.
	 *
	 * @param string               $name Sub-score machine name.
	 * @param array<string, mixed> $sub  Sub-score entry (value, weight, signals, reasons).
	 *
	 * @return array{why: string, fix: string}
	 */
	public static function compose_one( string $name, array $sub ): array {
		$value   = isset( $sub['value'] ) ? (int) $sub['value'] : 0;
		$signals = ( isset( $sub['signals'] ) && \is_array( $sub['signals'] ) )
			? $sub['signals']
			: array();

		switch ( $name ) {
			case 'discoverability':
				return self::truncate_pair( self::for_discoverability( $value, $signals ) );
			case 'content_readability':
				return self::truncate_pair( self::for_content_readability( $value, $signals ) );
			case 'schema_coverage':
				return self::truncate_pair( self::for_schema_coverage( $value, $signals ) );
			case 'exposure_safety':
				return self::truncate_pair( self::for_exposure_safety( $value, $signals ) );
			case 'integration_health':
				return self::truncate_pair( self::for_integration_health( $value, $signals ) );
			case 'md_conversion_quality':
				return self::truncate_pair( self::for_md_conversion_quality( $value, $signals ) );
			default:
				return self::truncate_pair( self::for_unknown( $name, $value ) );
		}
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_discoverability( int $value, array $signals ): array {
		$cache_pop   = (bool) ( $signals['llms_txt_cache_populated'] ?? false );
		$cpts_count  = (int) ( $signals['exposed_cpts_count'] ?? 0 );
		$entry_count = (int) ( $signals['llms_txt_entry_count'] ?? 0 );
		$conflicted  = (bool) ( $signals['rewrite_conflicted'] ?? false );

		if ( $value >= 100 ) {
			return array(
				'why' => \__( 'Working well — /llms.txt is populated and exposed CPTs are configured, so agents can find the content surface.', 'agentready' ),
				'fix' => \__( 'Keep the Context Profile in sync as new CPTs are added.', 'agentready' ),
			);
		}

		if ( $conflicted ) {
			return array(
				'why' => \__( 'Another plugin is overriding the /llms.txt rewrite rule, so agents may hit a stale index.', 'agentready' ),
				'fix' => \__( 'Deactivate the conflicting plugin or move it after Agent Ready in load order, then re-test /llms.txt.', 'agentready' ),
			);
		}

		if ( ! $cache_pop ) {
			return array(
				'why' => \__( 'The /llms.txt cache is empty, so agents have nothing to discover at the site root.', 'agentready' ),
				'fix' => \__( 'Open the Context Profile and save it to seed /llms.txt, or run wp agentready llms-txt regen.', 'agentready' ),
			);
		}

		if ( $cpts_count <= 0 ) {
			return array(
				'why' => \__( 'No post types are exposed to agents, so /llms.txt has no surface to advertise.', 'agentready' ),
				'fix' => \__( 'Open the Context Profile and add at least one post type to Exposed CPTs.', 'agentready' ),
			);
		}

		if ( $entry_count <= 0 ) {
			return array(
				'why' => \__( 'Exposed CPTs are configured but no published entries are reaching /llms.txt yet.', 'agentready' ),
				'fix' => \__( 'Publish at least one entry in an exposed CPT and run wp agentready llms-txt regen.', 'agentready' ),
			);
		}

		return array(
			'why' => \__( 'Partial — the index is populated but at least one discoverability signal is below target.', 'agentready' ),
			'fix' => \__( 'Open the Context Profile and review Exposed CPTs and /llms.txt entry coverage.', 'agentready' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_content_readability( int $value, array $signals ): array {
		$total    = (int) ( $signals['total_entries'] ?? 0 );
		$coverage = (int) ( $signals['coverage_pct'] ?? 0 );

		if ( $value >= 100 ) {
			return array(
				'why' => \__( 'Working well — every exposed entry has a curated description for agents to read.', 'agentready' ),
				'fix' => \__( 'Review descriptions on newly published entries during regular editorial passes.', 'agentready' ),
			);
		}

		if ( $total <= 0 ) {
			return array(
				'why' => \__( 'No exposed entries — there is nothing for agents to read yet.', 'agentready' ),
				'fix' => \__( 'Add at least one post type to Exposed CPTs in the Context Profile and publish a post.', 'agentready' ),
			);
		}

		if ( $value < 50 ) {
			return array(
				'why' => \sprintf(
					/* translators: %d: description-coverage percentage. */
					\__( 'Critical — only %d%% of exposed entries have a curated description.', 'agentready' ),
					$coverage
				),
				'fix' => \__( 'Enable LLM descriptions in the Context Profile and run wp agentready llms-txt descriptions backfill.', 'agentready' ),
			);
		}

		return array(
			'why' => \sprintf(
				/* translators: %d: description-coverage percentage. */
				\__( 'Partial — %d%% of exposed entries have a curated description; the rest fall back to the excerpt.', 'agentready' ),
				$coverage
			),
			'fix' => \__( 'Run wp agentready llms-txt descriptions backfill to fill the gaps.', 'agentready' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_schema_coverage( int $value, array $signals ): array {
		$plugin = isset( $signals['seo_plugin'] ) ? (string) $signals['seo_plugin'] : '';

		if ( $value >= 100 && '' !== $plugin ) {
			return array(
				'why' => \sprintf(
					/* translators: %s: detected SEO plugin name. */
					\__( 'Working well — an SEO plugin (%s) is emitting structured data alongside published content.', 'agentready' ),
					$plugin
				),
				'fix' => \__( 'Audit JSON-LD output on key landing pages once per release.', 'agentready' ),
			);
		}

		return array(
			'why' => \__( 'No structured data was detected. Exposed content reaches agents without schema metadata for now.', 'agentready' ),
			'fix' => \__( 'Agent Ready will emit JSON-LD natively in a future release; until then, an SEO plugin can fill the gap.', 'agentready' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_exposure_safety( int $value, array $signals ): array {
		$cpts_count = (int) ( $signals['exposed_cpts_count'] ?? 0 );
		$risky      = (int) ( $signals['risky_statuses_count'] ?? 0 );

		if ( $value >= 100 ) {
			return array(
				'why' => \__( 'Working well — only published content is exposed and Exposed CPTs are configured explicitly.', 'agentready' ),
				'fix' => \__( 'Re-audit Exposed Statuses when new post statuses are introduced by other plugins.', 'agentready' ),
			);
		}

		if ( $risky > 0 ) {
			return array(
				'why' => \sprintf(
					/* translators: %d: count of non-publish statuses currently exposed. */
					\__( '%d non-publish status is exposed to agents, which can leak unpublished content.', 'agentready' ),
					$risky
				),
				'fix' => \__( 'Open the Context Profile and reduce Exposed Statuses to publish only.', 'agentready' ),
			);
		}

		if ( $cpts_count <= 0 ) {
			return array(
				'why' => \__( 'No CPTs are exposed, which is safe by default but means agents will find nothing.', 'agentready' ),
				'fix' => \__( 'Open the Context Profile and add at least one post type to Exposed CPTs.', 'agentready' ),
			);
		}

		return array(
			'why' => \__( 'Partial — at least one exposure signal is below target.', 'agentready' ),
			'fix' => \__( 'Open the Context Profile and review Exposed CPTs and Exposed Statuses.', 'agentready' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_integration_health( int $value, array $signals ): array {
		$cleanup_on = (bool) ( $signals['llm_cleanup_enabled'] ?? false );
		$desc_on    = (bool) ( $signals['llm_descriptions_enabled'] ?? false );
		$client_cfg = (bool) ( $signals['ai_client_configured'] ?? false );
		$conflict_n = (int) ( $signals['conflict_count'] ?? 0 );
		$wants_llm  = $cleanup_on || $desc_on;

		if ( $value >= 100 ) {
			return array(
				'why' => $wants_llm
					? \__( 'Working well — the AI Client is configured and the enabled LLM features have a backend to call.', 'agentready' )
					: \__( 'Working well — LLM features are off, so no AI Client is required.', 'agentready' ),
				'fix' => \__( 'Re-run this check after toggling any LLM feature in the Context Profile.', 'agentready' ),
			);
		}

		if ( $wants_llm && ! $client_cfg ) {
			return array(
				'why' => \__( 'LLM features are enabled but the AI Client is unconfigured, so those features silently degrade.', 'agentready' ),
				'fix' => \__( 'Configure the AI Client in WordPress Settings, or disable the LLM toggles in the Context Profile.', 'agentready' ),
			);
		}

		if ( $conflict_n > 0 ) {
			return array(
				'why' => \sprintf(
					/* translators: %d: number of detected /llms.txt conflicts. */
					\__( '%d /llms.txt conflict was detected with another plugin.', 'agentready' ),
					$conflict_n
				),
				'fix' => \__( 'Open Tools → Context and follow the conflict notice to resolve the override.', 'agentready' ),
			);
		}

		return array(
			'why' => \__( 'Partial — at least one integration signal is below target.', 'agentready' ),
			'fix' => \__( 'Open the Context Profile and verify the AI Client status and /llms.txt conflicts.', 'agentready' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_md_conversion_quality( int $value, array $signals ): array {
		$rows      = (int) ( $signals['rows_total'] ?? 0 );
		$mean      = (int) ( $signals['mean_quality'] ?? 0 );
		$above_pct = (int) ( $signals['above_threshold_pct'] ?? 0 );

		if ( $value >= 100 ) {
			return array(
				'why' => \__( 'Working well — Markdown conversion quality is at the ceiling across the cached posts.', 'agentready' ),
				'fix' => \__( 'Re-run the audit after large editorial passes to catch drift.', 'agentready' ),
			);
		}

		if ( $rows <= 0 ) {
			return array(
				'why' => \__( 'No Markdown Views cache rows yet, so there is nothing to evaluate.', 'agentready' ),
				'fix' => \__( 'Visit a few .md URLs on the site to populate the cache, then recompute.', 'agentready' ),
			);
		}

		if ( $value < 50 ) {
			return array(
				'why' => \sprintf(
					/* translators: 1: mean MD quality 0-100. 2: percentage of rows above the cleanup threshold. */
					\__( 'Critical — mean Markdown quality is %1$d/100 and only %2$d%% of cached posts are above the cleanup threshold.', 'agentready' ),
					$mean,
					$above_pct
				),
				'fix' => \__( 'Enable LLM cleanup in the Context Profile and approve cleanup runs on the lowest-quality posts.', 'agentready' ),
			);
		}

		return array(
			'why' => \sprintf(
				/* translators: 1: mean MD quality 0-100. 2: percentage of rows above the cleanup threshold. */
				\__( 'Partial — mean Markdown quality is %1$d/100; %2$d%% of cached posts are above the cleanup threshold.', 'agentready' ),
				$mean,
				$above_pct
			),
			'fix' => \__( 'Approve LLM cleanup runs on the posts flagged below the threshold in Markdown Views.', 'agentready' ),
		);
	}

	/**
	 * Catch-all when a future sub-score is added but the templates haven't
	 * been extended. The output is intentionally generic — it shouldn't
	 * pretend to know the new sub-score's semantics.
	 *
	 * @return array{why: string, fix: string}
	 */
	private static function for_unknown( string $name, int $value ): array {
		return array(
			'why' => \sprintf(
				/* translators: 1: sub-score machine name. 2: value 0-100. */
				\__( 'Sub-score "%1$s" scored %2$d/100; no template is configured for this sub-score yet.', 'agentready' ),
				$name,
				$value
			),
			'fix' => \__( 'Review the raw signals in the Full breakdown panel.', 'agentready' ),
		);
	}

	/**
	 * Defence-in-depth: truncate every line to MAX_OUTPUT_CHARS in case a
	 * future template is over-budget. Templates above are written tight,
	 * but this floor catches regressions.
	 *
	 * @param array{why: string, fix: string} $pair
	 * @return array{why: string, fix: string}
	 */
	private static function truncate_pair( array $pair ): array {
		return array(
			'why' => self::truncate( $pair['why'] ),
			'fix' => self::truncate( $pair['fix'] ),
		);
	}

	private static function truncate( string $text ): string {
		if ( \strlen( $text ) <= self::MAX_OUTPUT_CHARS ) {
			return $text;
		}
		return \rtrim( \substr( $text, 0, self::MAX_OUTPUT_CHARS - 1 ) ) . '…';
	}
}
