<?php
/**
 * WP-CLI command surface for the Context Score module (#9 / AgDR-0030).
 *
 * Mounted at `wp mokhai context-score` following AgDR-0014's CLI
 * convention. The AC ticket wording `wp context audit` is satisfied by
 * the `audit` subcommand here — JSON output by default, `--porcelain`
 * for key=value lines.
 *
 * Subcommands:
 *   - `audit`     — emit the cached breakdown (recomputes synchronously
 *                   when the cache is empty or schema-stale).
 *   - `recompute` — force a synchronous recompute, bypassing the cache.
 *   - `reset`     — drop the cached payload (next read will recompute).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Cli;

use Mokhai\Context_Score\Service;

\defined( 'ABSPATH' ) || exit;

/**
 * Manage the Context Score from the command line.
 *
 * ## EXAMPLES
 *
 *     # Print the cached breakdown as JSON. Recomputes if the cache is empty.
 *     $ wp mokhai context-score audit
 *
 *     # Print the breakdown as key=value lines (shell-friendly).
 *     $ wp mokhai context-score audit --porcelain
 *
 *     # Force a synchronous recompute regardless of cache state.
 *     $ wp mokhai context-score recompute
 *
 *     # Drop the cached payload (next read recomputes).
 *     $ wp mokhai context-score reset
 */
final class Context_Score_Command {

	/**
	 * Register the `agentready context-score` command tree with WP-CLI.
	 *
	 * Called from `Main::register_hooks()` after the WP_CLI runtime guard.
	 * No-op when WP-CLI isn't active so the regular page-load path pays
	 * zero cost.
	 */
	public static function register(): void {
		if ( ! \defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'mokhai context-score', self::class );
	}

	/**
	 * Emit the cached Context Score breakdown.
	 *
	 * Recomputes synchronously when the cache is empty or the stored
	 * schema_version doesn't match the current code. Default output is the
	 * full JSON payload (overall + per-sub-score value/weight/signals/reasons).
	 *
	 * ## OPTIONS
	 *
	 * [--porcelain]
	 * : Emit one `key=value` line per overall + sub-score value field instead
	 *   of the JSON payload. Useful for piping into shell scripts.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mokhai context-score audit | jq .overall
	 *     64
	 *
	 *     $ wp mokhai context-score audit --porcelain
	 *     overall=64
	 *     discoverability=80
	 *     content_readability=62
	 *     ...
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 */
	public function audit( array $args, array $assoc_args ): void {
		unset( $args );

		$payload = Service::get_breakdown();
		if ( null === $payload ) {
			$payload = Service::recompute_now();
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( 'overall=' . (int) ( $payload['overall'] ?? 0 ) );
			$sub_scores = isset( $payload['sub_scores'] ) && is_array( $payload['sub_scores'] )
				? $payload['sub_scores']
				: array();
			foreach ( $sub_scores as $name => $sub ) {
				$value = is_array( $sub ) && isset( $sub['value'] ) ? (int) $sub['value'] : 0;
				\WP_CLI::line( $name . '=' . $value );
			}
			return;
		}

		$json = \wp_json_encode( $payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			\WP_CLI::error( 'Failed to encode Context Score breakdown as JSON.' );
			return;
		}
		\WP_CLI::line( $json );
	}

	/**
	 * Force a synchronous recompute, bypassing the cache.
	 *
	 * Reports the overall score and duration so CI smoke tests can assert
	 * the engine still produces a result inside the 10-second budget.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mokhai context-score recompute
	 *     Success: Context Score recomputed: 64/100 (842 ms).
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (unused).
	 */
	public function recompute( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$payload = Service::recompute_now();

		\WP_CLI::success(
			sprintf(
				'Context Score recomputed: %d/100 (%d ms).',
				(int) ( $payload['overall'] ?? 0 ),
				(int) ( $payload['recompute_duration_ms'] ?? 0 )
			)
		);
	}

	/**
	 * Drop the cached Context Score breakdown. The next reader recomputes.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mokhai context-score reset
	 *     Success: Context Score cache cleared.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (unused).
	 */
	public function reset( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		Service::invalidate();

		\WP_CLI::success( 'Context Score cache cleared.' );
	}
}
