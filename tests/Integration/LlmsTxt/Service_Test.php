<?php
/**
 * Integration tests for Mokhai\LlmsTxt\Service.
 *
 * Runs inside the wp-phpunit test instance so we exercise real options,
 * cron, transients, and hook dispatch. Covers AgDR-0022's cache contract
 * and AgDR-0023's debounce / hook surface.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\LlmsTxt\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class Service_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The plugin's Markdown_Views\Service hooks `save_post`/etc to its
		// own `invalidate()` which deletes from `wp_agentready_md_cache`.
		// The wp-env test bootstrap drops that table (see tests/bootstrap.php
		// comment) so we re-create it for every test that touches the
		// post lifecycle. Without this, every LlmsTxt test that calls
		// `factory()->post->create()` prints a `wpdberror` and trips
		// PHPUnit's `beStrictAboutOutputDuringTests`.
		Markdown_Views_Schema::create();

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		delete_option( 'agentready_llms_txt_editorial' );

		// Reset the profile FIRST so the resulting `mokhai_context_profile_saved`
		// action (fired by `update_option`) settles into a known state…
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

		// …THEN clear any regen scheduled by the profile-save hook chain.
		// Service::register_hooks() is already wired by Main::register_hooks
		// at plugin boot, so the profile update above triggers it. Clearing
		// after the update gives each test a deterministic "no regen
		// pending" starting state.
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
	}

	protected function tearDown(): void {
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
		delete_option( 'agentready_llms_txt_editorial' );

		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	public function test_regen_sync_writes_cache_with_metadata(): void {
		// Title deliberately not "Hello world" — that slug (hello-world) is
		// excluded by the default exclude_wp_samples toggle (#180), which would
		// empty the regen body and defeat this test's intent.
		self::factory()->post->create(
			array(
				'post_title'   => 'A published note',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);

		$body = Service::regen_sync();

		$this->assertNotSame( '', $body );

		$cache = Service::get_cache_payload();
		$this->assertIsArray( $cache );
		$this->assertSame( $body, $cache['body'] );
		$this->assertArrayHasKey( 'generated_at', $cache );
		$this->assertArrayHasKey( 'entry_count', $cache );
		$this->assertSame( Service::CACHE_SCHEMA_VERSION, $cache['schema_version'] );
	}

	public function test_get_composed_body_returns_cached_value_without_regen(): void {
		update_option(
			Service::CACHE_OPTION,
			array(
				'schema_version' => Service::CACHE_SCHEMA_VERSION,
				'body'           => "# Cached\n",
				'generated_at'   => '2026-01-01T00:00:00+00:00',
				'entry_count'    => 0,
			),
			'no'
		);

		$body = Service::get_composed_body();

		$this->assertSame( "# Cached\n", $body );
	}

	public function test_get_composed_body_treats_stale_schema_version_as_miss(): void {
		// Cache payload from a hypothetical future format (schema_version = 99).
		// Reader can't trust the shape, so it should regen instead of
		// returning the stored body. AgDR-0022 § "Schema version field".
		update_option(
			Service::CACHE_OPTION,
			array(
				'schema_version' => 99,
				'body'           => "# Stale\n",
				'generated_at'   => '2026-01-01T00:00:00+00:00',
				'entry_count'    => 0,
			),
			'no'
		);

		self::factory()->post->create(
			array(
				'post_title'  => 'Fresh after stale',
				'post_status' => 'publish',
			)
		);

		$body = Service::get_composed_body();

		$this->assertStringNotContainsString( 'Stale', $body );
		$this->assertStringContainsString( 'Fresh after stale', $body );

		$payload = Service::get_cache_payload();
		$this->assertIsArray( $payload );
		$this->assertSame( Service::CACHE_SCHEMA_VERSION, $payload['schema_version'] );
	}

	public function test_get_composed_body_regenerates_on_miss(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Fresh',
				'post_status' => 'publish',
			)
		);

		Service::invalidate();
		$this->assertNull( Service::get_cache_payload() );

		$body = Service::get_composed_body();
		$this->assertStringContainsString( 'Fresh', $body );
		$this->assertIsArray( Service::get_cache_payload() );
	}

	public function test_get_composed_body_returns_empty_when_lock_held(): void {
		set_transient( Service::REGEN_LOCK_TRANSIENT, time(), Service::LOCK_TTL );

		$body = Service::get_composed_body();

		$this->assertSame( '', $body );
	}

	public function test_schedule_regen_coalesces_repeated_calls(): void {
		Service::schedule_regen();
		$first = wp_next_scheduled( Service::REGEN_ACTION );
		$this->assertIsInt( $first );

		Service::schedule_regen();
		$second = wp_next_scheduled( Service::REGEN_ACTION );

		$this->assertSame( $first, $second );
	}

	/**
	 * Regression for Ref34t/agentready#103.
	 *
	 * Simulates the wp-env-without-traffic failure mode: an event was
	 * scheduled in the past but never consumed by cron. A subsequent
	 * schedule_regen() must clear the stale event and schedule a fresh
	 * future one — otherwise WP de-dups the new wp_schedule_single_event
	 * call against the stale entry and the regen is silently lost.
	 */
	public function test_schedule_regen_clears_stale_past_event_and_reschedules(): void {
		// Stage a stale past-timestamp event directly, bypassing the public
		// API so we don't accidentally test the very logic we're regressing.
		$past = time() - 60;
		wp_schedule_single_event( $past, Service::REGEN_ACTION );
		$this->assertSame( $past, wp_next_scheduled( Service::REGEN_ACTION ) );

		Service::schedule_regen();

		$next = wp_next_scheduled( Service::REGEN_ACTION );
		$this->assertIsInt( $next );
		$this->assertGreaterThan(
			time(),
			$next,
			'schedule_regen must produce a future event even when a stale past event was in the queue.'
		);
		$this->assertLessThanOrEqual(
			time() + Service::DEBOUNCE_DELAY + 1,
			$next,
			'New event must be scheduled within the debounce window.'
		);
	}

	public function test_on_post_change_skips_non_exposed_cpt(): void {
		// Page CPT isn't in the test profile's exposed_cpts (only 'post' is),
		// so the save_post hook chain should see on_post_change return early
		// before scheduling a regen.
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $page_id );
		$this->assertFalse( wp_next_scheduled( Service::REGEN_ACTION ) );

		// Direct call for clarity — same outcome.
		Service::on_post_change( $page_id );
		$this->assertFalse( wp_next_scheduled( Service::REGEN_ACTION ) );
	}

	public function test_on_post_change_schedules_for_exposed_cpt(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		// save_post fired during create; assert the scheduling happened.
		$ts = wp_next_scheduled( Service::REGEN_ACTION );
		$this->assertIsInt( $ts );
		$this->assertGreaterThanOrEqual( time() + Service::DEBOUNCE_DELAY - 1, $ts );
	}

	public function test_profile_save_triggers_regen(): void {
		do_action( 'mokhai_context_profile_saved', 'old', 'new' );

		$this->assertIsInt( wp_next_scheduled( Service::REGEN_ACTION ) );
	}

	public function test_invalidate_drops_cache(): void {
		Service::regen_sync();
		$this->assertIsArray( Service::get_cache_payload() );

		Service::invalidate();
		$this->assertNull( Service::get_cache_payload() );
	}

	public function test_compose_now_returns_body_without_writing_cache(): void {
		Service::invalidate();
		self::factory()->post->create(
			array(
				'post_title'  => 'Preview test',
				'post_status' => 'publish',
			)
		);

		$body = Service::compose_now();

		$this->assertStringContainsString( 'Preview test', $body );
		$this->assertNull( Service::get_cache_payload() );
	}

	public function test_empty_profile_yields_header_only_body(): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array(),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		self::factory()->post->create(
			array(
				'post_title'  => 'Hidden',
				'post_status' => 'publish',
			)
		);

		$body = Service::regen_sync();

		// #244: empty exposed_cpts → the site identity header alone, no
		// entries — an identifiable file, not a blank body.
		$this->assertNotSame( '', $body );
		$this->assertStringStartsWith( '# ', $body );
		$this->assertStringNotContainsString( 'Hidden', $body, 'Unexposed post must not appear.' );
		$this->assertStringNotContainsString( "\n- [", $body, 'No content exposed → no entries.' );
	}

	public function test_editorial_entries_appear_in_body(): void {
		update_option(
			'agentready_llms_txt_editorial',
			array(
				array(
					'title'       => 'Pinned post',
					'url'         => 'https://example.test/pinned/',
					'description' => 'Curated',
					'section'     => 'Featured',
				),
			)
		);
		// Ensure at least one auto entry so identity block renders.
		self::factory()->post->create(
			array(
				'post_title'  => 'Other',
				'post_status' => 'publish',
			)
		);

		$body = Service::regen_sync();

		$this->assertStringContainsString( '## Featured', $body );
		$this->assertStringContainsString( '[Pinned post](https://example.test/pinned/): Curated', $body );
	}

	public function test_schedule_daily_regen_is_idempotent(): void {
		Service::schedule_daily_regen();
		$first = wp_next_scheduled( Service::DAILY_REGEN_ACTION );

		Service::schedule_daily_regen();
		$second = wp_next_scheduled( Service::DAILY_REGEN_ACTION );

		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
	}

	public function test_clear_scheduled_regens_removes_both_actions(): void {
		Service::schedule_regen();
		Service::schedule_daily_regen();

		$this->assertIsInt( wp_next_scheduled( Service::REGEN_ACTION ) );
		$this->assertIsInt( wp_next_scheduled( Service::DAILY_REGEN_ACTION ) );

		Service::clear_scheduled_regens();

		$this->assertFalse( wp_next_scheduled( Service::REGEN_ACTION ) );
		$this->assertFalse( wp_next_scheduled( Service::DAILY_REGEN_ACTION ) );
	}
}
