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
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Context_Score;

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
 *     Builds one pair per sub-score in the breakdown.
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
			case 'multi_channel_discovery':
				return self::truncate_pair( self::for_multi_channel_discovery( $value, $signals ) );
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

		$static_robots = (bool) ( $signals['static_robots_txt'] ?? false );
		$advertise     = (bool) ( $signals['advertise_alternates_enabled'] ?? false );

		// A rewrite conflict is the most severe / actionable discovery problem,
		// so it takes precedence over the static-robots advisory below.
		if ( $conflicted ) {
			return array(
				'why' => \__( 'Another plugin is overriding the /llms.txt rewrite rule, so agents may hit a stale index.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Deactivate the conflicting plugin or move it after Mokhai in load order, then re-test /llms.txt.', 'mokhai-agent-readiness-kit' ),
			);
		}

		// Advisory (#245): a populated, advertised index but a static robots.txt
		// is present — the /llms.txt reference can't be auto-added there, so the
		// robots.txt discovery channel is silently broken. Surface this even at
		// a perfect score (it sits above the value>=100 "working well" branch).
		if ( $cache_pop && $advertise && $static_robots ) {
			$llms_url = \esc_url_raw( (string) ( $signals['llms_txt_url'] ?? '' ) );
			return array(
				'why' => \__( 'A static robots.txt file is present, so the /llms.txt reference is not added automatically — agents that read robots.txt are not pointed to your index.', 'mokhai-agent-readiness-kit' ),
				'fix' => \sprintf(
					/* translators: %s: the comment line to paste into robots.txt, including the site /llms.txt URL. */
					\__( 'Add this line to your static robots.txt manually: %s', 'mokhai-agent-readiness-kit' ),
					'# AI-readable content index (mokhai): ' . $llms_url
				),
			);
		}

		if ( $value >= 100 ) {
			return array(
				'why' => \__( 'Working well — /llms.txt is populated and exposed CPTs are configured, so agents can find the content surface.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Keep the Context Profile in sync as new CPTs are added.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( ! $cache_pop ) {
			return array(
				'why' => \__( 'The /llms.txt cache is empty, so agents have nothing to discover at the site root.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Open the Context Profile and save it to seed /llms.txt, or run wp mokhai llms-txt regen.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $cpts_count <= 0 ) {
			return array(
				'why' => \__( 'No post types are exposed to agents, so /llms.txt has no surface to advertise.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Open the Context Profile and add at least one post type to Exposed CPTs.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $entry_count <= 0 ) {
			return array(
				'why' => \__( 'Exposed CPTs are configured but no published entries are reaching /llms.txt yet.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Publish at least one entry in an exposed CPT and run wp mokhai llms-txt regen.', 'mokhai-agent-readiness-kit' ),
			);
		}

		return array(
			'why' => \__( 'Partial — the index is populated but at least one discoverability signal is below target.', 'mokhai-agent-readiness-kit' ),
			'fix' => \__( 'Open the Context Profile and review Exposed CPTs and /llms.txt entry coverage.', 'mokhai-agent-readiness-kit' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_content_readability( int $value, array $signals ): array {
		$total    = (int) ( $signals['total_entries'] ?? 0 );
		$coverage = (int) ( $signals['coverage_pct'] ?? 0 );
		$desc_on  = (bool) ( $signals['llm_descriptions_enabled'] ?? false );

		if ( $value >= 100 ) {
			return array(
				'why' => \__( 'Working well — every exposed entry has a curated description for agents to read.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Review descriptions on newly published entries during regular editorial passes.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $total <= 0 ) {
			return array(
				'why' => \__( 'No exposed entries — there is nothing for agents to read yet.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Add at least one post type to Exposed CPTs in the Context Profile and publish a post.', 'mokhai-agent-readiness-kit' ),
			);
		}

		// Fix advice depends on whether auto-descriptions are already on. If
		// enabled, the user just needs to regenerate from the Descriptions tab
		// (the GUI path that resolved this in the live test); if not, the first
		// step is enabling it there. CLI stays as the alternative either way.
		$fix = $desc_on
			? \__( 'Open Context Profile → Descriptions and run "Regenerate stale descriptions" (or wp mokhai llms-txt descriptions backfill).', 'mokhai-agent-readiness-kit' )
			: \__( 'Enable auto-descriptions in Context Profile → Descriptions, then run "Regenerate stale descriptions".', 'mokhai-agent-readiness-kit' );

		if ( $value < 50 ) {
			return array(
				'why' => \sprintf(
					/* translators: %d: description-coverage percentage. */
					\__( 'Critical — only %d%% of exposed entries have a curated description.', 'mokhai-agent-readiness-kit' ),
					$coverage
				),
				'fix' => $fix,
			);
		}

		return array(
			'why' => \sprintf(
				/* translators: %d: description-coverage percentage. */
				\__( 'Partial — %d%% of exposed entries have a curated description; the rest fall back to the excerpt.', 'mokhai-agent-readiness-kit' ),
				$coverage
			),
			'fix' => $fix,
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_schema_coverage( int $value, array $signals ): array {
		$plugin = isset( $signals['seo_plugin'] ) ? (string) $signals['seo_plugin'] : '';
		$native = (bool) ( $signals['native_emit_enabled'] ?? false );

		if ( $value >= 100 && '' !== $plugin ) {
			return array(
				'why' => \sprintf(
					/* translators: %s: detected SEO plugin name. */
					\__( 'Working well — an SEO plugin (%s) is emitting structured data alongside published content.', 'mokhai-agent-readiness-kit' ),
					$plugin
				),
				'fix' => \__( 'Audit JSON-LD output on key landing pages once per release.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $value >= 100 && $native ) {
			return array(
				'why' => \__( 'Working well — the plugin emits native JSON-LD (WebSite, Organization, per-content), so agents get structured data without an SEO plugin.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Audit the native JSON-LD output on key landing pages once per release.', 'mokhai-agent-readiness-kit' ),
			);
		}

		return array(
			'why' => \__( 'No structured data was detected. Exposed content reaches agents without schema metadata for now.', 'mokhai-agent-readiness-kit' ),
			'fix' => \__( 'Enable Schema emission in the Context Profile to emit native JSON-LD, or rely on an SEO plugin to fill the gap.', 'mokhai-agent-readiness-kit' ),
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
				'why' => \__( 'Working well — only published content is exposed and Exposed CPTs are configured explicitly.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Re-audit Exposed Statuses when new post statuses are introduced by other plugins.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $risky > 0 ) {
			return array(
				'why' => \sprintf(
					/* translators: %d: count of non-publish statuses currently exposed. */
					\__( '%d non-publish status is exposed to agents, which can leak unpublished content.', 'mokhai-agent-readiness-kit' ),
					$risky
				),
				'fix' => \__( 'Open the Context Profile and reduce Exposed Statuses to publish only.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $cpts_count <= 0 ) {
			return array(
				'why' => \__( 'No CPTs are exposed, which is safe by default but means agents will find nothing.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Open the Context Profile and add at least one post type to Exposed CPTs.', 'mokhai-agent-readiness-kit' ),
			);
		}

		return array(
			'why' => \__( 'Partial — at least one exposure signal is below target.', 'mokhai-agent-readiness-kit' ),
			'fix' => \__( 'Open the Context Profile and review Exposed CPTs and Exposed Statuses.', 'mokhai-agent-readiness-kit' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_integration_health( int $value, array $signals ): array {
		$desc_on    = (bool) ( $signals['llm_descriptions_enabled'] ?? false );
		$client_cfg = (bool) ( $signals['ai_client_configured'] ?? false );
		$conflict_n = (int) ( $signals['conflict_count'] ?? 0 );
		$wants_llm  = $desc_on;

		if ( $value >= 100 ) {
			return array(
				'why' => $wants_llm
					? \__( 'Working well — the AI Client is configured and the enabled LLM features have a backend to call.', 'mokhai-agent-readiness-kit' )
					: \__( 'Working well — LLM features are off, so no AI Client is required.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Re-run this check after toggling any LLM feature in the Context Profile.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $wants_llm && ! $client_cfg ) {
			return array(
				'why' => \__( 'LLM features are enabled but the AI Client is unconfigured, so those features silently degrade.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Configure the AI Client in WordPress Settings, or disable the LLM toggles in the Context Profile.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $conflict_n > 0 ) {
			return array(
				'why' => \sprintf(
					/* translators: %d: number of detected /llms.txt conflicts. */
					\__( '%d /llms.txt conflict was detected with another plugin.', 'mokhai-agent-readiness-kit' ),
					$conflict_n
				),
				'fix' => \__( 'Open Tools → Context and follow the conflict notice to resolve the override.', 'mokhai-agent-readiness-kit' ),
			);
		}

		return array(
			'why' => \__( 'Partial — at least one integration signal is below target.', 'mokhai-agent-readiness-kit' ),
			'fix' => \__( 'Open the Context Profile and verify the AI Client status and /llms.txt conflicts.', 'mokhai-agent-readiness-kit' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	/**
	 * Build a short comma-separated list of the worst-scoring page titles from
	 * the `worst_urls` signal (#255). Caps at two titles so the composed line
	 * stays within the 140-char narrative limit; the admin UI can render the
	 * full linked list from the raw `worst_urls` signal.
	 *
	 * @param array<string, mixed> $signals The md_conversion_quality signals.
	 */
	private static function worst_url_titles( array $signals ): string {
		$worst = isset( $signals['worst_urls'] ) && \is_array( $signals['worst_urls'] )
			? $signals['worst_urls']
			: array();

		$titles = array();
		foreach ( $worst as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			$title = \trim( (string) ( $entry['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$titles[] = $title;
			if ( \count( $titles ) >= 2 ) {
				break;
			}
		}

		return \implode( ', ', $titles );
	}

	private static function for_md_conversion_quality( int $value, array $signals ): array {
		$rows      = (int) ( $signals['rows_total'] ?? 0 );
		$mean      = (int) ( $signals['mean_quality'] ?? 0 );
		$above_pct = (int) ( $signals['above_threshold_pct'] ?? 0 );

		if ( $value >= 100 ) {
			return array(
				'why' => \__( 'Working well — Markdown conversion quality is at the ceiling across the cached posts.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Re-run the audit after large editorial passes to catch drift.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $rows <= 0 ) {
			return array(
				'why' => \__( 'No Markdown Views cache rows yet, so there is nothing to evaluate.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Visit a few .md URLs on the site to populate the cache, then recompute.', 'mokhai-agent-readiness-kit' ),
			);
		}

		// #255: body-quality findings take precedence — an empty or
		// noise-dominated body means an agent gets garbage regardless of how
		// the mean conversion score reads. Name the worst URLs and scope the
		// finding to what was sampled.
		$empty_pct = (int) ( $signals['empty_pct'] ?? 0 );
		$noisy_pct = (int) ( $signals['noisy_pct'] ?? 0 );
		$sampled   = (int) ( $signals['sampled'] ?? 0 );
		if ( $empty_pct > 0 || $noisy_pct > 0 ) {
			$worst = self::worst_url_titles( $signals );
			$why   = $empty_pct >= $noisy_pct
				? \sprintf(
					/* translators: 1: percentage of sampled bodies that are empty. 2: number of bodies sampled. 3: total cached rows. */
					\__( '%1$d%% of %2$d sampled .md bodies (of %3$d cached) are empty or near-empty — agents get no usable content there.', 'mokhai-agent-readiness-kit' ),
					$empty_pct,
					$sampled,
					$rows
				)
				: \sprintf(
					/* translators: 1: percentage of sampled bodies dominated by noise. 2: number of bodies sampled. 3: total cached rows. */
					\__( '%1$d%% of %2$d sampled .md bodies (of %3$d cached) are dominated by non-prose noise — agents ingest junk there.', 'mokhai-agent-readiness-kit' ),
					$noisy_pct,
					$sampled,
					$rows
				);

			$fix = '' !== $worst
				? \sprintf(
					/* translators: %s: comma-separated list of the worst-scoring page titles. */
					\__( 'Fix the source content of the worst pages first (%s) so each .md serves real text, then recompute.', 'mokhai-agent-readiness-kit' ),
					$worst
				)
				: \__( 'Fix the source content of the empty/noisy pages so each .md serves real text, then recompute.', 'mokhai-agent-readiness-kit' );

			return array(
				'why' => $why,
				'fix' => $fix,
			);
		}

		if ( $value < 50 ) {
			return array(
				'why' => \sprintf(
					/* translators: 1: mean MD quality 0-100. 2: percentage of rows above the MD-quality threshold. */
					\__( 'Critical — mean Markdown quality is %1$d/100 and only %2$d%% of cached posts are above the MD-quality threshold.', 'mokhai-agent-readiness-kit' ),
					$mean,
					$above_pct
				),
				'fix' => \__( 'Improve the source HTML of the lowest-quality posts — cleaner heading structure, fewer nested wrappers and shortcodes — so the deterministic Markdown conversion scores higher, then recompute.', 'mokhai-agent-readiness-kit' ),
			);
		}

		return array(
			'why' => \sprintf(
				/* translators: 1: mean MD quality 0-100. 2: percentage of rows above the MD-quality threshold. */
				\__( 'Partial — mean Markdown quality is %1$d/100; %2$d%% of cached posts are above the MD-quality threshold.', 'mokhai-agent-readiness-kit' ),
				$mean,
				$above_pct
			),
			'fix' => \__( 'Tidy the source HTML of the posts scoring below the threshold — simpler markup converts to cleaner Markdown — then recompute.', 'mokhai-agent-readiness-kit' ),
		);
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{why: string, fix: string}
	 */
	private static function for_multi_channel_discovery( int $value, array $signals ): array {
		$present_count   = (int) ( $signals['surfaces_present_count'] ?? 0 );
		$active_provider = ( isset( $signals['active_provider'] ) && \is_array( $signals['active_provider'] ) )
			? $signals['active_provider']
			: null;
		$provider_name   = ( null !== $active_provider && isset( $active_provider['name'] ) )
			? (string) $active_provider['name']
			: '';

		if ( $value >= 100 ) {
			return array(
				'why' => '' !== $provider_name
					? \sprintf(
						/* translators: %s: detected sibling provider name (e.g. "AI Layer"). */
						\__( 'Working well — every plugin-served discovery channel is published and %s is coordinating multi-channel coverage.', 'mokhai-agent-readiness-kit' ),
						$provider_name
					)
					: \__( 'Working well — every plugin-served discovery channel is published.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Re-audit after adding or removing sibling AI-readiness plugins to keep coverage consistent.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( $value <= 0 ) {
			return array(
				'why' => \__( 'No agent-discovery channels detected — agents that scan ai.txt or /.well-known/ declarations will miss this site.', 'mokhai-agent-readiness-kit' ),
				'fix' => \__( 'Open the Context Profile and save it to seed /llms.txt as a first discovery channel.', 'mokhai-agent-readiness-kit' ),
			);
		}

		if ( '' !== $provider_name ) {
			return array(
				'why' => \sprintf(
					/* translators: 1: count of plugin-served channels detected (0-4). 2: sibling provider name. */
					\__( 'Partial — %1$d of 4 plugin-served discovery channels detected; %2$s is contributing to coverage.', 'mokhai-agent-readiness-kit' ),
					$present_count,
					$provider_name
				),
				'fix' => \__( 'Enable the missing channels (ai.txt, ai-layer, llms-policy.json) in the Context Profile. OpenAPI is an optional bonus for API sites.', 'mokhai-agent-readiness-kit' ),
			);
		}

		return array(
			'why' => \sprintf(
				/* translators: %d: count of plugin-served channels detected (0-4). */
				\__( 'Partial — %d of 4 plugin-served discovery channels detected.', 'mokhai-agent-readiness-kit' ),
				$present_count
			),
			'fix' => \__( 'Enable the missing channels (ai.txt, ai-layer, llms-policy.json) in the Context Profile. OpenAPI is an optional bonus for API sites.', 'mokhai-agent-readiness-kit' ),
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
				\__( 'Sub-score "%1$s" scored %2$d/100; no template is configured for this sub-score yet.', 'mokhai-agent-readiness-kit' ),
				$name,
				$value
			),
			'fix' => \__( 'Review the raw signals in the Full breakdown panel.', 'mokhai-agent-readiness-kit' ),
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
