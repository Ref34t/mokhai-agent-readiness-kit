<?php
/**
 * Integration tests for Mokhai\Context_Score\Service.
 *
 * Runs inside the wp-phpunit test instance so we exercise real options,
 * cron registration, and the `mokhai_context_profile_saved` action
 * dispatch path. Covers AgDR-0030's cache contract and recompute triggers.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Context_Score;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Context_Score\Engine;
use Mokhai\Context_Score\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class Service_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The Signal_Collector reads from the Markdown Views cache table;
		// the wp-env bootstrap drops it between suites, so recreate it.
		Markdown_Views_Schema::create();

		Service::invalidate();

		// Reset the profile to a known shape FIRST so the resulting
		// `mokhai_context_profile_saved` settles into a known state…
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

		// …THEN clear any recompute scheduled by the profile-save listener
		// so each test starts from a deterministic "no recompute pending"
		// state.
		wp_clear_scheduled_hook( Service::RECOMPUTE_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_RECOMPUTE_ACTION );
		wp_clear_scheduled_hook( Service::NARRATIVE_ACTION );
	}

	protected function tearDown(): void {
		Service::invalidate();
		wp_clear_scheduled_hook( Service::RECOMPUTE_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_RECOMPUTE_ACTION );
		wp_clear_scheduled_hook( Service::NARRATIVE_ACTION );

		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	public function test_get_breakdown_returns_null_when_no_cache(): void {
		$this->assertNull( Service::get_breakdown() );
	}

	public function test_recompute_now_writes_cache_with_required_fields(): void {
		$payload = Service::recompute_now();

		$this->assertIsArray( $payload );
		$this->assertSame( Service::CACHE_SCHEMA_VERSION, $payload['schema_version'] );
		$this->assertArrayHasKey( 'computed_at', $payload );
		$this->assertArrayHasKey( 'recompute_duration_ms', $payload );
		$this->assertArrayHasKey( 'overall', $payload );
		$this->assertArrayHasKey( 'sub_scores', $payload );
		$this->assertIsInt( $payload['overall'] );
		$this->assertGreaterThanOrEqual( 0, $payload['overall'] );
		$this->assertLessThanOrEqual( 100, $payload['overall'] );

		foreach ( array_keys( Engine::WEIGHTS ) as $name ) {
			$this->assertArrayHasKey( $name, $payload['sub_scores'], "missing sub-score: {$name}" );
		}
	}

	public function test_recompute_now_attaches_a_narrative_with_one_pair_per_sub_score(): void {
		$payload = Service::recompute_now();

		$this->assertArrayHasKey( 'narrative', $payload, 'narrative slot is missing — #11 / AgDR-0032 contract broken.' );
		$this->assertIsArray( $payload['narrative'] );

		$narrative = $payload['narrative'];
		$this->assertArrayHasKey( 'mode', $narrative );
		$this->assertArrayHasKey( 'degraded', $narrative );
		$this->assertArrayHasKey( 'sub_scores', $narrative );
		$this->assertContains(
			$narrative['mode'],
			array( 'llm', 'rule_based', 'mixed' ),
			'narrative.mode must be one of llm | rule_based | mixed'
		);

		foreach ( array_keys( Engine::WEIGHTS ) as $name ) {
			$this->assertArrayHasKey( $name, $narrative['sub_scores'], "narrative missing pair for {$name}" );
			$entry = $narrative['sub_scores'][ $name ];
			$this->assertIsArray( $entry );
			$this->assertArrayHasKey( 'why', $entry );
			$this->assertArrayHasKey( 'fix', $entry );
			$this->assertArrayHasKey( 'source', $entry );
			$this->assertContains( $entry['source'], array( 'llm', 'rule_based' ) );
			$this->assertNotSame( '', trim( (string) $entry['why'] ) );
			$this->assertNotSame( '', trim( (string) $entry['fix'] ) );
		}
	}

	public function test_recompute_now_persists_to_option(): void {
		Service::recompute_now();

		$stored = get_option( Service::CACHE_OPTION );

		$this->assertIsArray( $stored );
		$this->assertSame( Service::CACHE_SCHEMA_VERSION, $stored['schema_version'] );
	}

	public function test_recompute_now_writes_pending_llm_narrative(): void {
		// #167 / AgDR-0051: the narrative is generated asynchronously, so
		// recompute_now writes an instant rule-based placeholder marked
		// llm_pending rather than blocking on the LLM.
		$narrative = Service::recompute_now()['narrative'];

		$this->assertTrue( $narrative['llm_pending'] );
		$this->assertSame( 'rule_based', $narrative['mode'] );
		$this->assertSame( 'llm_pending', $narrative['degraded_reason'] );
	}

	public function test_recompute_now_schedules_the_background_narrative_job(): void {
		$this->assertFalse( wp_next_scheduled( Service::NARRATIVE_ACTION ) );

		Service::recompute_now();

		$this->assertNotFalse(
			wp_next_scheduled( Service::NARRATIVE_ACTION ),
			'recompute_now must schedule the async narrative job.'
		);
	}

	public function test_do_generate_narrative_clears_pending(): void {
		Service::recompute_now();
		$this->assertTrue( Service::get_breakdown()['narrative']['llm_pending'] );

		// No WP AI client in the test instance → generate() degrades to a
		// rule-based fallback. The contract under test is that the job reaches
		// a FINAL state (no longer pending), not which mode it lands in.
		Service::do_generate_narrative();

		$this->assertFalse( Service::get_breakdown()['narrative']['llm_pending'] );
	}

	public function test_do_generate_narrative_is_noop_when_not_pending(): void {
		Service::recompute_now();
		Service::do_generate_narrative();           // clears pending
		$first = Service::get_breakdown()['narrative'];

		Service::do_generate_narrative();           // already enriched → early return
		$second = Service::get_breakdown()['narrative'];

		$this->assertSame( $first['generated_at'], $second['generated_at'] );
	}

	public function test_do_generate_narrative_is_noop_when_cache_absent(): void {
		Service::invalidate();

		Service::do_generate_narrative();           // must not crash or write

		$this->assertNull( Service::get_breakdown() );
	}

	public function test_get_breakdown_returns_cached_payload(): void {
		$written = Service::recompute_now();

		$read = Service::get_breakdown();

		$this->assertIsArray( $read );
		$this->assertSame( $written['overall'], $read['overall'] );
		$this->assertSame( $written['sub_scores'], $read['sub_scores'] );
	}

	public function test_get_breakdown_treats_unknown_schema_version_as_miss(): void {
		// Plant a stale payload with the wrong schema_version. The reader
		// should treat it as a miss — same defensive pattern AgDR-0022 uses
		// for the /llms.txt cache.
		update_option(
			Service::CACHE_OPTION,
			array(
				'schema_version' => 99,
				'overall'        => 50,
				'sub_scores'     => array(),
			),
			false
		);

		$this->assertNull( Service::get_breakdown() );
	}

	public function test_invalidate_drops_cached_payload(): void {
		Service::recompute_now();
		$this->assertNotNull( Service::get_breakdown() );

		Service::invalidate();

		$this->assertNull( Service::get_breakdown() );
	}

	public function test_schedule_recompute_registers_a_cron_event(): void {
		$this->assertFalse( wp_next_scheduled( Service::RECOMPUTE_ACTION ) );

		Service::schedule_recompute();

		$scheduled = wp_next_scheduled( Service::RECOMPUTE_ACTION );
		$this->assertNotFalse( $scheduled );
		$this->assertGreaterThanOrEqual( time() + Service::DEBOUNCE_DELAY - 1, (int) $scheduled );
	}

	public function test_schedule_recompute_coalesces_within_debounce_window(): void {
		Service::schedule_recompute();
		$first = wp_next_scheduled( Service::RECOMPUTE_ACTION );

		// Second call within the same debounce window should find the
		// existing event and noop.
		Service::schedule_recompute();
		$second = wp_next_scheduled( Service::RECOMPUTE_ACTION );

		$this->assertSame( $first, $second );
	}

	/**
	 * Ref34t/agentready#115 — stale-event recovery (sibling of #103).
	 *
	 * Simulates the wp-env-without-traffic failure mode: an event was
	 * scheduled in the past but never consumed by cron. A subsequent
	 * schedule_recompute() must clear the stale event and schedule a
	 * fresh future one — otherwise WP de-dups the new
	 * wp_schedule_single_event call against the stale entry and the
	 * recompute is silently lost. Mirrors
	 * tests/Integration/LlmsTxt/Service_Test.php::test_schedule_regen_clears_stale_past_event_and_reschedules.
	 */
	public function test_schedule_recompute_clears_stale_past_event_and_reschedules(): void {
		// Stage a stale past-timestamp event directly, bypassing the
		// public API so we don't accidentally test the very logic we're
		// regressing.
		$past = time() - 60;
		wp_schedule_single_event( $past, Service::RECOMPUTE_ACTION );
		$this->assertSame( $past, wp_next_scheduled( Service::RECOMPUTE_ACTION ) );

		Service::schedule_recompute();

		$next = wp_next_scheduled( Service::RECOMPUTE_ACTION );
		$this->assertIsInt( $next );
		$this->assertGreaterThan(
			time(),
			$next,
			'schedule_recompute must produce a future event even when a stale past event was in the queue.'
		);
		$this->assertLessThanOrEqual(
			time() + Service::DEBOUNCE_DELAY + 1,
			$next,
			'New event must be scheduled within the debounce window.'
		);
	}

	public function test_profile_saved_action_schedules_a_recompute(): void {
		// Fire the action directly with the listener path the Main bootstrap
		// already wired (Service::register_hooks runs at plugin boot).
		do_action( 'mokhai_context_profile_saved', array(), array() );

		$scheduled = wp_next_scheduled( Service::RECOMPUTE_ACTION );
		$this->assertNotFalse( $scheduled );
	}

	public function test_schedule_daily_recompute_registers_a_daily_event(): void {
		wp_clear_scheduled_hook( Service::DAILY_RECOMPUTE_ACTION );

		Service::schedule_daily_recompute();

		$this->assertNotFalse( wp_next_scheduled( Service::DAILY_RECOMPUTE_ACTION ) );
	}

	public function test_schedule_daily_recompute_is_idempotent(): void {
		wp_clear_scheduled_hook( Service::DAILY_RECOMPUTE_ACTION );

		Service::schedule_daily_recompute();
		$first = wp_next_scheduled( Service::DAILY_RECOMPUTE_ACTION );

		Service::schedule_daily_recompute();
		$second = wp_next_scheduled( Service::DAILY_RECOMPUTE_ACTION );

		$this->assertSame( $first, $second );
	}

	public function test_clear_scheduled_recomputes_removes_both_events(): void {
		Service::schedule_recompute();
		Service::schedule_daily_recompute();

		$this->assertNotFalse( wp_next_scheduled( Service::RECOMPUTE_ACTION ) );
		$this->assertNotFalse( wp_next_scheduled( Service::DAILY_RECOMPUTE_ACTION ) );

		Service::clear_scheduled_recomputes();

		$this->assertFalse( wp_next_scheduled( Service::RECOMPUTE_ACTION ) );
		$this->assertFalse( wp_next_scheduled( Service::DAILY_RECOMPUTE_ACTION ) );
	}

	public function test_recompute_reflects_profile_changes(): void {
		Service::recompute_now();
		$before = Service::get_breakdown();

		// Change the profile to expose more CPTs. Discoverability should
		// move up because the "exposed_cpts non-empty" gate stays satisfied
		// AND the larger configuration is more likely to populate /llms.txt
		// — but the score-floor assertions below don't depend on signs of
		// movement, just that recompute observes the new state.
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post', 'page' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		Service::recompute_now();
		$after = Service::get_breakdown();

		$this->assertIsArray( $before );
		$this->assertIsArray( $after );
		// computed_at should advance (the second recompute happens after the
		// first; even on a fast machine, gmdate('c') changes monotonically
		// or stays equal — never goes backwards).
		$this->assertGreaterThanOrEqual( $before['computed_at'], $after['computed_at'] );
		$this->assertSame(
			2,
			$after['sub_scores']['discoverability']['signals']['exposed_cpts_count']
		);
	}

	public function test_recompute_completes_within_phase_a_budget(): void {
		// AC: < 10s on ≤ 1000-post sites. Empty test environment is well
		// under that; the test asserts the budget envelope, not the floor.
		Service::recompute_now();
		$payload = Service::get_breakdown();

		$this->assertIsArray( $payload );
		$this->assertLessThan( 10_000, (int) $payload['recompute_duration_ms'] );
	}
}
