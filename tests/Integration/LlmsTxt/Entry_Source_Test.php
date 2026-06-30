<?php
/**
 * Integration tests for Mokhai\LlmsTxt\Entry_Source.
 *
 * Runs inside the wp-phpunit test instance so each branch of
 * `Entry_Source::get_sections()` is exercised against real `WP_Query`
 * dispatch, real post-type registration, and real hook chains. Pins the
 * behaviours flagged in Rex review of #56:
 *
 *  - `PER_CPT_CAP` truncation enforcement
 *  - `Context_Profile_Settings::is_url_exposable()` gate (password-protected
 *    posts excluded even when their CPT/status match)
 *  - `mokhai_llms_txt_entry_description` filter precedence over the
 *    `post_excerpt` fallback
 *  - `(no title)` fallback for posts saved without a title
 *  - Custom-CPT label resolution via `get_post_type_object`
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\LlmsTxt\Entry_Source;
use Mokhai\LlmsTxt\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

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
		// `wp_mokhai_md_cache`. The wp-env bootstrap drops that table
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

	public function test_excluded_and_sample_posts_are_dropped_from_entries(): void {
		$visible_id = self::factory()->post->create(
			array(
				'post_title'  => 'Real article',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Per-post exclude toggle (#180).
		$meta_excluded_id = self::factory()->post->create(
			array(
				'post_title'  => 'Manually excluded',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		update_post_meta( $meta_excluded_id, Context_Profile_Settings::EXCLUDE_META_KEY, '1' );

		// WordPress sample content excluded by the default exclude_wp_samples
		// toggle (slug match, regardless of title).
		$sample_id = self::factory()->post->create(
			array(
				'post_title'  => 'Sample Page',
				'post_name'   => 'sample-page',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->assertTrue( Context_Profile_Settings::is_url_exposable( get_post( $visible_id ) ) );
		$this->assertFalse(
			Context_Profile_Settings::is_url_exposable( get_post( $meta_excluded_id ) ),
			'Per-post exclude meta must deny the post.'
		);
		$this->assertFalse(
			Context_Profile_Settings::is_url_exposable( get_post( $sample_id ) ),
			'WP sample content must be denied by the default exclude_wp_samples toggle.'
		);

		$sections = Entry_Source::get_sections();

		$this->assertCount( 1, $sections );
		$titles = array_column( $sections[0]['entries'], 'title' );
		$this->assertContains( 'Real article', $titles );
		$this->assertNotContains( 'Manually excluded', $titles );
		$this->assertNotContains( 'Sample Page', $titles );
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

	/**
	 * Regression for Ref34t/agentready#242.
	 *
	 * Multilingual plugins (WPML / Polylang) hook `the_title` and can resolve
	 * it to an empty string when `/llms.txt` is composed in a no-language
	 * context (WP-CLI regen, cron). Simulating that filter, the entry must fall
	 * back to the raw stored `post_title` instead of rendering `(no title)`.
	 */
	public function test_empty_filtered_title_falls_back_to_raw_post_title(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Privacy Policy',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		// Stand in for a multilingual plugin blanking the filtered title.
		$blank_the_title = static function () {
			return '';
		};
		add_filter( 'the_title', $blank_the_title, 10, 0 );

		try {
			$sections = Entry_Source::get_sections();
		} finally {
			remove_filter( 'the_title', $blank_the_title, 10 );
		}

		$this->assertCount( 1, $sections );
		$this->assertCount( 1, $sections[0]['entries'] );
		$this->assertSame(
			'Privacy Policy',
			$sections[0]['entries'][0]['title'],
			'A filtered-empty title must fall back to the raw post_title, not (no title).'
		);
	}

	/**
	 * Regression for Ref34t/agentready#105.
	 *
	 * `/llms.txt` is an agent discovery surface — its links should point at
	 * the `.md` form (small, clean Markdown) rather than the canonical HTML
	 * page (large, navigation-heavy). When the Markdown Views module is
	 * enabled, entry URLs must transform pretty permalinks to `<slug>.md`.
	 */
	public function test_entry_url_uses_md_form_when_markdown_views_enabled(): void {
		// wp-env's test instance defaults to plain permalinks (`?p=N`).
		// Flip to pretty permalinks for this test so we exercise the
		// `.md` suffix branch of the transform (the plain-permalinks
		// `?format=md` branch has its own test below).
		$original_structure = (string) \get_option( 'permalink_structure' );
		\update_option( 'permalink_structure', '/%postname%/' );
		\flush_rewrite_rules( false );

		try {
			// Default Profile (seeded in setUp) has markdown_views_enabled=true.
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'A typical post',
					'post_name'   => 'a-typical-post',
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$sections = Entry_Source::get_sections();
			$this->assertNotEmpty( $sections );

			$entry = $sections[0]['entries'][0];
			$this->assertStringEndsWith(
				'.md',
				$entry['url'],
				'Pretty-permalink URL must end in .md when Markdown Views is on.'
			);
			$this->assertStringNotContainsString(
				'a-typical-post/',
				$entry['url'],
				'Trailing slash must be stripped before appending .md.'
			);

			// Sanity: the .md URL still resolves to the same post via Router.
			$this->assertSame(
				\rtrim( (string) \get_permalink( $post_id ), '/' ) . '.md',
				$entry['url']
			);
		} finally {
			\update_option( 'permalink_structure', $original_structure );
			\flush_rewrite_rules( false );
		}
	}

	/**
	 * If the operator has turned the Markdown Views module off, /llms.txt
	 * cannot link at the `.md` form — the rewrite is still registered but
	 * Handler will 404 on it. Fall back to the canonical permalink.
	 *
	 * Parameterised across both permalink modes (#116). The MV-disabled
	 * fall-through must hold regardless of whether the site runs pretty
	 * (`/%postname%/`) or plain (`?p=N`) permalinks — neither the `.md`
	 * suffix nor the `?format=md` query may be appended. The earlier
	 * single-mode version only exercised whatever structure the test
	 * instance happened to default to (plain), leaving the pretty-permalink
	 * branch of the disabled path unproven.
	 *
	 * @dataProvider permalink_structure_provider
	 *
	 * @param string $permalink_structure Value to set for `permalink_structure`.
	 * @param string $mode_label          Human-readable mode name for assertion messages.
	 */
	public function test_entry_url_stays_canonical_when_markdown_views_disabled( string $permalink_structure, string $mode_label ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post' ),
					'exposed_statuses'       => array( 'publish' ),
					'markdown_views_enabled' => false,
				)
			)
		);

		$original_structure = (string) \get_option( 'permalink_structure' );
		\update_option( 'permalink_structure', $permalink_structure );
		\flush_rewrite_rules( false );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'MV-off post',
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$sections = Entry_Source::get_sections();
			$entry    = $sections[0]['entries'][0];

			$this->assertSame(
				(string) \get_permalink( $post_id ),
				$entry['url'],
				sprintf( 'With MV disabled (%s permalinks), the URL must be the canonical permalink — no transform.', $mode_label )
			);
			$this->assertStringEndsNotWith(
				'.md',
				$entry['url'],
				sprintf( 'No .md suffix may be appended with MV disabled (%s permalinks).', $mode_label )
			);
			$this->assertStringNotContainsString(
				'format=md',
				$entry['url'],
				sprintf( 'No ?format=md query may be appended with MV disabled (%s permalinks).', $mode_label )
			);
		} finally {
			\update_option( 'permalink_structure', $original_structure );
			\flush_rewrite_rules( false );
		}
	}

	/**
	 * Permalink structures exercised by the MV-disabled canonical-URL test.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function permalink_structure_provider(): array {
		return array(
			'pretty' => array( '/%postname%/', 'pretty' ),
			'plain'  => array( '', 'plain' ),
		);
	}

	/**
	 * On a site running plain permalinks (`?p=<id>`), the `.md` rewrite
	 * doesn't apply — fall through to the `?format=md` content-negotiation
	 * form so the agent still reaches the Markdown response.
	 */
	public function test_entry_url_uses_format_md_query_for_plain_permalinks(): void {
		$original_structure = (string) \get_option( 'permalink_structure' );
		\update_option( 'permalink_structure', '' );
		\flush_rewrite_rules( false );

		try {
			self::factory()->post->create(
				array(
					'post_title'  => 'Plain-permalink post',
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$sections = Entry_Source::get_sections();
			$entry    = $sections[0]['entries'][0];

			$this->assertStringContainsString(
				'format=md',
				$entry['url'],
				'Plain permalinks must fall through to the ?format=md query form.'
			);
			$this->assertStringEndsNotWith(
				'.md',
				$entry['url'],
				'No .md suffix on plain-permalink URLs — the rewrite would not match.'
			);
		} finally {
			\update_option( 'permalink_structure', $original_structure );
			\flush_rewrite_rules( false );
		}
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
