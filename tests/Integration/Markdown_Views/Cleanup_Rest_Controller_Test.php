<?php
/**
 * Integration tests for the Phase B cleanup REST controller per AgDR-0020.
 *
 * Runs inside wp-phpunit so we exercise the real REST infrastructure,
 * real capability checks, real post-meta, and the real cache table.
 * Verifies that each of the four routes:
 *   - rejects unauthenticated / non-edit_post users
 *   - returns the unified state-blob response shape
 *   - transitions the orchestrator state correctly
 *   - returns 409 when called from an invalid base state (approve/reject)
 *   - is idempotent on no-op cases
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Markdown_Views;

use WP_REST_Request;
use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Markdown_Views\Cleanup_Orchestrator;
use WPContext\Markdown_Views\Cleanup_Rest_Controller;
use WPContext\Markdown_Views\Schema;

final class Cleanup_Rest_Controller_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();

		// The REST routes are registered via the production hook chain
		// (Main::register_hooks → Cleanup_Rest_Controller::register_hooks
		// → add_action('rest_api_init', ...)). Calling register_routes()
		// directly here would re-register OUTSIDE the rest_api_init
		// action, which triggers `_doing_it_wrong()` per WP 5.1+ — the
		// wp-phpunit framework captures that notice and fails the test.

		// Profile setup: module enabled, post exposable.
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			\array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post', 'page' ),
					'exposed_statuses'       => array( 'publish' ),
					'markdown_views_enabled' => true,
				)
			)
		);
	}

	protected function tearDown(): void {
		Schema::drop();
		\wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Seed a post into the `done` state — output + hash + status set.
	 */
	private function seed_done_post(): int {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<p>Body.</p>', 'post_status' => 'publish' )
		);

		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_OUTPUT, 'cleaned body' );
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_OUTPUT_HASH, 'hash-1' );
		\update_post_meta(
			$post_id,
			Cleanup_Orchestrator::META_KEY_DIAGNOSTICS,
			(string) \wp_json_encode( array( 'sentences_kept' => 3, 'sentences_dropped' => 0 ) )
		);
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_DONE );

		return (int) $post_id;
	}

	private function make_admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function make_subscriber(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_get_state_returns_full_blob_for_admin(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_done_post();

		$req = new WP_REST_Request( 'GET', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_STATE );
		$req->set_query_params( array( 'post' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( Cleanup_Orchestrator::STATUS_DONE, $data['status'] );
		self::assertSame( 'hash-1', $data['content_hash'] );
		self::assertSame( 'cleaned body', $data['cleaned_markdown'] );
		self::assertArrayHasKey( 'diagnostics', $data );
		self::assertArrayHasKey( 'deterministic_markdown', $data );
		self::assertArrayHasKey( 'quality_score', $data );
		self::assertArrayHasKey( 'signals', $data );
	}

	public function test_get_state_rejects_subscriber(): void {
		\wp_set_current_user( $this->make_subscriber() );
		$post_id = $this->seed_done_post();

		$req = new WP_REST_Request( 'GET', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_STATE );
		$req->set_query_params( array( 'post' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_approve_transitions_done_to_approved(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_done_post();

		$req = new WP_REST_Request( 'POST', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_APPROVE );
		$req->set_body_params( array( 'post_id' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( Cleanup_Orchestrator::STATUS_APPROVED, $response->get_data()['status'] );
		self::assertSame( Cleanup_Orchestrator::STATUS_APPROVED, Cleanup_Orchestrator::get_status( $post_id ) );
	}

	public function test_approve_on_pending_post_returns_409(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<p>Body.</p>', 'post_status' => 'publish' )
		);
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_PENDING );

		$req = new WP_REST_Request( 'POST', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_APPROVE );
		$req->set_body_params( array( 'post_id' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'cleanup_not_done', $response->get_data()['code'] );
	}

	public function test_approve_is_idempotent(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_done_post();
		Cleanup_Orchestrator::approve( $post_id );

		$req = new WP_REST_Request( 'POST', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_APPROVE );
		$req->set_body_params( array( 'post_id' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( Cleanup_Orchestrator::STATUS_APPROVED, $response->get_data()['status'] );
	}

	public function test_reject_transitions_done_to_rejected(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_done_post();

		$req = new WP_REST_Request( 'POST', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_REJECT );
		$req->set_body_params( array( 'post_id' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( Cleanup_Orchestrator::STATUS_REJECTED, $response->get_data()['status'] );
	}

	public function test_reject_on_failed_post_returns_409(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<p>Body.</p>', 'post_status' => 'publish' )
		);
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_FAILED );

		$req = new WP_REST_Request( 'POST', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_REJECT );
		$req->set_body_params( array( 'post_id' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 409, $response->get_status() );
	}

	public function test_regenerate_invalidates_and_schedules(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_done_post();

		$req = new WP_REST_Request( 'POST', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_REGENERATE );
		$req->set_body_params( array( 'post_id' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( Cleanup_Orchestrator::STATUS_PENDING, $response->get_data()['status'] );
		self::assertNotFalse(
			\wp_next_scheduled( Cleanup_Orchestrator::SCHEDULE_ACTION, array( $post_id ) ),
			'regenerate must queue a cron event'
		);
	}

	public function test_regenerate_is_idempotent_when_already_pending(): void {
		\wp_set_current_user( $this->make_admin() );
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<p>Body.</p>', 'post_status' => 'publish' )
		);
		\update_post_meta( $post_id, Cleanup_Orchestrator::META_KEY_STATUS, Cleanup_Orchestrator::STATUS_PENDING );

		$req = new WP_REST_Request( 'POST', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_REGENERATE );
		$req->set_body_params( array( 'post_id' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 200, $response->get_status() );
		// Still pending (no transition).
		self::assertSame( Cleanup_Orchestrator::STATUS_PENDING, $response->get_data()['status'] );
	}

	public function test_module_disabled_returns_403(): void {
		\wp_set_current_user( $this->make_admin() );
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			\array_merge(
				Context_Profile_Settings::get_defaults(),
				array( 'markdown_views_enabled' => false )
			)
		);
		$post_id = self::factory()->post->create();

		$req = new WP_REST_Request( 'GET', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_STATE );
		$req->set_query_params( array( 'post' => $post_id ) );

		$response = \rest_do_request( $req );

		self::assertSame( 403, $response->get_status() );
	}

	public function test_get_does_not_mutate_cleanup_state(): void {
		// Regression guard for the bug fixed in c301434+follow-up: when
		// `build_state_response` called `Service::get_markdown_for_post`,
		// its should_clean-then-schedule side effect flipped a `done`
		// state to `pending` on every GET. The handler must be a pure
		// read on the cleanup post-meta keys.
		\wp_set_current_user( $this->make_admin() );
		$post_id = $this->seed_done_post();

		$req = new WP_REST_Request(
			'GET',
			'/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_STATE
		);
		$req->set_query_params( array( 'post' => $post_id ) );

		\rest_do_request( $req );

		// Status must still be `done` after the GET — no mutation.
		self::assertSame(
			Cleanup_Orchestrator::STATUS_DONE,
			Cleanup_Orchestrator::get_status( $post_id ),
			'GET endpoint must not mutate cleanup state.'
		);
	}

	public function test_invalid_post_id_returns_400(): void {
		\wp_set_current_user( $this->make_admin() );

		$req = new WP_REST_Request( 'GET', '/' . Cleanup_Rest_Controller::NAMESPACE . Cleanup_Rest_Controller::ROUTE_STATE );
		$req->set_query_params( array( 'post' => 0 ) );

		$response = \rest_do_request( $req );

		// REST validation may catch this earlier (400 from `validate_callback`)
		// or the permission gate (400 with rest_invalid_post).
		self::assertContains( $response->get_status(), array( 400, 403 ) );
	}
}
