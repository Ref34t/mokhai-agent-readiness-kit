<?php
/**
 * Rate-limit exception thrown by Provider implementations.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Ai;

\defined( 'ABSPATH' ) || exit;

/**
 * Signal that the provider rate-limited the request. Client_Wrapper does
 * not retry rate-limits in-request — it queues a deferred retry via
 * wp_schedule_single_event and returns the deterministic-fallback Result.
 */
final class Rate_Limit_Error extends \RuntimeException {
}
