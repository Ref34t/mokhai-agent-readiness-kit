<?php
/**
 * Pure scoring engine for the Context Score (#9 / AgDR-0030).
 *
 * Takes a `$signals` array gathered by `Signal_Collector` and returns a
 * structured `Breakdown` array: overall 0–100 plus a per-sub-score map, each
 * carrying its raw value, weight, raw signal counts, and a list of
 * human-readable reason strings. No WordPress calls. Pure PHP so the unit
 * tests run against fixtures without WP_UnitTestCase.
 *
 * Sub-score weights (sum to 100) are class constants — see AgDR-0030 §
 * "Sub-scores and weights".
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Context_Score;

\defined( 'ABSPATH' ) || exit;

/**
 * Deterministic Context Score engine.
 *
 * Public API is `Engine::compute( array $signals ): array`. Everything else
 * is private — sub-score calculators live as static methods so the test
 * suite can target them individually if a regression on one sub-score
 * shouldn't drag down the whole suite.
 *
 * The breakdown shape is the durable contract consumed by ticket #10
 * (admin UI) and ticket #11 (LLM narrative). The numeric weights are
 * tunable v0.1 starting values; adjustments do not require a schema bump
 * because the shape is what's persisted, not the formula.
 */
final class Engine {

	/**
	 * Schema version of the breakdown payload. Bumped when adding sub-scores
	 * or changing the shape of the per-sub-score entry. Additive sub-score
	 * additions with safe defaults bump the version; destructive changes
	 * (renames, type changes) require a /migration ticket per the workflow
	 * gates.
	 *
	 * v3 (#139 / AgDR-0047) adds an additive parallel `reason_keys` array to
	 * each sub-score — `{code, args}` tokens the admin UI maps to translatable
	 * strings. The English `reasons` array is unchanged; this is purely
	 * additive, so no /migration ticket is required.
	 *
	 * @var int
	 */
	public const BREAKDOWN_SCHEMA_VERSION = 3;

	/**
	 * Per-sub-score weights. Sum MUST equal 100 — asserted in self-tests so a
	 * future contributor adjusting weights without re-totalling gets a
	 * deterministic failure.
	 *
	 * v2 of the breakdown (#22 / AgDR-0043) splits the original
	 * `discoverability` (20) into the existing /llms.txt-focused
	 * `discoverability` (10) and the new sibling `multi_channel_discovery`
	 * (10). All other weights are unchanged.
	 *
	 * @var array<string, int>
	 */
	public const WEIGHTS = array(
		'discoverability'         => 10,
		'content_readability'     => 15,
		'schema_coverage'         => 10,
		'exposure_safety'         => 15,
		'integration_health'      => 15,
		'md_conversion_quality'   => 25,
		'multi_channel_discovery' => 10,
	);

	/**
	 * Quality-score cutoff for the `md_conversion_quality` sub-score's
	 * "% of cached rows above threshold" component (40 of its points).
	 *
	 * Owned here (not borrowed from the retired Markdown Views cleanup config)
	 * so the MD-quality sub-score is self-contained. Seeded to the historical
	 * cleanup-threshold default (70) so the score is unchanged for sites on the
	 * default — see AgDR-0049 (#153). Sites that had set a non-default cleanup
	 * threshold see this component recomputed against 70.
	 *
	 * @var int
	 */
	public const MD_QUALITY_THRESHOLD = 70;

	/**
	 * Compute the full breakdown from a signals array.
	 *
	 * @param array<string, mixed> $signals Output of Signal_Collector::collect().
	 *
	 * @return array{
	 *     schema_version: int,
	 *     overall: int,
	 *     sub_scores: array<string, array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}>
	 * }
	 */
	public static function compute( array $signals ): array {
		$sub_scores = array(
			'discoverability'         => self::score_discoverability( $signals ),
			'content_readability'     => self::score_content_readability( $signals ),
			'schema_coverage'         => self::score_schema_coverage( $signals ),
			'exposure_safety'         => self::score_exposure_safety( $signals ),
			'integration_health'      => self::score_integration_health( $signals ),
			'md_conversion_quality'   => self::score_md_conversion_quality( $signals ),
			'multi_channel_discovery' => self::score_multi_channel_discovery( $signals ),
		);

		$weighted_sum = 0;
		foreach ( $sub_scores as $name => $sub ) {
			$weight        = self::WEIGHTS[ $name ];
			$weighted_sum += $sub['value'] * $weight;
		}

		return array(
			'schema_version' => self::BREAKDOWN_SCHEMA_VERSION,
			'overall'        => (int) floor( $weighted_sum / 100 ),
			'sub_scores'     => $sub_scores,
		);
	}

	/**
	 * Discoverability — can an agent find the site's content surface?
	 *
	 * Signals (in order of weight):
	 *   - `/llms.txt` cache populated (50 pts)
	 *   - Site exposes at least one CPT (25 pts)
	 *   - Non-zero entry count in /llms.txt (15 pts)
	 *   - No rewrite-shadowing conflict (10 pts)
	 *
	 * @param array<string, mixed> $signals
	 *
	 * @return array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}
	 */
	private static function score_discoverability( array $signals ): array {
		$profile     = self::array_at( $signals, 'profile' );
		$llms_txt    = self::array_at( $signals, 'llms_txt' );
		$exposed_cpt = self::array_at( $profile, 'exposed_cpts' );
		$cache_pop   = (bool) ( $llms_txt['cache_populated'] ?? false );
		$entry_count = (int) ( $llms_txt['entry_count'] ?? 0 );
		$conflicts   = self::array_at( $llms_txt, 'conflicts' );

		$rewrite_conflicted = false;
		foreach ( $conflicts as $conflict ) {
			if ( is_array( $conflict ) && ( $conflict['kind'] ?? '' ) === 'rewrite' ) {
				$rewrite_conflicted = true;
				break;
			}
		}

		$score       = 0;
		$reasons     = array();
		$reason_keys = array();

		if ( $cache_pop ) {
			$score += 50;
			self::add_reason( $reasons, $reason_keys, 'disc_llms_txt_populated', '/llms.txt cache is populated.' );
		} else {
			self::add_reason( $reasons, $reason_keys, 'disc_llms_txt_empty', '/llms.txt cache is empty — agents cannot discover the site index.' );
		}

		if ( count( $exposed_cpt ) > 0 ) {
			$score += 25;
			self::add_reason( $reasons, $reason_keys, 'disc_cpt_exposed', sprintf( 'Site exposes %d post type(s) to agents.', count( $exposed_cpt ) ), array( count( $exposed_cpt ) ) );
		} else {
			self::add_reason( $reasons, $reason_keys, 'disc_no_cpt_exposed', 'No post types are exposed to agents (Context Profile → Exposed CPTs is empty).' );
		}

		if ( $entry_count > 0 ) {
			$score += 15;
			self::add_reason( $reasons, $reason_keys, 'disc_entries_listed', sprintf( '/llms.txt lists %d entries.', $entry_count ), array( $entry_count ) );
		} else {
			self::add_reason( $reasons, $reason_keys, 'disc_zero_entries', '/llms.txt has zero entries.' );
		}

		if ( ! $rewrite_conflicted ) {
			$score += 10;
		} else {
			self::add_reason( $reasons, $reason_keys, 'disc_rewrite_conflict', 'Another plugin is overriding the /llms.txt rewrite rule.' );
		}

		// Advisory-only signals (#245): a static robots.txt blocks the
		// /llms.txt auto-reference. Surfaced in the narrative; deliberately
		// NOT scored — it doesn't change discoverability capability, only the
		// robots.txt advertising channel.
		$advertise_enabled = (bool) ( $profile['advertise_alternates_enabled'] ?? false );
		$static_robots     = (bool) ( $llms_txt['static_robots_txt'] ?? false );
		$llms_txt_url      = (string) ( $llms_txt['llms_txt_url'] ?? '' );

		return array(
			'value'       => self::clamp( $score ),
			'weight'      => self::WEIGHTS['discoverability'],
			'signals'     => array(
				'llms_txt_cache_populated'     => $cache_pop,
				'exposed_cpts_count'           => count( $exposed_cpt ),
				'llms_txt_entry_count'         => $entry_count,
				'rewrite_conflicted'           => $rewrite_conflicted,
				'static_robots_txt'            => $static_robots,
				'advertise_alternates_enabled' => $advertise_enabled,
				'llms_txt_url'                 => $llms_txt_url,
			),
			'reasons'     => $reasons,
			'reason_keys' => $reason_keys,
		);
	}

	/**
	 * Content readability — are exposed entries curated for agents?
	 *
	 * Single signal: description coverage. The more entries have a curated
	 * description (post excerpt or LLM-generated cached description from #8),
	 * the higher the score. A site with zero entries scores 0 (nothing to read).
	 *
	 * @param array<string, mixed> $signals
	 *
	 * @return array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}
	 */
	private static function score_content_readability( array $signals ): array {
		$desc        = self::array_at( $signals, 'descriptions' );
		$profile     = self::array_at( $signals, 'profile' );
		$desc_on     = (bool) ( $profile['llm_descriptions_enabled'] ?? false );
		$total       = (int) ( $desc['total_entries'] ?? 0 );
		$with_desc   = (int) ( $desc['entries_with_description'] ?? 0 );
		$with_desc   = min( $with_desc, $total );
		$reasons     = array();
		$reason_keys = array();

		if ( $total <= 0 ) {
			self::add_reason( $reasons, $reason_keys, 'cr_no_exposed_entries', 'No exposed entries — nothing for agents to read.' );
			return array(
				'value'       => 0,
				'weight'      => self::WEIGHTS['content_readability'],
				'signals'     => array(
					'total_entries'            => 0,
					'entries_with_description' => 0,
					'coverage_pct'             => 0,
					'llm_descriptions_enabled' => $desc_on,
				),
				'reasons'     => $reasons,
				'reason_keys' => $reason_keys,
			);
		}

		$coverage_pct = (int) floor( ( $with_desc * 100 ) / $total );

		if ( $coverage_pct >= 90 ) {
			self::add_reason( $reasons, $reason_keys, 'cr_coverage_good', sprintf( '%d%% of exposed entries have a curated description.', $coverage_pct ), array( $coverage_pct ) );
		} elseif ( $coverage_pct >= 50 ) {
			self::add_reason( $reasons, $reason_keys, 'cr_coverage_medium', sprintf( '%d%% of exposed entries have a curated description — room to improve.', $coverage_pct ), array( $coverage_pct ) );
		} else {
			self::add_reason( $reasons, $reason_keys, 'cr_coverage_low', sprintf( 'Only %d%% of exposed entries have a curated description.', $coverage_pct ), array( $coverage_pct ) );
		}

		return array(
			'value'       => self::clamp( $coverage_pct ),
			'weight'      => self::WEIGHTS['content_readability'],
			'signals'     => array(
				'total_entries'            => $total,
				'entries_with_description' => $with_desc,
				'coverage_pct'             => $coverage_pct,
				'llm_descriptions_enabled' => $desc_on,
			),
			'reasons'     => $reasons,
			'reason_keys' => $reason_keys,
		);
	}

	/**
	 * Schema coverage — is structured data being published alongside the
	 * content? In v0.1 we can only detect the *presence* of an SEO plugin
	 * (Yoast / Rank Math / AIOSEO). Deeper inspection (per-page schema
	 * validation) is a v0.1.x candidate.
	 *
	 * @param array<string, mixed> $signals
	 *
	 * @return array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}
	 */
	private static function score_schema_coverage( array $signals ): array {
		$schema      = self::array_at( $signals, 'schema' );
		$plugin      = (string) ( $schema['seo_plugin'] ?? '' );
		$native      = ! empty( $schema['native_emit_enabled'] );
		$reasons     = array();
		$reason_keys = array();

		if ( '' !== $plugin ) {
			self::add_reason( $reasons, $reason_keys, 'sc_seo_plugin_detected', sprintf( 'Detected SEO plugin (%s) — structured data is likely being emitted.', $plugin ), array( $plugin ) );
			$value = 100;
		} elseif ( $native ) {
			// Native gap-fill emitter is on via Context Profile (#73 /
			// AgDR-0034). Mokhai is emitting WebSite + Organization
			// + per-content JSON-LD on wp_head — same schema_coverage
			// outcome as a third-party SEO plugin, without the
			// disclaimer that drove ticket #73.
			self::add_reason( $reasons, $reason_keys, 'sc_native_jsonld', 'Mokhai is emitting native JSON-LD (WebSite + Organization + per-content). Schema coverage satisfied without a third-party SEO plugin.' );
			$value = 100;
		} else {
			// Neither path active. Reason text points operators at the
			// concrete one-click action ("enable Schema emission in
			// Context Profile") rather than the v0.1 disclaimer that
			// PR #72 shipped — now that #73 has landed, native emission
			// is real and reachable, not a future-tense promise.
			self::add_reason( $reasons, $reason_keys, 'sc_no_structured_data', 'No structured data detected on this site. Enable Schema emission in the Context Profile to have Mokhai emit native JSON-LD, or rely on a third-party SEO plugin.' );
			$value = 60;
		}

		return array(
			'value'       => self::clamp( $value ),
			'weight'      => self::WEIGHTS['schema_coverage'],
			'signals'     => array(
				'seo_plugin'          => $plugin,
				'native_emit_enabled' => $native,
			),
			'reasons'     => $reasons,
			'reason_keys' => $reason_keys,
		);
	}

	/**
	 * Exposure safety — does the site avoid leaking unpublished or
	 * sensitive content to agents?
	 *
	 * Signals:
	 *   - `exposed_statuses` ⊆ `[publish]`              (safe baseline, 60 pts)
	 *   - At least one CPT exposed                       (40 pts)
	 *
	 * Penalty applied per non-publish status — `private` and `password` are
	 * higher penalty than `pending` / `draft` because they imply intent to
	 * hide.
	 *
	 * @param array<string, mixed> $signals
	 *
	 * @return array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}
	 */
	private static function score_exposure_safety( array $signals ): array {
		$profile     = self::array_at( $signals, 'profile' );
		$cpts        = self::array_at( $profile, 'exposed_cpts' );
		$statuses    = self::array_at( $profile, 'exposed_statuses' );
		$reasons     = array();
		$reason_keys = array();

		$score = 0;

		// Baseline: exposing only `publish` is the safe default per FR-9.
		$risky_statuses = array_values(
			array_filter(
				$statuses,
				static fn( $s ): bool => 'publish' !== (string) $s
			)
		);

		if ( array() === $risky_statuses ) {
			$score += 60;
			self::add_reason( $reasons, $reason_keys, 'es_only_published', 'Only published content is exposed to agents.' );
		} else {
			// Penalty: -15 per risky status, capped to consume the 60-point baseline.
			$penalty   = min( 60, count( $risky_statuses ) * 15 );
			$score    += max( 0, 60 - $penalty );
			$risky_csv = implode( ', ', array_map( 'strval', $risky_statuses ) );
			self::add_reason( $reasons, $reason_keys, 'es_risky_statuses', sprintf( 'Exposed statuses include %s — these can leak unpublished content to agents.', $risky_csv ), array( $risky_csv ) );
		}

		if ( count( $cpts ) > 0 ) {
			$score += 40;
			self::add_reason( $reasons, $reason_keys, 'es_cpt_explicit', 'Exposed CPTs are configured explicitly (no implicit defaults).' );
		} else {
			self::add_reason( $reasons, $reason_keys, 'es_no_cpt', 'No CPTs exposed — safe-by-default, but agents will find nothing.' );
		}

		return array(
			'value'       => self::clamp( $score ),
			'weight'      => self::WEIGHTS['exposure_safety'],
			'signals'     => array(
				'exposed_cpts_count'   => count( $cpts ),
				'exposed_statuses'     => array_values( array_map( 'strval', $statuses ) ),
				'risky_statuses_count' => count( $risky_statuses ),
			),
			'reasons'     => $reasons,
			'reason_keys' => $reason_keys,
		);
	}

	/**
	 * Integration health — are the LLM + /llms.txt integrations consistent?
	 *
	 * Signals:
	 *   - LLM toggle consistency with AI Client posture (60 pts) — both off
	 *     OR both on yields full credit; toggles on but client unconfigured
	 *     yields zero (the silent-degrade state).
	 *   - No /llms.txt conflicts (any kind)             (40 pts)
	 *
	 * Sites that opt out of the LLM stack entirely (toggles off + no client)
	 * are NOT penalised — that's a valid steady-state configuration. The
	 * only penalty target is the inconsistent state (LLM on + no client).
	 *
	 * @param array<string, mixed> $signals
	 *
	 * @return array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}
	 */
	private static function score_integration_health( array $signals ): array {
		$profile     = self::array_at( $signals, 'profile' );
		$llms_txt    = self::array_at( $signals, 'llms_txt' );
		$ai_client   = self::array_at( $signals, 'ai_client' );
		$desc_on     = (bool) ( $profile['llm_descriptions_enabled'] ?? false );
		$client_cfg  = (bool) ( $ai_client['configured'] ?? false );
		$conflicts   = self::array_at( $llms_txt, 'conflicts' );
		$reasons     = array();
		$reason_keys = array();

		$score = 0;

		// LLM ↔ client consistency. Inconsistent = toggles on but client off
		// (the silent-degrade trap from AgDR-0003). Every other state is fine,
		// including "client configured but LLM toggles off" — that's a
		// "capability present, deliberately unused" state, no penalty.
		//
		// `llm_descriptions_enabled` is now the only LLM toggle this guards —
		// the Markdown Views cleanup pass it also used to cover was retired in
		// #153 (AgDR-0049). Descriptions already carries the identical
		// "LLM-on → client-required" check, so the guard is unchanged for every
		// site except one that had ONLY cleanup on with no client: that site
		// stops being penalised for a feature that no longer exists.
		$wants_llm = $desc_on;
		if ( $wants_llm && ! $client_cfg ) {
			self::add_reason( $reasons, $reason_keys, 'ih_llm_unconfigured', 'LLM features enabled but AI client is unconfigured — those features are silently degraded.' );
		} else {
			$score += 60;
			if ( $wants_llm ) {
				self::add_reason( $reasons, $reason_keys, 'ih_llm_configured', 'AI client configured and LLM features enabled.' );
			} else {
				self::add_reason( $reasons, $reason_keys, 'ih_llm_disabled', 'LLM features disabled — no AI client required.' );
			}
		}

		// Conflicts (any kind).
		if ( array() === $conflicts ) {
			$score += 40;
		} else {
			$kinds = array();
			foreach ( $conflicts as $conflict ) {
				if ( is_array( $conflict ) && isset( $conflict['kind'] ) ) {
					$kinds[] = (string) $conflict['kind'];
				}
			}
			$kinds     = array_values( array_unique( $kinds ) );
			$kinds_csv = implode( ', ', $kinds );
			self::add_reason( $reasons, $reason_keys, 'ih_llms_txt_conflict', sprintf( '/llms.txt conflict detected (%s).', $kinds_csv ), array( $kinds_csv ) );
		}

		return array(
			'value'       => self::clamp( $score ),
			'weight'      => self::WEIGHTS['integration_health'],
			'signals'     => array(
				'llm_descriptions_enabled' => $desc_on,
				'ai_client_configured'     => $client_cfg,
				'conflict_count'           => count( $conflicts ),
			),
			'reasons'     => $reasons,
			'reason_keys' => $reason_keys,
		);
	}

	/**
	 * MD conversion quality — how clean is the deterministic walker output
	 * for the cached pages?
	 *
	 * Signals:
	 *   - Mean MD `quality_score` across cached rows (60 pts at 100, scales linearly)
	 *   - % of rows above the MD-quality threshold    (40 pts at 100%, scales linearly)
	 *
	 * A site with zero cached MD rows scores 0 (nothing to evaluate).
	 *
	 * @param array<string, mixed> $signals
	 *
	 * @return array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}
	 */
	private static function score_md_conversion_quality( array $signals ): array {
		$md          = self::array_at( $signals, 'md_cache' );
		$rows        = (int) ( $md['rows_total'] ?? 0 );
		$scored      = (int) ( $md['rows_with_score'] ?? 0 );
		$mean        = (float) ( $md['mean_quality'] ?? 0.0 );
		$above       = (int) ( $md['rows_above_threshold'] ?? 0 );
		$thresh      = (int) ( $md['md_quality_threshold'] ?? self::MD_QUALITY_THRESHOLD );
		$reasons     = array();
		$reason_keys = array();

		if ( $rows <= 0 || $scored <= 0 ) {
			self::add_reason( $reasons, $reason_keys, 'mcq_no_cache', 'No Markdown Views cache rows yet — visit a few `.md` URLs to populate the cache.' );
			return array(
				'value'       => 0,
				'weight'      => self::WEIGHTS['md_conversion_quality'],
				'signals'     => array(
					'rows_total'           => $rows,
					'rows_with_score'      => $scored,
					'mean_quality'         => 0,
					'rows_above_threshold' => 0,
					'md_quality_threshold' => $thresh,
				),
				'reasons'     => $reasons,
				'reason_keys' => $reason_keys,
			);
		}

		$mean_int  = (int) floor( $mean );
		$above_pct = (int) floor( ( $above * 100 ) / $scored );
		$mean_pts  = (int) floor( ( $mean_int * 60 ) / 100 );
		$above_pts = (int) floor( ( $above_pct * 40 ) / 100 );
		$score     = $mean_pts + $above_pts;

		self::add_reason( $reasons, $reason_keys, 'mcq_mean_quality', sprintf( 'Mean Markdown quality across %d cached posts: %d/100.', $scored, $mean_int ), array( $scored, $mean_int ) );
		self::add_reason( $reasons, $reason_keys, 'mcq_above_threshold', sprintf( '%d%% of cached posts are above the MD-quality threshold (%d).', $above_pct, $thresh ), array( $above_pct, $thresh ) );

		return array(
			'value'       => self::clamp( $score ),
			'weight'      => self::WEIGHTS['md_conversion_quality'],
			'signals'     => array(
				'rows_total'           => $rows,
				'rows_with_score'      => $scored,
				'mean_quality'         => $mean_int,
				'rows_above_threshold' => $above,
				'above_threshold_pct'  => $above_pct,
				'md_quality_threshold' => $thresh,
			),
			'reasons'     => $reasons,
			'reason_keys' => $reason_keys,
		);
	}

	/**
	 * Multi-channel discovery — how many agent-discovery surfaces does the
	 * site publish, across Mokhai's own channels and complementary
	 * sibling plugins (#22 / AgDR-0043)?
	 *
	 * Five surfaces, each worth 20 points (sum 100):
	 *   - `/llms.txt` cache populated         (re-credits the existing signal)
	 *   - `ai.txt` at the WordPress install root
	 *   - `/.well-known/ai-layer` (file probe OR registered sibling provider)
	 *   - `/.well-known/llms-policy.json`
	 *   - OpenAPI / Swagger spec at `ABSPATH/{openapi.json,openapi.yaml,swagger.json}`
	 *
	 * When a registered sibling provider (e.g. AI Layer) is detected, an
	 * extra reason string names the plugin and points at its admin page so
	 * the narrative can render a one-click "Configure at X" affordance. The
	 * /llms.txt re-credit is intentional — the AC lists it as one of the
	 * five surfaces; double-credit with `discoverability` is the cost of
	 * giving sites a coherent "how many channels" reading.
	 *
	 * Conflict warnings for competing /llms.txt plugins stay in
	 * `discoverability` (rewrite_conflicted signal) — this sub-score only
	 * emits positive credit reasons, so the AC's "no double-warning"
	 * requirement is satisfied by construction.
	 *
	 * @param array<string, mixed> $signals
	 *
	 * @return array{value: int, weight: int, signals: array<string, mixed>, reasons: array<int, string>, reason_keys: array<int, array{code: string, args: array<int, scalar>}>}
	 */
	private static function score_multi_channel_discovery( array $signals ): array {
		$bundle = self::array_at( $signals, 'multi_channel_discovery' );

		$llms_txt        = (bool) ( $bundle['llms_txt_present'] ?? false );
		$ai_txt          = (bool) ( $bundle['ai_txt_present'] ?? false );
		$wk_ai_layer     = (bool) ( $bundle['well_known_ai_layer'] ?? false );
		$wk_llms_policy  = (bool) ( $bundle['well_known_llms_policy'] ?? false );
		$openapi         = (bool) ( $bundle['openapi_spec_present'] ?? false );
		$active_provider = ( isset( $bundle['active_provider'] ) && is_array( $bundle['active_provider'] ) )
			? $bundle['active_provider']
			: null;

		// The four plugin-served channels each contribute 25 points so a
		// plugin-only install can reach 100 on the surfaces it actually serves.
		// OpenAPI is a bonus channel (AgDR-0058 / #212): detected and credited
		// in the narrative for API-exposing sites, but it does not change the
		// score — the plugin can't generate an OpenAPI spec for a REST surface
		// it doesn't own, so gating the score on it created a dead-end gauge.
		$core_present = (int) $llms_txt + (int) $ai_txt + (int) $wk_ai_layer + (int) $wk_llms_policy;
		$score        = $core_present * 25;
		$reasons      = array();
		$reason_keys  = array();

		if ( $core_present <= 0 ) {
			self::add_reason( $reasons, $reason_keys, 'mcd_no_channels', 'No agent-discovery channels detected — site is invisible to agents that scan for ai.txt or /.well-known/ declarations.' );
		} else {
			self::add_reason( $reasons, $reason_keys, 'mcd_channels_detected', sprintf( '%d of 4 plugin-served agent-discovery channel(s) detected.', $core_present ), array( $core_present ) );
		}

		if ( $openapi ) {
			self::add_reason( $reasons, $reason_keys, 'mcd_openapi_bonus', 'OpenAPI spec detected — bonus discovery channel for sites exposing an API.' );
		}

		if ( null !== $active_provider && $wk_ai_layer ) {
			$name       = isset( $active_provider['name'] ) ? (string) $active_provider['name'] : 'Sibling provider';
			$config_url = isset( $active_provider['config_url'] ) ? (string) $active_provider['config_url'] : '';
			if ( '' !== $config_url ) {
				self::add_reason( $reasons, $reason_keys, 'mcd_provider_configurable', sprintf( '%s detected — coordinating multi-channel discovery. Configure at %s', $name, $config_url ), array( $name, $config_url ) );
			} else {
				self::add_reason( $reasons, $reason_keys, 'mcd_provider_detected', sprintf( '%s detected — coordinating multi-channel discovery.', $name ), array( $name ) );
			}
		}

		return array(
			'value'       => self::clamp( $score ),
			'weight'      => self::WEIGHTS['multi_channel_discovery'],
			'signals'     => array(
				'llms_txt_present'       => $llms_txt,
				'ai_txt_present'         => $ai_txt,
				'well_known_ai_layer'    => $wk_ai_layer,
				'well_known_llms_policy' => $wk_llms_policy,
				'openapi_spec_present'   => $openapi,
				'surfaces_present_count' => $core_present,
				'active_provider'        => null !== $active_provider
					? array(
						'slug'       => (string) ( $active_provider['slug'] ?? '' ),
						'name'       => (string) ( $active_provider['name'] ?? '' ),
						'config_url' => (string) ( $active_provider['config_url'] ?? '' ),
					)
					: null,
			),
			'reasons'     => $reasons,
			'reason_keys' => $reason_keys,
		);
	}

	/**
	 * Resolve a nested array from `$signals`, defaulting to empty array when
	 * absent or wrong type. Lets the per-sub-score code skip is_array
	 * guards in the hot path without trusting caller-shape.
	 *
	 * @param array<string, mixed> $haystack
	 *
	 * @return array<mixed>
	 */
	private static function array_at( array $haystack, string $key ): array {
		return isset( $haystack[ $key ] ) && is_array( $haystack[ $key ] )
			? $haystack[ $key ]
			: array();
	}

	/**
	 * Clamp a score to the 0..100 range so a buggy per-sub-score formula
	 * cannot produce out-of-band values that break the overall calculation.
	 */
	private static function clamp( int $value ): int {
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 100 ) {
			return 100;
		}
		return $value;
	}

	/**
	 * Append a reason to both the English `reasons` array and the parallel
	 * `reason_keys` array in lockstep (#139 / AgDR-0047).
	 *
	 * `$text` is the pre-rendered English string (kept for the LLM fact-path,
	 * `Narrative_Guard`, and as the admin UI's fallback). `$code` is a stable
	 * machine token the admin bundle maps to a translatable `__()` template,
	 * and `$args` are the positional values that template's placeholders
	 * consume — the SAME values used to render `$text`, so the two
	 * representations can never drift.
	 *
	 * Stays pure PHP: no WordPress calls. Engine never translates; it only
	 * carries the code + args so the display layer can.
	 *
	 * @param array<int, string>                                       $reasons     English reason strings (by ref).
	 * @param array<int, array{code: string, args: array<int, scalar>}> $reason_keys Parallel token array (by ref).
	 * @param string                                                   $code        Stable reason code.
	 * @param string                                                   $text        Pre-rendered English reason.
	 * @param array<int, scalar>                                       $args        Positional template args.
	 */
	private static function add_reason( array &$reasons, array &$reason_keys, string $code, string $text, array $args = array() ): void {
		$reasons[]     = $text;
		$reason_keys[] = array(
			'code' => $code,
			'args' => array_values( $args ),
		);
	}
}
