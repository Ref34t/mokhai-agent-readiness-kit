<?php
/**
 * Integration tests for the LlmsTxt activation/deactivation lifecycle.
 *
 * Exercises `WPContext\Main::on_activate()` and `Main::on_deactivate()` as
 * a single unit so the contracts captured in AgDR-0021 (cache persists
 * across deactivation), AgDR-0022 (cache option shape), and AgDR-0023
 * (cron schedule + clear) are pinned end-to-end. The individual pieces
 * (rewrite registration, schedule_daily_regen, regen_sync,
 * clear_scheduled_regens) are covered by Service_Test / Router_Test;
 * this class fills the integration gap Rex flagged on #56.
 *
 * Tests call `Main::on_activate()` / `Main::on_deactivate()` directly
 * (per ticket AC). The WordPress plugin lifecycle
 * (`activate_plugin()` / `deactivate_plugins()`) is impractical to
 * exercise in PHPUnit because the test plugin file isn't loaded as a
 * standalone plugin in the wp-phpunit harness.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Context_Score\Service as Context_Score_Service;
use WPContext\LlmsTxt\Conflict_Detector;
use WPContext\LlmsTxt\Description_Orchestrator;
use WPContext\LlmsTxt\Service;
use WPContext\Main;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Activation_Lifecycle_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Markdown_Views\Service::register_hooks (wired by Main::register_hooks
		// at bootstrap) deletes from `wp_agentready_md_cache` on save_post.
		// The wp-env test bootstrap drops that table — recreate it so any
		// factory()->post->create() call in this suite stays quiet.
		Markdown_Views_Schema::create();

		// Root cause of the cross-test cron leak (v1/v2 didn't close it):
		// this suite's factory()->post->create() calls fire `save_post`, which
		// triggers `Description_Orchestrator::on_save_post()` → `schedule()` →
		// a per-post cron entry. Sibling Description_Orchestrator_Test counts
		// SCHEDULE_ACTION events globally and asserts the count is 1; ALT's two
		// post-creating tests (`test_on_activate_writes_initial_cache_option`,
		// `test_deactivate_then_reactivate_round_trip_serves_cache`) were each
		// adding an entry — clearing in tearDown happens INSIDE the same
		// transaction as the write, so a tearDown clear can't outrun a write
		// that auto-commits via the dbDelta DDL path inside `on_activate()`.
		// The robust shape is to detach the listener for the duration of this
		// suite so the SCHEDULE_ACTION events never get scheduled in the first
		// place; `_restore_hooks()` re-attaches it at parent::tearDown(), so
		// downstream classes still observe the production-wired listener.
		// We don't need that listener for any assertion ALT makes — this suite
		// pins Main::on_activate() / on_deactivate() behaviour, not the
		// save_post → schedule pipeline (that's covered by DOT's
		// test_save_post_listener_schedules_for_eligible_post).
		remove_action( 'save_post', array( Description_Orchestrator::class, 'on_save_post' ), 30 );

		// Reset every piece of state the lifecycle touches so each test
		// observes the activate / deactivate transition in isolation.
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		delete_option( 'agentready_llms_txt_editorial' );

		// Establish a known profile FIRST so the resulting
		// `agentready_context_profile_saved` hook chain settles…
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

		// …THEN clear every scheduled regen the profile-save chain may
		// have queued. Lifecycle assertions below must observe events
		// scheduled by `on_activate()`, not by the setUp side effects.
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
	}

	protected function tearDown(): void {
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
		delete_option( 'agentready_llms_txt_editorial' );
		delete_option( 'agentready_seo_posture_last_seen' );

		// Context_Score state written by Main::on_activate(): clear the daily
		// recompute cron and the version option so the next test boots from a
		// clean slate.
		Context_Score_Service::clear_scheduled_recomputes();
		delete_option( 'agentready_version' );

		// Belt-and-suspenders clear of Description_Orchestrator::SCHEDULE_ACTION:
		// the on_save_post listener was detached in setUp() so factory posts
		// shouldn't be scheduling these — but if a future test in this class
		// ever calls schedule() directly (e.g. via on_activate adding more
		// listeners), this clear keeps DOT's idempotency assertion safe.
		wp_clear_scheduled_hook( Description_Orchestrator::SCHEDULE_ACTION );

		// Restore the rewrite-rules state we mutated for assertion clarity.
		global $wp_rewrite;
		if ( isset( $wp_rewrite ) ) {
			$wp_rewrite->extra_rules_top = array();
		}

		// Markdown_Views_Schema::drop() was removed: it's a DDL statement that
		// auto-commits in MySQL, breaking the WP_UnitTestCase transaction that
		// would otherwise roll back cron-event writes. The leak from this test
		// to Description_Orchestrator_Test goes away once the transaction
		// wrapper isn't broken. The schema persists across tests, which is
		// harmless since Markdown_Views_Schema::create() in setUp is idempotent.

		parent::tearDown();
	}

	public function test_on_activate_registers_llms_txt_rewrite_rule(): void {
		// Clear extras so we're observing the activation-side effect, not
		// pre-existing registrations from other tests / bootstrap.
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		Main::get_instance()->on_activate();

		$this->assertArrayHasKey(
			Conflict_Detector::REWRITE_KEY,
			$wp_rewrite->extra_rules_top,
			'on_activate() must register the `/llms.txt` rewrite into $wp_rewrite->extra_rules_top.'
		);
		$this->assertStringContainsString(
			'agentready_llms_txt',
			$wp_rewrite->extra_rules_top[ Conflict_Detector::REWRITE_KEY ],
			'Registered rewrite must route to the LlmsTxt query var.'
		);
	}

	public function test_on_activate_schedules_daily_regen_cron(): void {
		// Pre-condition: no daily regen pending.
		$this->assertFalse( wp_next_scheduled( Service::DAILY_REGEN_ACTION ) );

		Main::get_instance()->on_activate();

		$next = wp_next_scheduled( Service::DAILY_REGEN_ACTION );
		$this->assertIsInt( $next, 'Daily regen cron must be scheduled after activation.' );
		$this->assertGreaterThan( time(), $next, 'Scheduled event must fire in the future.' );
	}

	public function test_on_activate_writes_initial_cache_option(): void {
		// Need at least one exposed post so the initial regen produces a
		// non-empty body — but even an empty composition writes the cache
		// payload (AgDR-0022). We assert the payload shape rather than the
		// body content so this test pins the contract, not the prose.
		self::factory()->post->create(
			array(
				'post_title'  => 'Activated entry',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->assertNull(
			Service::get_cache_payload(),
			'Pre-condition: cache must be empty before activation.'
		);

		Main::get_instance()->on_activate();

		$payload = Service::get_cache_payload();
		$this->assertIsArray(
			$payload,
			'on_activate() must run regen_sync() so the cache option exists after activation.'
		);
		$this->assertSame( Service::CACHE_SCHEMA_VERSION, $payload['schema_version'] );
		$this->assertArrayHasKey( 'body', $payload );
		$this->assertArrayHasKey( 'generated_at', $payload );
	}

	public function test_on_deactivate_clears_scheduled_cron(): void {
		// Arrange a hot state: both cron events scheduled (mirrors what
		// on_activate() leaves behind, plus a debounced regen to prove
		// clear_scheduled_regens() catches both surfaces).
		Service::schedule_daily_regen();
		Service::schedule_regen();
		$this->assertIsInt( wp_next_scheduled( Service::DAILY_REGEN_ACTION ) );
		$this->assertIsInt( wp_next_scheduled( Service::REGEN_ACTION ) );

		Main::get_instance()->on_deactivate();

		$this->assertFalse(
			wp_next_scheduled( Service::DAILY_REGEN_ACTION ),
			'on_deactivate() must clear the daily regen cron event.'
		);
		$this->assertFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'on_deactivate() must clear the debounced single-event regen.'
		);
	}

	public function test_on_deactivate_preserves_cache_option(): void {
		// Seed a cache payload so we can prove deactivation does NOT delete
		// it — AgDR-0021 § "Persistent options preserved across deactivation".
		Main::get_instance()->on_activate();
		$before = Service::get_cache_payload();
		$this->assertIsArray(
			$before,
			'Pre-condition: cache populated by on_activate() before deactivation.'
		);

		Main::get_instance()->on_deactivate();

		$after = Service::get_cache_payload();
		$this->assertIsArray(
			$after,
			'on_deactivate() must preserve the cache option (AgDR-0021).'
		);
		$this->assertSame(
			$before['body'],
			$after['body'],
			'Cache body must be byte-identical across deactivation — full purge only happens on uninstall.'
		);
	}

	public function test_deactivate_then_reactivate_round_trip_serves_cache(): void {
		// One post so the initial regen produces a recognisable body.
		self::factory()->post->create(
			array(
				'post_title'  => 'Round trip entry',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$main = Main::get_instance();

		$main->on_activate();
		$first_body = Service::get_cache_payload()['body'];
		$this->assertIsString( $first_body );

		$main->on_deactivate();

		// Cache survives deactivation (asserted in the dedicated test
		// above); reactivation must keep serving the cached document.
		$main->on_activate();
		$reactivated = Service::get_cache_payload();
		$this->assertIsArray( $reactivated );
		$this->assertStringContainsString(
			'Round trip entry',
			$reactivated['body'],
			'Reactivation must continue serving the previously-composed body.'
		);
		$this->assertSame(
			$first_body,
			$reactivated['body'],
			'Reactivation must serve a byte-identical cached body — re-composition must not subtly change the output across the round trip.'
		);
	}
}
