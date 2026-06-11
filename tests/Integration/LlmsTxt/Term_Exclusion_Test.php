<?php
/**
 * Integration tests for term-based content exclusion (#188).
 *
 * Covers both halves of the feature against real taxonomies:
 * the exposure gate (a post in an excluded category / tag is denied with
 * reason `excluded` via real `has_term()` lookups), and the
 * `set_object_terms` regen listener (a programmatic `wp_set_post_terms()`
 * fires no save_post, so the listener is the only thing keeping /llms.txt
 * fresh — same trigger class as the #190 exclude-meta listeners).
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\LlmsTxt\Service;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Term_Exclusion_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Recreate the md_cache table the wp-env test bootstrap drops, so
		// save_post-wired Markdown_Views invalidation stays quiet.
		Markdown_Views_Schema::create();

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );

		$this->set_profile( array() );

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
	 * @param array<string, mixed> $overrides Profile keys to override.
	 */
	private function set_profile( array $overrides ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post' ),
					'exposed_statuses' => array( 'publish' ),
				),
				$overrides
			)
		);
	}

	/**
	 * Published post with the regen queue drained, so any scheduled event
	 * can only come from the action under test.
	 */
	private function staged_post(): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );

		return $post_id;
	}

	// ------------------------------------------------------------------
	// Exposure gate
	// ------------------------------------------------------------------

	public function test_post_in_excluded_category_by_id_is_denied(): void {
		$term_id = self::factory()->category->create( array( 'slug' => 'internal' ) );
		$this->set_profile( array( 'excluded_term_ids' => array( $term_id ) ) );

		$post_id = $this->staged_post();
		wp_set_post_terms( $post_id, array( $term_id ), 'category' );

		$post = get_post( $post_id );
		self::assertSame( 'excluded', Context_Profile_Settings::get_exposure_reason( $post ) );
		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_post_with_excluded_tag_by_slug_is_denied(): void {
		self::factory()->tag->create( array( 'slug' => 'archive' ) );
		$this->set_profile( array( 'excluded_term_slugs' => array( 'archive' ) ) );

		$post_id = $this->staged_post();
		wp_set_post_terms( $post_id, array( 'archive' ), 'post_tag' );

		$post = get_post( $post_id );
		self::assertSame( 'excluded', Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_post_in_unlisted_category_stays_exposable(): void {
		$listed   = self::factory()->category->create( array( 'slug' => 'internal' ) );
		$unlisted = self::factory()->category->create( array( 'slug' => 'news' ) );
		$this->set_profile( array( 'excluded_term_ids' => array( $listed ) ) );

		$post_id = $this->staged_post();
		wp_set_post_terms( $post_id, array( $unlisted ), 'category' );

		$post = get_post( $post_id );
		self::assertNull( Context_Profile_Settings::get_exposure_reason( $post ) );
		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	// ------------------------------------------------------------------
	// set_object_terms regen listener
	// ------------------------------------------------------------------

	public function test_programmatic_term_assignment_schedules_regen_when_lists_set(): void {
		$term_id = self::factory()->category->create( array( 'slug' => 'internal' ) );
		$this->set_profile( array( 'excluded_term_ids' => array( $term_id ) ) );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );

		$post_id = $this->staged_post();
		$this->assertFalse( wp_next_scheduled( Service::REGEN_ACTION ) );

		wp_set_post_terms( $post_id, array( $term_id ), 'category' );

		$this->assertNotFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'A programmatic term assignment must schedule a /llms.txt regen while term deny-lists are configured.'
		);
	}

	public function test_term_assignment_with_empty_lists_does_not_schedule(): void {
		$term_id = self::factory()->category->create( array( 'slug' => 'news' ) );
		$post_id = $this->staged_post();

		wp_set_post_terms( $post_id, array( $term_id ), 'category' );

		$this->assertFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'Term assignments must not schedule regens when no term deny-lists are configured.'
		);
	}

	public function test_non_content_taxonomy_does_not_schedule(): void {
		$this->set_profile( array( 'excluded_term_slugs' => array( 'internal' ) ) );
		register_taxonomy( 'wpctx_test_tax', 'post' );
		$term = wp_insert_term( 'shadow', 'wpctx_test_tax' );
		$post_id = $this->staged_post();

		wp_set_post_terms( $post_id, array( (int) $term['term_id'] ), 'wpctx_test_tax' );

		$this->assertFalse(
			wp_next_scheduled( Service::REGEN_ACTION ),
			'Assignments in non category/tag taxonomies must not schedule regens.'
		);

		unregister_taxonomy( 'wpctx_test_tax' );
	}
}
