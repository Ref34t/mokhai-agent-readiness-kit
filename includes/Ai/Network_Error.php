<?php
/**
 * Network-error exception thrown by Provider implementations.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Ai;

\defined( 'ABSPATH' ) || exit;

/**
 * Signal a transient network failure that Client_Wrapper should retry once
 * within the same request before falling back to deferred-retry mode.
 */
final class Network_Error extends \RuntimeException {
}
