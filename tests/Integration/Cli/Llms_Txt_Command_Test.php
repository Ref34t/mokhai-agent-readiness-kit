<?php
/**
 * Integration tests for Mokhai\Cli\Llms_Txt_Command.
 *
 * Covers the WP-CLI surface from #7 Phase A / AgDR-0022:
 *   - `wp ai-readiness-kit llms-txt status`  (porcelain + table output)
 *   - `wp ai-readiness-kit llms-txt regen`   (synchronous regen + success message)
 *   - `wp ai-readiness-kit llms-txt preview` (compose without writing cache)
 *
 * WP-CLI is not loaded inside wp-phpunit, so this file ships a minimal
 * shim for `WP_CLI`, `WP_CLI\NoExitException`, and `WP_CLI\Utils\format_items`
 * that captures output for assertion. The shim is only defined when the
 * symbol is missing — production WP-CLI takes precedence if ever loaded.
 *
 * The plugin's `Llms_Txt_Command::register()` is a no-op without the
 * `WP_CLI` *constant*; instance methods (`status`, `regen`, `preview`)
 * just call `\WP_CLI::*` and are exercised here directly.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Cli;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Cli\Llms_Txt_Command;
use Mokhai\LlmsTxt\Conflict_Detector;
use Mokhai\LlmsTxt\Conflict_Notice;
use Mokhai\LlmsTxt\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

/*
 * --- WP-CLI shim (minimal) -------------------------------------------------
 *
 * wp-phpunit boots core but doesn't include WP-CLI. The CLI command class
 * references `\WP_CLI::line/success/error` and `\WP_CLI\Utils\format_items`
 * at method-call time, so we need both available as real symbols (not just
 * the `WP_CLI` constant) before the methods run.
 *
 * The shim captures all output into a static buffer on `\WP_CLI` so each
 * test can read it back. `success()` is implemented as plain output capture
 * (NOT an exception) because the command's `regen()` method has no
 * post-`success()` code — we just need to observe the message text. If a
 * future command path calls `WP_CLI::success()` mid-method and expects flow
 * to halt, switch this to throw the bundled `WP_CLI\ExitException` shim.
 */
if ( ! class_exists( '\\WP_CLI' ) ) {
	// Declared in a `namespace {}` block via eval() so the class lands in
	// the root namespace, not Mokhai\Tests\Integration\Cli.
	eval(
		'namespace {
			class WP_CLI {
				public static $lines = array();
				public static $successes = array();
				public static $errors = array();

				public static function reset() {
					self::$lines = array();
					self::$successes = array();
					self::$errors = array();
				}

				public static function line( $message = "" ) {
					self::$lines[] = (string) $message;
				}

				public static function success( $message ) {
					self::$successes[] = (string) $message;
				}

				public static function error( $message, $exit = true ) {
					self::$errors[] = (string) $message;
				}

				public static function warning( $message ) {
					self::$lines[] = "Warning: " . (string) $message;
				}

				public static function log( $message ) {
					self::$lines[] = (string) $message;
				}

				public static function add_command( $name, $class ) {
					// no-op: tests bypass the command registry and call methods directly.
				}
			}
		}'
	);
}

if ( ! function_exists( 'Mokhai\\Tests\\Integration\\Cli\\__wp_cli_utils_format_items_shim' ) ) {
	/**
	 * Internal: shim for `\WP_CLI\Utils\format_items`. Renders `$items`
	 * as a CSV-ish "Field=Value" block on `\WP_CLI::$lines` so tests
	 * can assert which fields the table form emits without depending
	 * on WP-CLI's table formatter.
	 *
	 * @param string                                           $format Unused.
	 * @param array<int, array<string, string>>                $items  Rows.
	 * @param array<int, string>|string                        $fields Column names.
	 */
	function __wp_cli_utils_format_items_shim( string $format, array $items, $fields ): void {
		foreach ( $items as $row ) {
			$pairs = array();
			foreach ( $row as $key => $value ) {
				$pairs[] = $key . '=' . $value;
			}
			\WP_CLI::line( implode( '|', $pairs ) );
		}
	}
}

if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	// Declared in a namespaced block so it lands in the WP_CLI\Utils namespace
	// where the command code references it. Done via eval() because PHP
	// doesn't allow opening a fresh namespace block inside an if-statement.
	eval(
		'namespace WP_CLI\\Utils;
		function format_items( $format, $items, $fields ) {
			\\Mokhai\\Tests\\Integration\\Cli\\__wp_cli_utils_format_items_shim( $format, $items, $fields );
		}'
	);
}

/**
 * Integration tests for the `wp ai-readiness-kit llms-txt` command surface.
 *
 * Each test runs against the live wp-phpunit environment: real options,
 * real cron, real transients. Service::invalidate() + option cleanup in
 * setUp/tearDown mirror Service_Test.php so the cache schema is in a
 * known state.
 */
final class Llms_Txt_Command_Test extends WP_UnitTestCase {

	private Llms_Txt_Command $command;

	protected function setUp(): void {
		parent::setUp();

		// wp-env activated the plugin during env-boot, which dropped its
		// markdown-views cache table outside the per-test temp-table
		// rewrite. Re-create it for every test that touches the post
		// lifecycle (same pattern as Service_Test::setUp()).
		Markdown_Views_Schema::create();

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		delete_option( 'agentready_llms_txt_editorial' );

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		// Clear any regen scheduled by the profile-save hook chain so each
		// test starts from a deterministic "no scheduled" state.
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );

		// Reset conflict-detector state so `status` reports a clean baseline
		// unless a test explicitly stages a conflict fixture below.
		Conflict_Notice::invalidate_cache();
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		\WP_CLI::reset();

		$this->command = new Llms_Txt_Command();
	}

	protected function tearDown(): void {
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
		delete_option( 'agentready_llms_txt_editorial' );

		// Strip any conflict fixture so it can't leak into a sibling test.
		Conflict_Notice::invalidate_cache();
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		Markdown_Views_Schema::drop();

		\WP_CLI::reset();

		parent::tearDown();
	}

	/**
	 * The status subcommand's porcelain output must list every field the
	 * status report tracks — pinning the wire format consumed by support
	 * tooling and CI smoke checks.
	 */
	public function test_status_porcelain_output_includes_all_fields(): void {
		$this->command->status( array(), array( 'porcelain' => true ) );

		$expected_keys = array(
			'cache_populated',
			'generated_at',
			'entry_count',
			'body_bytes',
			'regen_lock_held',
			'next_debounced_regen',
			'next_daily_regen',
			'conflicts_detected',
		);

		// Each field should appear exactly once as `key=value`.
		foreach ( $expected_keys as $key ) {
			$matching = array_filter(
				\WP_CLI::$lines,
				static fn( string $line ): bool => str_starts_with( $line, $key . '=' )
			);
			$this->assertCount(
				1,
				$matching,
				sprintf( 'Porcelain output must emit `%s=<value>` exactly once.', $key )
			);
		}

		// Empty cache + no scheduled events: assert the deterministic baseline.
		$this->assertContains( 'cache_populated=no', \WP_CLI::$lines );
		$this->assertContains( 'generated_at=(never)', \WP_CLI::$lines );
		$this->assertContains( 'entry_count=0', \WP_CLI::$lines );
		$this->assertContains( 'body_bytes=0', \WP_CLI::$lines );
		$this->assertContains( 'regen_lock_held=no', \WP_CLI::$lines );
		$this->assertContains( 'next_debounced_regen=(none pending)', \WP_CLI::$lines );
		$this->assertContains( 'next_daily_regen=(none scheduled)', \WP_CLI::$lines );
	}

	/**
	 * The status subcommand's default (table) output must surface every
	 * field too — different formatting, identical field coverage.
	 */
	public function test_status_table_output_includes_all_fields(): void {
		$this->command->status( array(), array() );

		// The shim renders table rows as `Field=<name>|Value=<value>` lines.
		$rendered = implode( "\n", \WP_CLI::$lines );

		foreach (
			array(
				'cache_populated',
				'generated_at',
				'entry_count',
				'body_bytes',
				'regen_lock_held',
				'next_debounced_regen',
				'next_daily_regen',
				'conflicts_detected',
			) as $field
		) {
			$this->assertStringContainsString(
				'Field=' . $field,
				$rendered,
				sprintf( 'Table output must include the `%s` field row.', $field )
			);
		}

		// Table output should NOT emit porcelain `key=value` lines.
		$this->assertNotContains( 'cache_populated=no', \WP_CLI::$lines );
	}

	/**
	 * After a real post exists, regen must write the cache option and the
	 * success message must contain both byte count and entry count.
	 */
	public function test_regen_writes_cache_and_returns_success_with_body_bytes_and_entry_count(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Regen target',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->assertNull( Service::get_cache_payload(), 'Cache must start empty.' );

		$this->command->regen( array(), array() );

		// Cache populated as a side-effect.
		$cache = Service::get_cache_payload();
		$this->assertIsArray( $cache, 'Regen must populate the cache option.' );
		$this->assertArrayHasKey( 'body', $cache );
		$this->assertArrayHasKey( 'entry_count', $cache );
		$this->assertGreaterThan( 0, strlen( (string) $cache['body'] ) );

		// Success message captured.
		$this->assertCount( 1, \WP_CLI::$successes, 'Regen must emit exactly one success message.' );

		$message = \WP_CLI::$successes[0];
		$this->assertStringContainsString( (string) strlen( (string) $cache['body'] ) . ' bytes', $message );
		$this->assertStringContainsString( (string) (int) $cache['entry_count'] . ' entries', $message );
	}

	/**
	 * Lock the success-message format so a future copy edit is caught at
	 * CI time, not in production support workflows.
	 *
	 *   "Regenerated /llms.txt (<bytes> bytes, <count> entries)."
	 */
	public function test_regen_success_message_format_is_pinned(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Format pin',
				'post_status' => 'publish',
			)
		);

		$this->command->regen( array(), array() );

		$this->assertCount( 1, \WP_CLI::$successes );

		$cache    = Service::get_cache_payload();
		$bytes    = strlen( (string) $cache['body'] );
		$entries  = (int) $cache['entry_count'];
		$expected = sprintf( 'Regenerated /llms.txt (%d bytes, %d entries).', $bytes, $entries );

		$this->assertSame(
			$expected,
			\WP_CLI::$successes[0],
			'Regen success-message format must remain stable across releases.'
		);
	}

	/**
	 * Preview must compose the body and print it to stdout, but it must
	 * NOT write the cache option — that's regen's job.
	 */
	public function test_preview_composes_without_writing_cache(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Preview only',
				'post_status' => 'publish',
			)
		);

		$this->assertNull( Service::get_cache_payload(), 'Cache must start empty.' );

		$this->command->preview( array(), array() );

		// Cache MUST remain empty (preview is read-only relative to options).
		$this->assertNull(
			Service::get_cache_payload(),
			'Preview must not populate the cache option.'
		);

		// Exactly one line should have been emitted (the composed body).
		$this->assertCount( 1, \WP_CLI::$lines, 'Preview must emit exactly one line (the composed body).' );
		$this->assertStringContainsString(
			'Preview only',
			\WP_CLI::$lines[0],
			'Preview output must include the post title from the composed body.'
		);
	}

	/**
	 * Stage a rewrite-rule conflict (cheapest fixture — no filesystem write,
	 * no plugin-option mutation) and assert `status --porcelain` surfaces
	 * `conflicts_detected=1`.
	 *
	 * Pins the wire format consumed by smoke tooling + support runbooks:
	 * the field name (`conflicts_detected`) and the integer-as-string
	 * encoding (`=1`, not `=true` / `=yes`).
	 *
	 * The fixture mirrors `Conflict_Detector_Test::test_competing_rewrite_rule_is_detected`
	 * — a foreign value on `$wp_rewrite->extra_rules_top[ REWRITE_KEY ]` is
	 * the smallest poke that flips the detector from clean to dirty.
	 */
	public function test_status_porcelain_emits_conflicts_detected_one_when_conflict_exists(): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top[ Conflict_Detector::REWRITE_KEY ] = 'index.php?some_other_plugin=1';

		// The detector is cached via a 5-minute transient; setUp() drops it,
		// but be defensive against ordering changes by invalidating again
		// after staging the fixture.
		Conflict_Notice::invalidate_cache();

		$this->command->status( array(), array( 'porcelain' => true ) );

		$this->assertContains(
			'conflicts_detected=1',
			\WP_CLI::$lines,
			'Porcelain output must emit `conflicts_detected=1` when one conflict is staged.'
		);
	}

	/**
	 * With no conflict fixture in place, `status --porcelain` must report
	 * `conflicts_detected=0`. Guards against a regression where the field
	 * silently switches to `false` / `no` / `none` and breaks downstream
	 * `=0` parsers.
	 */
	public function test_status_porcelain_emits_conflicts_detected_zero_when_clean(): void {
		// setUp() already drops the transient + clears extra_rules_top, so
		// no extra fixture work is needed — this test asserts the baseline.
		$this->command->status( array(), array( 'porcelain' => true ) );

		$this->assertContains(
			'conflicts_detected=0',
			\WP_CLI::$lines,
			'Porcelain output must emit `conflicts_detected=0` on a clean baseline.'
		);
	}
}
