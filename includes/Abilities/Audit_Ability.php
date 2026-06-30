<?php
/**
 * `ai-readiness-kit/audit-run` ability (#21 / AgDR-0044).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Abilities;

use Mokhai\Context_Score\Service;

\defined( 'ABSPATH' ) || exit;

/**
 * Recompute the Context Score synchronously and return the fresh breakdown.
 *
 * Thin wrapper over `Context_Score\Service::recompute_now()` — the same
 * entrypoint the admin "Recompute now" button and the
 * `POST /context-score/recompute` REST route use, so an agent invocation
 * produces an identical result to the admin UI.
 */
final class Audit_Ability {

	/**
	 * Stable ability ID.
	 *
	 * @var string
	 */
	public const ID        = 'mokhai/audit-run';
	public const LEGACY_ID = 'ai-readiness-kit/audit-run';

	/**
	 * Execute callback. Takes no input; returns the breakdown payload.
	 *
	 * @param mixed $input Validated ability input (unused — no parameters).
	 *
	 * @return array<string, mixed> The recomputed Context Score breakdown.
	 */
	public static function run( $input = null ): array {
		unset( $input );

		return Service::recompute_now();
	}
}
