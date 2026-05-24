<?php
/**
 * Integration tests for the Phase B cleanup-state machine extensions
 * in WPContext\Markdown_Views\Cleanup_Orchestrator per AgDR-0020.
 *
 * Runs inside wp-phpunit so we exercise the real post-meta layer,
 * the real cron-queue, and the real `save_post` invalidation hook.
 *
 * Covered transitions:
 *   - approve() from `done`   → `approved`
 *   - reject()  from `done`   → `rejected`
 *   - approve / reject idempotent on already-approved / already-rejected
 *   - approve / reject from invalid base states → RuntimeException
 *   - regenerate() invalidates output + queues cron event
 *   - regenerate() idempotent on already-`pending`
 *   - get_state() returns the full state blob
 *   - get_approved_output() returns content only when status == 'approved'
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use WPContext\Markdown_Views\Cleanup_Orchestrator;
use WPContext\Markdown_Views\Schema;

final class Cleanup_Orchestrator_State_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();
	}

	protected function tearDown(): void {
		Schema::drop();
		parent::tearDown();
	}

	/**
	 * Seed a post into the `done` cleanup state — output + hash +
	 * diagnostics present, status set to `done`. Returns the post id.
	 */
	private function seed_done_state( string $hash = 'test-hash-1' ): int {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<p>Body.</p>' )
		);

		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_OUTPUT, 'cleaned markdown body' );
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_OUTPUT_HASH, $hash );
		\update_post_meta(
			$post_id,
			Cleanup_Orchestrator::META_KEY_DIAGNOSTICS,
			(string) \wp_json_encode( array( 'sentences_kept' => 5, 'sentences_dropped' => 0 ) )
		);
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_DONE );

		return (int) $post_id;
	}

	public function test_approve_transitions_done_to_approved(): void {
		$post_id = $this->seed_done_state();

		$result = Cleanup_Orchestrator::approve( $post_id );

		self::assertTrue( $result, 'approve() returns true on real transition' );
		self::assertSame( Cleanup_Orchestrator::STATUS_APPROVED, Cleanup_Orchestrator::get_status( $post_id ) );
	}

	public function test_approve_is_idempotent_on_already_approved(): void {
		$post_id = $this->seed_done_state();
		Cleanup_Orchestrator::approve( $post_id );

		$result = Cleanup_Orchestrator::approve( $post_id );

		self::assertFalse( $result, 'approve() on already-approved returns false (no-op)' );
		self::assertSame( Cleanup_Orchestrator::STATUS_APPROVED, Cleanup_Orchestrator::get_status( $post_id ) );
	}

	public function test_approve_from_pending_throws(): void {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_PENDING );

		$this->expectException( \RuntimeException::class );
		Cleanup_Orchestrator::approve( (int) $post_id );
	}

	public function test_approve_from_no_state_throws(): void {
		$post_id = self::factory()->post->create();

		$this->expectException( \RuntimeException::class );
		Cleanup_Orchestrator::approve( (int) $post_id );
	}

	public function test_reject_transitions_done_to_rejected(): void {
		$post_id = $this->seed_done_state();

		$result = Cleanup_Orchestrator::reject( $post_id );

		self::assertTrue( $result );
		self::assertSame( Cleanup_Orchestrator::STATUS_REJECTED, Cleanup_Orchestrator::get_status( $post_id ) );
	}

	public function test_reject_is_idempotent_on_already_rejected(): void {
		$post_id = $this->seed_done_state();
		Cleanup_Orchestrator::reject( $post_id );

		$result = Cleanup_Orchestrator::reject( $post_id );

		self::assertFalse( $result );
		self::assertSame( Cleanup_Orchestrator::STATUS_REJECTED, Cleanup_Orchestrator::get_status( $post_id ) );
	}

	public function test_reject_from_failed_throws(): void {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_FAILED );

		$this->expectException( \RuntimeException::class );
		Cleanup_Orchestrator::reject( (int) $post_id );
	}

	public function test_regenerate_invalidates_and_schedules(): void {
		$post_id = $this->seed_done_state();
		$post    = \get_post( $post_id );
		self::assertNotNull( $post );

		$result = Cleanup_Orchestrator::regenerate( $post );

		self::assertTrue( $result );
		// Output cleared.
		self::assertSame( '', \get_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_OUTPUT, true ) );
		// Status now pending.
		self::assertSame( Cleanup_Orchestrator::STATUS_PENDING, Cleanup_Orchestrator::get_status( $post_id ) );
		// Cron event scheduled.
		self::assertNotFalse(
			\wp_next_scheduled( Cleanup_Orchestrator::SCHEDULE_ACTION, array( $post_id ) ),
			'regenerate() must queue a cron event for run_cleanup'
		);
	}

	public function test_regenerate_idempotent_when_already_pending(): void {
		$post_id = self::factory()->post->create();
		$post    = \get_post( $post_id );
		self::assertNotNull( $post );
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_PENDING );
		\wp_schedule_single_event( \time() + 1, Cleanup_Orchestrator::SCHEDULE_ACTION, array( (int) $post_id ) );

		$result = Cleanup_Orchestrator::regenerate( $post );

		self::assertFalse( $result, 'regenerate() on pending returns false (no-op)' );
		self::assertSame( Cleanup_Orchestrator::STATUS_PENDING, Cleanup_Orchestrator::get_status( (int) $post_id ) );
	}

	/**
	 * Ref34t/agentready#120 — per-post stale-event recovery.
	 *
	 * Simulates the wp-env-without-traffic failure mode: a per-post
	 * cleanup event was scheduled in the past but never consumed by
	 * cron. A subsequent Cleanup_Orchestrator::schedule() call for the
	 * same post must clear the stale event and schedule a fresh future
	 * one — otherwise WP de-dups the new wp_schedule_single_event call
	 * against the stale entry and the cleanup is silently lost, but
	 * the admin UI keeps showing "pending" forever because the meta
	 * marker is rewritten on every schedule call.
	 *
	 * Mirrors tests/Integration/Context_Score/Service_Test.php::
	 * test_schedule_recompute_clears_stale_past_event_and_reschedules
	 * and tests/Integration/LlmsTxt/Service_Test.php::
	 * test_schedule_regen_clears_stale_past_event_and_reschedules.
	 */
	public function test_schedule_clears_stale_past_event_and_reschedules(): void {
		$post_id = (int) self::factory()->post->create();
		$post    = \get_post( $post_id );
		self::assertNotNull( $post );
		$args = array( $post_id );

		// Stage a stale past-timestamp event directly, bypassing the
		// public API so we don't accidentally test the very logic
		// we're regressing.
		$past = \time() - 60;
		\wp_schedule_single_event( $past, Cleanup_Orchestrator::SCHEDULE_ACTION, $args );
		$this->assertSame( $past, \wp_next_scheduled( Cleanup_Orchestrator::SCHEDULE_ACTION, $args ) );

		Cleanup_Orchestrator::schedule( $post );

		$next = \wp_next_scheduled( Cleanup_Orchestrator::SCHEDULE_ACTION, $args );
		$this->assertIsInt( $next );
		$this->assertGreaterThan(
			\time(),
			$next,
			'schedule() must produce a future event for this post even when a stale past event was in the queue.'
		);
		// The cleanup-cap guard runs AFTER the stale-event branch, so
		// when the cap is far from hit the new event is scheduled
		// within ~1s.
		$this->assertLessThanOrEqual(
			\time() + 5,
			$next,
			'New event must be scheduled within a few seconds.'
		);
		// Meta marker must reflect the fresh schedule.
		$this->assertSame(
			Cleanup_Orchestrator::STATUS_PENDING,
			Cleanup_Orchestrator::get_status( $post_id )
		);
	}

	public function test_get_state_returns_blob_with_all_fields(): void {
		$post_id = $this->seed_done_state( 'hash-blob-test' );

		$state = Cleanup_Orchestrator::get_state( $post_id );

		self::assertSame( Cleanup_Orchestrator::STATUS_DONE, $state['status'] );
		self::assertSame( 'hash-blob-test', $state['content_hash'] );
		self::assertSame( 'cleaned markdown body', $state['cleaned_markdown'] );
		self::assertIsArray( $state['diagnostics'] );
		self::assertSame( 5, $state['diagnostics']['sentences_kept'] );
	}

	public function test_get_state_on_post_with_no_cleanup_returns_empty_fields(): void {
		$post_id = self::factory()->post->create();

		$state = Cleanup_Orchestrator::get_state( (int) $post_id );

		self::assertSame( '', $state['status'] );
		self::assertSame( '', $state['content_hash'] );
		self::assertNull( $state['cleaned_markdown'] );
		self::assertNull( $state['diagnostics'] );
	}

	public function test_get_approved_output_returns_null_when_status_is_done(): void {
		$post_id = $this->seed_done_state( 'hash-X' );

		self::assertNull( Cleanup_Orchestrator::get_approved_output( $post_id, 'hash-X' ) );
	}

	public function test_get_approved_output_returns_content_when_status_is_approved(): void {
		$post_id = $this->seed_done_state( 'hash-X' );
		Cleanup_Orchestrator::approve( $post_id );

		self::assertSame(
			'cleaned markdown body',
			Cleanup_Orchestrator::get_approved_output( $post_id, 'hash-X' )
		);
	}

	public function test_get_approved_output_returns_null_on_hash_mismatch(): void {
		$post_id = $this->seed_done_state( 'hash-X' );
		Cleanup_Orchestrator::approve( $post_id );

		// Different content hash → cleanup is stale → return null even
		// though status is approved.
		self::assertNull( Cleanup_Orchestrator::get_approved_output( $post_id, 'hash-Y' ) );
	}

	public function test_get_approved_output_returns_null_when_rejected(): void {
		$post_id = $this->seed_done_state( 'hash-X' );
		Cleanup_Orchestrator::reject( $post_id );

		self::assertNull( Cleanup_Orchestrator::get_approved_output( $post_id, 'hash-X' ) );
	}

	public function test_invalidate_clears_all_four_meta_keys(): void {
		$post_id = $this->seed_done_state();

		Cleanup_Orchestrator::invalidate( $post_id );

		self::assertSame( '', \get_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_OUTPUT, true ) );
		self::assertSame( '', \get_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_OUTPUT_HASH, true ) );
		self::assertSame( '', \get_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_DIAGNOSTICS, true ) );
		self::assertSame( '', \get_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, true ) );
	}
}
