<?php
/**
 * Cleanup_Guard outcome value object per AgDR-0018.
 *
 * Carries the post-filter markdown plus a diagnostic record describing
 * which sentences were dropped and why. The diagnostic survives into
 * post-meta so the Phase B admin UI can render "this cleanup attempt
 * dropped 3 sentences because token X was hallucinated" without
 * re-running the guard.
 *
 * Immutable. PHP 7.4 floor — no `readonly`.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * Records the verdict of one pass through `Cleanup_Guard::check()`.
 *
 * `failed_overall()` is the kill-switch read: when true, the caller
 * (Cleanup_Orchestrator) discards the cleaned MD entirely and falls
 * back to the deterministic version + marks the post `needs-retry`.
 */
final class Guard_Result {

	/**
	 * @var string
	 */
	private $filtered_markdown;

	/**
	 * @var array<string, int>
	 */
	private $stats;

	/**
	 * Per-stripped-sentence detail records. Each entry contains:
	 *   - sentence : string (the original LLM-output sentence)
	 *   - stage    : 'allowlist' | 'entity'
	 *   - tokens   : array<string>   (allowlist stage: words not in source)
	 *   - entity   : string|null     (entity stage: the offending name)
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $dropped;

	/**
	 * @var bool
	 */
	private $failed_overall;

	/**
	 * @param array<string, int>                       $stats
	 * @param array<int, array<string, mixed>>         $dropped
	 */
	public function __construct(
		string $filtered_markdown,
		array $stats,
		array $dropped,
		bool $failed_overall
	) {
		$this->filtered_markdown = $filtered_markdown;
		$this->stats             = $stats;
		$this->dropped           = $dropped;
		$this->failed_overall    = $failed_overall;
	}

	public function get_filtered_markdown(): string {
		return $this->filtered_markdown;
	}

	/**
	 * @return array<string, int> keys: sentences_kept, sentences_dropped
	 */
	public function get_stats(): array {
		return $this->stats;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_dropped(): array {
		return $this->dropped;
	}

	/**
	 * True when the drop ratio crossed `Cleanup_Guard::FAILURE_RATIO_THRESHOLD`
	 * — caller should discard the whole cleanup and serve deterministic.
	 */
	public function failed_overall(): bool {
		return $this->failed_overall;
	}
}
