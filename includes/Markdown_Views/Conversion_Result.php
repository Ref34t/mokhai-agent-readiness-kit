<?php
/**
 * Walker output value object per AgDR-0017.
 *
 * Replaces the bare `string` return of `Walker::convert()`. Carries the
 * rendered markdown, a 0–100 quality score, and the raw signal counts
 * + rates that contributed to the score. Persisting raw counts (not
 * just the score) lets the admin UI explain *why* a post triggered
 * cleanup — "12 untransformed shortcodes detected" instead of just
 * "score 30".
 *
 * Immutable. PHP 7.4 floor — no `readonly` keyword; use private +
 * getters.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * The signals array structure is the durable contract; weights in
 * Walker::QUALITY_WEIGHTS are tunable. See AgDR-0017 for the formula
 * and AgDR-0011 for how this object's contents map to the cache row.
 */
final class Conversion_Result {

	/**
	 * @var string
	 */
	private $markdown;

	/**
	 * 0..100 inclusive.
	 *
	 * @var int
	 */
	private $quality_score;

	/**
	 * Signal map. Keys are signal names; values are normalised rates in
	 * `[0, 1]` plus their underlying raw counts.
	 *
	 * Stable keys (v0.1):
	 *   - tag_strip_rate / tag_strip_count / tag_total_count
	 *   - orphan_inline_style_rate / orphan_inline_style_count
	 *   - table_fragment_rate / table_fragment_count / table_total_count
	 *   - deep_div_nesting_rate / deep_div_count / div_total_count
	 *   - image_only_paragraph_rate / image_only_paragraph_count / paragraph_total_count
	 *   - empty_line_run_rate / empty_line_run_count
	 *   - shortcode_residue_rate / shortcode_residue_count
	 *
	 * @var array<string, int|float>
	 */
	private $signals;

	/**
	 * @param array<string, int|float> $signals
	 */
	public function __construct( string $markdown, int $quality_score, array $signals ) {
		$this->markdown      = $markdown;
		$this->quality_score = $quality_score < 0 ? 0 : ( $quality_score > 100 ? 100 : $quality_score );
		$this->signals       = $signals;
	}

	public function get_markdown(): string {
		return $this->markdown;
	}

	public function get_quality_score(): int {
		return $this->quality_score;
	}

	/**
	 * @return array<string, int|float>
	 */
	public function get_signals(): array {
		return $this->signals;
	}
}
