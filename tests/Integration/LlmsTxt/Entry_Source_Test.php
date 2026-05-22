<?php
/**
 * Integration tests for WPContext\LlmsTxt\Entry_Source.
 *
 * Runs inside the wp-phpunit test instance so each branch of
 * `Entry_Source::get_sections()` is exercised against real `WP_Query`
 * dispatch, real post-type registration, and real hook chains. Pins the
 * behaviours flagged in Rex review of #56:
 *
 *  - `PER_CPT_CAP` truncation enforcement
 *  - `Context_Profile_Settings::is_url_exposable()` gate (password-protected
 *    posts excluded even when their CPT/status match)
 *  - `agentready_llms_txt_entry_description` filter precedence over the
 *    `post_excerpt` fallback
 *  - `(no title)` fallback for posts saved without a title
 *  - Custom-CPT label resolution via `get_post_type_object`
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\LlmsTxt\Entry_Source;
use WPContext\LlmsTxt\Service;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Entry_Source_Test extends WP_UnitTestCase {

	/**
	 * Slug for the custom post type registered for label-resolution and
	 * cap-enforcement scenarios. Kept on the instance so `tearDown()` can
	 * unregister it without leaking into sibling tests.
	 *
	 * @var string
	 */
	private const CUSTOM_CPT = 'agentready_test_doc';

	protected function setUp(): void {
		parent::setUp();

		// `factory()->post->create()` fires `save_post`, which the plugin's
		// Markdown_Views\Service uses to invalidate rows in
		// `wp_agentready_md_cache`. The wp-env bootstrap drops that table
		// (see tests/bootstrap.php), so we re-create it for every test that
		// touches the post lifecycle — matches the pattern in
		// `Service_Test::setUp()`.
		Markdown_Views_Schema::create();

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );

		// Seed the Context Profile so `is_url_exposable()` will pass for
		// `post` and `publish` by default. Individual tests override the
		// profile when they need to expose the custom CPT below.
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
		// test starts with a deterministic "no regen pending" state.
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
	}

	protected function tearDown(): void {
		// Drop any custom-CPT registration leaking out of a test. `unregister_post_type`
		// is a no-op when the slug isn't registered, so it's safe to call unconditionally.
		if ( post_type_exists( self::CUSTOM_CPT ) ) {
			unregister_post_type( self::CUSTOM_CPT );
		}

		// Strip any description filter callbacks added by tests.
		remove_all_filters( Entry_Source::DESCRIPTION_FILTER );

		Service::invalidate();
		delete_transient( Service::REGEN_LOCK_TRANSIENT );
		wp_clear_scheduled_hook( Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );

		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	/**
	 * Register a custom post type with a human-readable plural label and
	 * expose it via the Context Profile so `Entry_Source` will index it.
	 *
	 * @param string $plural_label Plural label used for the section header.
	 */
	private function register_and_expose_custom_cpt( string $plural_label = 'Test Documents' ): void {
		register_post_type(
			self::CUSTOM_CPT,
			array(
				'public'      => true,
				'label'       => $plural_label,
				'labels'      => array(
					'name'          => $plural_label,
					'singular_name' => 'Test Document',
				),
				'has_archive' => true,
				'show_ui'     => true,
			)
		);

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( self::CUSTOM_CPT ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);
	}

	public function test_per_cpt_cap_truncates_entries_above_the_limit(): void {
		// Using a much smaller surplus (5 over cap) than the documented cap
		// of 1000 keeps the test fast while still proving the truncation
		// applies. The cap value comes directly from the constant so a
		// future revision auto-tracks.
		$this->register_and_expose_custom_cpt();

		$total = Entry_Source::PER_CPT_CAP + 5;
		for ( $i = 0; $i < $total; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'  => sprintf( 'Doc %04d', $i ),
					'post_status' => 'publish',
					'post_type'   => self::CUSTOM_CPT,
				)
			);
		}

		$sections = Entry_Source::get_sections();

		$this->assertCount( 1, $sections, 'Expected exactly one section for the custom CPT.' );
		$this->assertCount(
			Entry_Source::PER_CPT_CAP,
			$sections[0]['entries'],
			'Section entry count must equal PER_CPT_CAP even when more posts exist.'
		);
	}

	public function test_password_protected_post_is_excluded_via_is_url_exposable(): void {
		$visible_id = self::factory()->post->create(
			array(
				'post_title'  => 'Public note',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$protected_id = self::factory()->post->create(
			array(
				'post_title'    => 'Locked note',
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => 'secret-passphrase',
			)
		);

		// Sanity: the gate denies the protected post and admits the public one.
		$this->assertFalse(
			Context_Profile_Settings::is_url_exposable( get_post( $protected_id ) ),
			'Password-protected post must be denied by the exposability gate.'
		);
		$this->assertTrue(
			Context_Profile_Settings::is_url_exposable( get_post( $visible_id ) )
		);

		$sections = Entry_Source::get_sections();

		$this->assertCount( 1, $sections );
		$titles = array_column( $sections[0]['entries'], 'title' );
		$this->assertContains( 'Public note', $titles );
		$this->assertNotContains(
			'Locked note',
			$titles,
			'Password-protected post must not surface in Entry_Source output.'
		);
	}

	public function test_description_filter_takes_precedence_over_post_excerpt(): void {
		self::factory()->post->create(
			array(
				'post_title'   => 'Filter wins',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_excerpt' => 'Excerpt that must be ignored',
			)
		);

		add_filter(
			Entry_Source::DESCRIPTION_FILTER,
			static function ( $default, $post ) {
				return 'Filtered description from hook';
			},
			10,
			2
		);

		$sections = Entry_Source::get_sections();

		$this->assertCount( 1, $sections );
		$this->assertCount( 1, $sections[0]['entries'] );

		$entry = $sections[0]['entries'][0];
		$this->assertArrayHasKey( 'description', $entry );
		$this->assertSame(
			'Filtered description from hook',
			$entry['description'],
			'Description filter return value must short-circuit the excerpt fallback.'
		);
	}

	public function test_post_without_title_renders_no_title_fallback(): void {
		self::factory()->post->create(
			array(
				'post_title'  => '',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$sections = Entry_Source::get_sections();

		$this->assertCount( 1, $sections );
		$this->assertCount( 1, $sections[0]['entries'] );
		$this->assertSame(
			'(no title)',
			$sections[0]['entries'][0]['title'],
			'Empty post_title must collapse to the (no title) placeholder.'
		);
	}

	public function test_custom_cpt_label_appears_as_section_header(): void {
		$this->register_and_expose_custom_cpt( 'Field Reports' );

		self::factory()->post->create(
			array(
				'post_title'  => 'Q1 site visit',
				'post_status' => 'publish',
				'post_type'   => self::CUSTOM_CPT,
			)
		);

		$sections = Entry_Source::get_sections();

		$this->assertCount( 1, $sections );
		$this->assertSame(
			'Field Reports',
			$sections[0]['label'],
			'Section header must use the registered plural label, not the CPT slug.'
		);
	}
}
