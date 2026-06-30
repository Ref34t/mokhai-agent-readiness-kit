<?php
/**
 * Result value object returned by Client_Wrapper::generate.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Ai;

\defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of one Client_Wrapper::generate outcome.
 *
 * Callers branch on:
 *   - `$result->from_llm()`   — was the content produced by the LLM, or
 *                               is the caller responsible for supplying a
 *                               deterministic fallback?
 *   - `$result->needs_retry()` — should the caller mark the post / score
 *                               as needs-retry so a deferred attempt is
 *                               surfaced in the UI?
 *   - `$result->content()`     — the LLM-produced string, or null if the
 *                               caller must use its deterministic fallback.
 *   - `$result->error_code()`  — one of 'unconfigured' | 'rate_limit' |
 *                               'permanent' | 'network' | 'unknown' | null.
 *
 * PHP 7.4 floor (per ticket #1 / AgDR-0001) — readonly properties (PHP 8.1)
 * are not available, so immutability is enforced by private properties and
 * the absence of setters.
 */
final class Result {

	/**
	 * Whether the content came from the LLM (true) or the caller's
	 * deterministic fallback path (false).
	 *
	 * @var bool
	 */
	private $from_llm;

	/**
	 * Whether the caller should mark the source as needs-retry.
	 *
	 * @var bool
	 */
	private $needs_retry;

	/**
	 * LLM-produced content, or null when the caller must use its fallback.
	 *
	 * @var string|null
	 */
	private $content;

	/**
	 * Failure classification, or null on success.
	 *
	 * @var string|null
	 */
	private $error_code;

	/**
	 * @param bool        $from_llm    Whether content is from the LLM.
	 * @param bool        $needs_retry Whether the caller should mark needs-retry.
	 * @param string|null $content     LLM content, or null.
	 * @param string|null $error_code  Failure classification, or null.
	 */
	public function __construct( bool $from_llm, bool $needs_retry, ?string $content, ?string $error_code ) {
		$this->from_llm    = $from_llm;
		$this->needs_retry = $needs_retry;
		$this->content     = $content;
		$this->error_code  = $error_code;
	}

	public function from_llm(): bool {
		return $this->from_llm;
	}

	public function needs_retry(): bool {
		return $this->needs_retry;
	}

	public function content(): ?string {
		return $this->content;
	}

	public function error_code(): ?string {
		return $this->error_code;
	}
}
