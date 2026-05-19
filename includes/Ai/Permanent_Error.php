<?php
/**
 * Permanent-error exception thrown by Provider implementations.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Ai;

\defined( 'ABSPATH' ) || exit;

/**
 * Signal a non-retryable provider failure — typically a 4xx HTTP response
 * (parameter validation, auth, not-found, unsupported media, unprocessable
 * entity) where re-sending the same payload will fail identically.
 *
 * Client_Wrapper does NOT retry this class either in-request or via the
 * deferred-retry cron event. The caller receives a Result with
 * `needs_retry=false` and `error_code='permanent'` so it falls through to
 * its deterministic fallback and stops trying. See AgDR-0026.
 */
final class Permanent_Error extends \RuntimeException {
}
