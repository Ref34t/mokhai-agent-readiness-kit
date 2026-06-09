<?php
/**
 * Integration tests for the exclude-meta → /llms.txt cache invalidation (#190).
 *
 * The composed /llms.txt body is exposure-gated at COMPOSE time, so its
 * correctness depends on a regen firing whenever an input changes. The
 * block-editor exclude toggle (#180) rides the `save_post` listener, but a
 * programmatic `update_post_meta()` / `wp post meta set` / REST meta write
 * fires none of the save_post-family hooks — before #190 the cached body kept
 * listing a freshly-excluded post for up to 24h (daily backstop). These tests
 * pin the `{added,updated,deleted}_post_meta` listeners that close that
 * window, including the exposed-CPT pre-check delegation and the
 * all-other-meta-keys noop.
 *
 * Staging discipline: every `factory()->post->create()` fires `save_post`,
 * which itself schedules a regen — each test clears REGEN_ACTION after
 * staging so the assertion observes only the meta-write trigger under test
 * (see Activation_Lifecycle_Test for the pattern's rationale).
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\LlmsTxt\Service;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Exclude_Meta_Invalidation_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Recreate the md_cache table the wp-env test bootstrap drops, so
		// save_post-wired Markdown_Views invalidation stays quiet (see
		// Service_Test setUp for the full rationale).
		Markdown_Views_Schema::create();

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );

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

		// The profile update above fires the saved-action hook chain, which
		// schedules a regen — clear it so each test starts with an empty queue.
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
	}

	protected function tearDown(): void {
		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );

		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	/**
	 * Create a published, exposed post and drain the regen queue so the next
	 * scheduled event can only come from the meta write under test.
	 */
	private function staged_post( string $title = 'Programmatic exclude fixture', string $type = 'post' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => $type,
			)
		);

		wp_clear_scheduled_hook( Service::REGEN_ACTION );

		return $post_id;
	}

	public function test_programmatic_exclude_write_schedules_regen(): void {
		$post_id = $this->staged_post();

		$this->assertFalse( wp_next_scheduled( Service::REGEN_ACTION ) );

		// First write on a post without the meta row — fires `added_post_meta`.
		update_post_meta( $post_id, Context_Profile_Settings::EXCLUDE_META_KEY, '1' );

		$this->assertNotFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'A programmatic exclude write must schedule a /llms.txt regen.'
		);
	}

	public function test_exclude_meta_update_and_delete_also_schedule(): void {
		$post_id = $this->staged_post();
		update_post_meta( $post_id, Context_Profile_Settings::EXCLUDE_META_KEY, '1' );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );

		// Value change on an existing row — fires `updated_post_meta`.
		update_post_meta( $post_id, Context_Profile_Settings::EXCLUDE_META_KEY, false );
		$this->assertNotFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'Un-excluding via update_post_meta must schedule a regen.'
		);

		wp_clear_scheduled_hook( Service::REGEN_ACTION );

		// Row removal — fires `deleted_post_meta`.
		delete_post_meta( $post_id, Context_Profile_Settings::EXCLUDE_META_KEY );
		$this->assertNotFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'Deleting the exclude meta row must schedule a regen.'
		);
	}

	public function test_other_meta_writes_do_not_schedule(): void {
		$post_id = $this->staged_post();

		update_post_meta( $post_id, '_wpctx_unrelated_key', 'value' );

		$this->assertFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'Meta writes to other keys must not trigger a regen.'
		);
	}

	public function test_exclude_write_on_unexposed_cpt_does_not_schedule(): void {
		// Profile exposes only `post`; a page can never appear in /llms.txt,
		// so its exclude flag must not drag a regen into the cron queue
		// (the on_post_change pre-check delegation).
		$page_id = $this->staged_post( 'An unexposed page', 'page' );

		update_post_meta( $page_id, Context_Profile_Settings::EXCLUDE_META_KEY, '1' );

		$this->assertFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'Exclude writes on a non-exposed post type must not schedule a regen.'
		);
	}

	public function test_excluded_post_leaves_the_composed_body_on_regen(): void {
		$post_id = $this->staged_post( 'Composed body exclusion fixture' );

		$this->assertStringContainsString( 'Composed body exclusion fixture', Service::regen_sync() );

		update_post_meta( $post_id, Context_Profile_Settings::EXCLUDE_META_KEY, '1' );
		$this->assertNotFalse( wp_next_scheduled( Service::REGEN_ACTION ) );

		// Run the scheduled work the way cron would.
		Service::do_regen();

		$cache = Service::get_cache_payload();
		$this->assertIsArray( $cache );
		$this->assertStringNotContainsString(
			'Composed body exclusion fixture',
			(string) $cache['body'],
			'The regen triggered by the exclude write must drop the post from /llms.txt.'
		);
	}
}
