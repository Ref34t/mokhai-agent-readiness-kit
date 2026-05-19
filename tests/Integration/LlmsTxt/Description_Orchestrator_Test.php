<?php
/**
 * Integration tests for `WPContext\LlmsTxt\Description_Orchestrator`
 * and `Description_Filter` per AgDR-0027.
 *
 * Runs inside wp-phpunit so we exercise real post-meta, real cron, real
 * filter dispatch through `Entry_Source::DESCRIPTION_FILTER`. The
 * `run()` cron handler itself is not exercised here — it would require
 * a real `wp_ai_client_prompt()` round-trip; the orchestrator's
 * deterministic surface (eligibility, scheduling, regen, invalidate,
 * read-side filter) is what this suite pins.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\LlmsTxt\Description_Orchestrator;
use WPContext\LlmsTxt\Entry_Source;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Description_Orchestrator_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Same Markdown_Views schema reseed pattern as Service_Test.
		// The wp-env bootstrap drops the cache table and Markdown_Views\Service
		// hooks save_post → invalidate() which writes to it; without
		// recreating the table every factory()->post->create() prints a
		// wpdberror that trips strict-output tests.
		Markdown_Views_Schema::create();

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'             => array( 'post' ),
					'exposed_statuses'         => array( 'publish' ),
					'llm_descriptions_enabled' => true,
				)
			)
		);

		wp_clear_scheduled_hook( Description_Orchestrator::SCHEDULE_ACTION );
	}

	public function test_should_schedule_returns_true_for_eligible_post_without_cache(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
			)
		);

		self::assertTrue( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_manual_description_present(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_MANUAL,
			'Admin-set sticky description.'
		);

		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_toggle_disabled(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'             => array( 'post' ),
					'llm_descriptions_enabled' => false,
				)
			)
		);

		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_cpt_not_exposed(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'page', 'post_status' => 'publish' )
		);

		// `exposed_cpts` was seeded as ['post'] in setUp — `page` is not in the set.
		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_true_when_auto_is_stale(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_AUTO,
			'Older cached description.'
		);
		// generated_for_modified_gmt strictly earlier than post_modified_gmt.
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			'2000-01-01 00:00:00'
		);

		self::assertTrue( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_should_schedule_returns_false_when_auto_is_fresh(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_AUTO,
			'Fresh cached description.'
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
			$post->post_modified_gmt
		);

		self::assertFalse( Description_Orchestrator::should_schedule( $post ) );
	}

	public function test_schedule_queues_cron_event_and_records_pending_status(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		Description_Orchestrator::schedule( $post );

		self::assertNotFalse(
			wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( (int) $post->ID ) )
		);
		self::assertSame(
			Description_Orchestrator::STATUS_PENDING,
			Description_Orchestrator::get_status( (int) $post->ID )
		);
	}

	public function test_schedule_is_idempotent_when_already_queued(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		Description_Orchestrator::schedule( $post );
		Description_Orchestrator::schedule( $post );

		$cron  = _get_cron_array();
		$count = 0;
		foreach ( (array) $cron as $hooks ) {
			if ( isset( $hooks[ Description_Orchestrator::SCHEDULE_ACTION ] ) ) {
				$count += count( $hooks[ Description_Orchestrator::SCHEDULE_ACTION ] );
			}
		}
		self::assertSame( 1, $count, 'second schedule() must not double-queue' );
	}

	public function test_regenerate_clears_auto_cache_and_queues_fresh_job(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'old' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED, '2000-01-01 00:00:00' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_STATUS, Description_Orchestrator::STATUS_DONE );

		$result = Description_Orchestrator::regenerate( $post );

		self::assertTrue( $result );
		self::assertSame( '', (string) get_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, true ) );
		self::assertSame(
			Description_Orchestrator::STATUS_PENDING,
			Description_Orchestrator::get_status( (int) $post->ID )
		);
	}

	public function test_regenerate_preserves_manual_override(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta(
			$post->ID,
			Description_Orchestrator::META_KEY_MANUAL,
			'Sticky admin description.'
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'old auto' );

		Description_Orchestrator::regenerate( $post );

		self::assertSame(
			'Sticky admin description.',
			(string) get_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, true )
		);
	}

	public function test_regenerate_is_idempotent_when_pending(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		Description_Orchestrator::schedule( $post );

		$result = Description_Orchestrator::regenerate( $post );

		self::assertFalse( $result );
	}

	public function test_get_cached_description_prefers_manual(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, 'manual wins' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto loses' );

		self::assertSame(
			'manual wins',
			Description_Orchestrator::get_cached_description( (int) $post->ID )
		);
	}

	public function test_get_cached_description_falls_back_to_auto(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto value' );

		self::assertSame(
			'auto value',
			Description_Orchestrator::get_cached_description( (int) $post->ID )
		);
	}

	public function test_get_cached_description_returns_empty_when_nothing_set(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		self::assertSame( '', Description_Orchestrator::get_cached_description( (int) $post->ID ) );
	}

	public function test_filter_returns_manual_description(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, 'manual desc' );

		// Description_Filter::register_hooks is called by Main::register_hooks
		// at plugin boot, so the filter is already wired in the test process.
		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, '', $post );

		self::assertSame( 'manual desc', $out );
	}

	public function test_filter_returns_auto_when_no_manual(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto desc' );

		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, '', $post );

		self::assertSame( 'auto desc', $out );
	}

	public function test_filter_falls_through_when_cache_empty(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);

		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, '', $post );

		self::assertSame( '', $out );
	}

	public function test_filter_does_not_override_a_prior_non_empty_value(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto desc' );

		// Another subscriber may have already set a description; we
		// honour it rather than clobber.
		$out = apply_filters( Entry_Source::DESCRIPTION_FILTER, 'set by upstream', $post );

		self::assertSame( 'set by upstream', $out );
	}

	public function test_save_post_listener_schedules_for_eligible_post(): void {
		// The save_post hook is wired by Description_Orchestrator::register_hooks
		// at plugin boot, so factory()->post->create() should trigger it.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Some content for description generation.',
			)
		);

		// Eligibility check evaluated `Client_Wrapper::has_ai_client()` —
		// in wp-env the WP AI Client backport is bundled so it returns true.
		// If the test environment ever changes this, the assertion below
		// becomes "no cron event" and that's also a valid pass.
		if ( ! \WPContext\Ai\Client_Wrapper::has_ai_client() ) {
			self::markTestSkipped( 'WP AI Client unavailable in this test instance; save_post listener short-circuits before scheduling.' );
		}

		self::assertNotFalse(
			wp_next_scheduled( Description_Orchestrator::SCHEDULE_ACTION, array( $post_id ) )
		);
	}

	public function test_invalidate_clears_all_orchestrator_meta(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_type' => 'post', 'post_status' => 'publish' )
		);
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_AUTO, 'auto' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_MANUAL, 'manual' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_STATUS, Description_Orchestrator::STATUS_DONE );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED, '2026-01-01 00:00:00' );
		update_post_meta( $post->ID, Description_Orchestrator::META_KEY_DIAGNOSTICS, '{}' );

		Description_Orchestrator::invalidate( (int) $post->ID );

		foreach (
			array(
				Description_Orchestrator::META_KEY_AUTO,
				Description_Orchestrator::META_KEY_MANUAL,
				Description_Orchestrator::META_KEY_STATUS,
				Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
				Description_Orchestrator::META_KEY_DIAGNOSTICS,
			) as $key
		) {
			self::assertSame( '', (string) get_post_meta( $post->ID, $key, true ), $key . ' should be cleared' );
		}
	}
}
