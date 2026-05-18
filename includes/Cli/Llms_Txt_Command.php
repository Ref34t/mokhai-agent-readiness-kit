<?php
/**
 * WP-CLI command surface for the LLMs Index module (#7 / AgDR-0022).
 *
 * Two subcommands:
 *   - `wp agentready llms-txt status` — diagnostic report (cache state,
 *     generated_at timestamp, entry count, next scheduled regen).
 *   - `wp agentready llms-txt regen`  — synchronous regen bypassing the
 *     cron debounce. Useful for support workflows and CI smoke tests.
 *
 * Mirrors the convention `Markdown_Views_Command` established under
 * `wp agentready md *`.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Cli;

use WPContext\LlmsTxt\Service;

\defined( 'ABSPATH' ) || exit;

/**
 * Manage the `/llms.txt` index from the command line.
 *
 * ## EXAMPLES
 *
 *     # Show cache state, last regen timestamp, scheduled events.
 *     $ wp agentready llms-txt status
 *
 *     # Force a synchronous regen — bypasses the 5-second cron debounce.
 *     $ wp agentready llms-txt regen
 *
 *     # Print the composed body to stdout without writing the cache.
 *     $ wp agentready llms-txt preview
 */
final class Llms_Txt_Command {

	/**
	 * Register the `agentready llms-txt` command tree with WP-CLI.
	 *
	 * Called from `Main::register_hooks()` after the WP_CLI runtime guard.
	 * No-op when WP-CLI isn't active so the regular page-load path pays
	 * zero cost.
	 */
	public static function register(): void {
		if ( ! \defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'agentready llms-txt', self::class );
	}

	/**
	 * Report the current cache state and scheduled regen events.
	 *
	 * ## OPTIONS
	 *
	 * [--porcelain]
	 * : Emit one `key=value` line per field instead of the human-readable
	 *   table. Useful for piping into other tools.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp agentready llms-txt status
	 *     +------------------------+------------------------------------+
	 *     | Field                  | Value                              |
	 *     +------------------------+------------------------------------+
	 *     | cache_populated        | yes                                |
	 *     | generated_at           | 2026-05-18T17:24:33+00:00          |
	 *     | entry_count            | 142                                |
	 *     | body_bytes             | 11823                              |
	 *     | regen_lock_held        | no                                 |
	 *     | next_debounced_regen   | (none pending)                     |
	 *     | next_daily_regen       | 2026-05-19T05:00:00+00:00          |
	 *     +------------------------+------------------------------------+
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$cache    = Service::get_cache_payload();
		$lock     = \get_transient( Service::REGEN_LOCK_TRANSIENT );
		$debounce = \wp_next_scheduled( Service::REGEN_ACTION );
		$daily    = \wp_next_scheduled( Service::DAILY_REGEN_ACTION );

		$fields = array(
			'cache_populated'      => null === $cache ? 'no' : 'yes',
			'generated_at'         => null !== $cache && isset( $cache['generated_at'] )
				? (string) $cache['generated_at']
				: '(never)',
			'entry_count'          => null !== $cache && isset( $cache['entry_count'] )
				? (string) (int) $cache['entry_count']
				: '0',
			'body_bytes'           => null !== $cache && isset( $cache['body'] )
				? (string) strlen( (string) $cache['body'] )
				: '0',
			'regen_lock_held'      => false !== $lock ? 'yes' : 'no',
			'next_debounced_regen' => false !== $debounce
				? \gmdate( 'c', (int) $debounce )
				: '(none pending)',
			'next_daily_regen'     => false !== $daily
				? \gmdate( 'c', (int) $daily )
				: '(none scheduled)',
		);

		if ( isset( $assoc_args['porcelain'] ) ) {
			foreach ( $fields as $key => $value ) {
				\WP_CLI::line( $key . '=' . $value );
			}
			return;
		}

		$rows = array();
		foreach ( $fields as $key => $value ) {
			$rows[] = array(
				'Field' => $key,
				'Value' => $value,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );
	}

	/**
	 * Force a synchronous regen, bypassing the cron debounce window.
	 *
	 * Reports the result (body size + entry count) so CI smoke tests can
	 * assert non-empty output.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp agentready llms-txt regen
	 *     Success: Regenerated /llms.txt (11823 bytes, 142 entries).
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (unused).
	 */
	public function regen( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$body  = Service::regen_sync();
		$bytes = strlen( $body );
		$cache = Service::get_cache_payload();
		$count = null !== $cache && isset( $cache['entry_count'] )
			? (int) $cache['entry_count']
			: 0;

		\WP_CLI::success(
			sprintf(
				'Regenerated /llms.txt (%d bytes, %d entries).',
				$bytes,
				$count
			)
		);
	}

	/**
	 * Compose the body and print to stdout without writing the cache.
	 *
	 * Useful for previewing the next regen output without touching site
	 * state. Equivalent to fetching `/llms.txt` after a fresh regen.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp agentready llms-txt preview | head -20
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (unused).
	 */
	public function preview( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$body = Service::compose_now();
		\WP_CLI::line( $body );
	}
}
