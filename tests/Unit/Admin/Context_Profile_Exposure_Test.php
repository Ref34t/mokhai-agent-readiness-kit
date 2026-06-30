<?php
/**
 * Unit tests for the exposure + module-enabled API added to
 * Context_Profile_Settings as part of #5 (per AgDR-0012, AgDR-0015).
 *
 * Covers:
 *   - `is_module_enabled()` reads the per-module flag, defaults true on unknown modules
 *   - `is_url_exposable()` enforces CPT whitelist, status whitelist, password gate,
 *     and the `agentready_post_is_noindexed` filter
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use WP_Post;
use Mokhai\Admin\Context_Profile_Settings;

final class Context_Profile_Exposure_Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpctx_test_post_types']     = array( 'post', 'page' );
		$GLOBALS['wpctx_test_options']        = array();
		$GLOBALS['wpctx_test_capabilities']   = array( 'manage_options' => true );
		$GLOBALS['wpctx_test_did_action']     = array();
		$GLOBALS['wpctx_test_active_plugins'] = array();
		$GLOBALS['wpctx_test_added_actions']  = array();
		$GLOBALS['mokhai_test_filters']        = array();
		$GLOBALS['wpctx_test_post_terms']     = array();
	}

	private function set_profile( array $overrides ): void {
		$GLOBALS['wpctx_test_options'][ Context_Profile_Settings::OPTION_KEY ] = array_merge(
			Context_Profile_Settings::get_defaults(),
			$overrides
		);
	}

	private function make_post( array $properties = array() ): WP_Post {
		$post = new WP_Post();
		foreach ( $properties as $key => $value ) {
			$post->$key = $value;
		}
		return $post;
	}

	// ----------------------------------------------------------------------
	// is_module_enabled
	// ----------------------------------------------------------------------

	public function test_markdown_views_defaults_to_enabled(): void {
		self::assertTrue( Context_Profile_Settings::is_module_enabled( 'markdown_views' ) );
	}

	public function test_module_enabled_returns_false_when_admin_disabled(): void {
		$this->set_profile( array( 'markdown_views_enabled' => false ) );
		self::assertFalse( Context_Profile_Settings::is_module_enabled( 'markdown_views' ) );
	}

	public function test_unknown_module_defaults_to_enabled(): void {
		// A module that doesn't yet have a flag in the profile should be
		// considered enabled — discovery of the toggle's presence is
		// decoupled from discovery of the module's code.
		self::assertTrue( Context_Profile_Settings::is_module_enabled( 'tomorrow_module' ) );
	}

	// ----------------------------------------------------------------------
	// is_url_exposable — CPT gate
	// ----------------------------------------------------------------------

	public function test_post_with_non_exposed_cpt_is_not_exposable(): void {
		// Defaults: exposed_cpts === []
		$post = $this->make_post( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_post_with_exposed_cpt_passes_cpt_gate(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );
		$post = $this->make_post( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	// ----------------------------------------------------------------------
	// is_url_exposable — status gate
	// ----------------------------------------------------------------------

	public function test_draft_post_is_not_exposable_under_default_statuses(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );
		$post = $this->make_post( array( 'post_type' => 'post', 'post_status' => 'draft' ) );
		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_post_is_exposable_when_status_is_explicitly_allowed(): void {
		$this->set_profile(
			array(
				'exposed_cpts'     => array( 'post' ),
				'exposed_statuses' => array( 'publish', 'private' ),
			)
		);
		$post = $this->make_post( array( 'post_type' => 'post', 'post_status' => 'private' ) );
		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	// ----------------------------------------------------------------------
	// is_url_exposable — password gate (hardcoded, not configurable)
	// ----------------------------------------------------------------------

	public function test_password_protected_post_is_never_exposable(): void {
		$this->set_profile(
			array(
				'exposed_cpts'     => array( 'post' ),
				'exposed_statuses' => array( 'publish' ),
			)
		);
		$post = $this->make_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_password' => 'sekret',
			)
		);
		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	// ----------------------------------------------------------------------
	// is_url_exposable — noindex filter (#12's extension point)
	// ----------------------------------------------------------------------

	public function test_noindex_filter_can_deny_an_otherwise_exposable_post(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );

		// Simulate #12's future hook: declare this post noindex.
		$GLOBALS['mokhai_test_filters']['agentready_post_is_noindexed'][] = static function () {
			return true;
		};

		$post = $this->make_post( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_noindex_filter_default_is_false(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );

		$post = $this->make_post( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	// ----------------------------------------------------------------------
	// is_url_exposable — content exclusions (#180)
	// ----------------------------------------------------------------------

	public function test_per_post_exclude_meta_denies_an_otherwise_exposable_post(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );
		$GLOBALS['wpctx_test_post_meta'][42][ Context_Profile_Settings::EXCLUDE_META_KEY ] = '1';

		$post = $this->make_post(
			array(
				'ID'          => 42,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
		self::assertSame( 'excluded', Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_exclude_meta_zero_does_not_deny(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );
		$GLOBALS['wpctx_test_post_meta'][7][ Context_Profile_Settings::EXCLUDE_META_KEY ] = '0';

		$post = $this->make_post(
			array(
				'ID'          => 7,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_name'   => 'real-post',
			)
		);

		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_post_on_excluded_ids_list_is_not_exposable(): void {
		$this->set_profile(
			array(
				'exposed_cpts' => array( 'post' ),
				'excluded_ids' => array( 99 ),
			)
		);

		$post = $this->make_post(
			array(
				'ID'          => 99,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
		self::assertSame( 'excluded', Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_post_on_excluded_slugs_list_is_not_exposable(): void {
		$this->set_profile(
			array(
				'exposed_cpts'   => array( 'post' ),
				'excluded_slugs' => array( 'secret-draft' ),
			)
		);

		$post = $this->make_post(
			array(
				'ID'          => 5,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_name'   => 'secret-draft',
			)
		);

		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	// ----------------------------------------------------------------------
	// is_url_exposable — term-based exclusions (#188)
	// ----------------------------------------------------------------------

	public function test_post_in_excluded_category_id_is_not_exposable(): void {
		$this->set_profile(
			array(
				'exposed_cpts'      => array( 'post' ),
				'excluded_term_ids' => array( 12 ),
			)
		);
		$GLOBALS['wpctx_test_post_terms'][5]['category'] = array( 12, 'news' );

		$post = $this->make_post(
			array(
				'ID'          => 5,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
		self::assertSame( 'excluded', Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_post_with_excluded_tag_slug_is_not_exposable(): void {
		$this->set_profile(
			array(
				'exposed_cpts'        => array( 'post' ),
				'excluded_term_slugs' => array( 'internal' ),
			)
		);
		$GLOBALS['wpctx_test_post_terms'][5]['post_tag'] = array( 'internal' );

		$post = $this->make_post(
			array(
				'ID'          => 5,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
		self::assertSame( 'excluded', Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_post_without_excluded_terms_stays_exposable(): void {
		$this->set_profile(
			array(
				'exposed_cpts'        => array( 'post' ),
				'excluded_term_ids'   => array( 12 ),
				'excluded_term_slugs' => array( 'internal' ),
			)
		);
		$GLOBALS['wpctx_test_post_terms'][5]['category'] = array( 'news', 33 );
		$GLOBALS['wpctx_test_post_terms'][5]['post_tag'] = array( 'public' );

		$post = $this->make_post(
			array(
				'ID'          => 5,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_name'   => 'real-post',
			)
		);

		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_empty_term_lists_do_not_deny(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );

		$post = $this->make_post(
			array(
				'ID'          => 5,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_name'   => 'real-post',
			)
		);

		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_wp_sample_content_is_excluded_by_default(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'page' ) ) );

		$post = $this->make_post(
			array(
				'ID'          => 2,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'sample-page',
			)
		);

		self::assertFalse( Context_Profile_Settings::is_url_exposable( $post ) );
		self::assertSame( 'sample', Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_wp_sample_content_is_exposable_when_toggle_off(): void {
		$this->set_profile(
			array(
				'exposed_cpts'       => array( 'post' ),
				'exclude_wp_samples' => false,
			)
		);

		$post = $this->make_post(
			array(
				'ID'          => 1,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_name'   => 'hello-world',
			)
		);

		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
	}

	public function test_ordinary_post_with_no_exclusions_is_exposable(): void {
		$this->set_profile( array( 'exposed_cpts' => array( 'post' ) ) );

		$post = $this->make_post(
			array(
				'ID'          => 11,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_name'   => 'a-real-article',
			)
		);

		self::assertTrue( Context_Profile_Settings::is_url_exposable( $post ) );
		self::assertNull( Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	// ----------------------------------------------------------------------
	// defaults + deny-list sanitisation (#180)
	// ----------------------------------------------------------------------

	public function test_defaults_include_exclusion_keys(): void {
		$defaults = Context_Profile_Settings::get_defaults();
		self::assertSame( array(), $defaults['excluded_ids'] );
		self::assertSame( array(), $defaults['excluded_slugs'] );
		self::assertTrue( $defaults['exclude_wp_samples'] );
	}

	public function test_excluded_ids_are_sanitised_to_unique_positive_ints(): void {
		// 'not-a-number' → 0 (dropped); 0 dropped; -3 → 3 (absint takes the
		// absolute value, mirroring WP); duplicate 42 collapses.
		$this->set_profile(
			array(
				'excluded_ids' => array( '42', 'not-a-number', -3, 0, 42, 7 ),
			)
		);

		$profile = Context_Profile_Settings::get_profile();
		self::assertSame( array( 42, 3, 7 ), $profile['excluded_ids'] );
	}

	public function test_excluded_slugs_are_sanitised_to_slug_form(): void {
		$this->set_profile(
			array(
				'excluded_slugs' => array( 'Sample Page', 'sample-page', '   ', 'Tender for Restoration' ),
			)
		);

		$profile = Context_Profile_Settings::get_profile();
		self::assertSame( array( 'sample-page', 'tender-for-restoration' ), $profile['excluded_slugs'] );
	}

	public function test_term_lists_default_empty_and_sanitise_like_post_lists(): void {
		$defaults = Context_Profile_Settings::get_defaults();
		self::assertSame( array(), $defaults['excluded_term_ids'] );
		self::assertSame( array(), $defaults['excluded_term_slugs'] );

		$this->set_profile(
			array(
				'excluded_term_ids'   => array( '12', 'junk', -4, 0, 12 ),
				'excluded_term_slugs' => array( 'Internal Docs', 'internal-docs', '   ' ),
			)
		);

		$profile = Context_Profile_Settings::get_profile();
		self::assertSame( array( 12, 4 ), $profile['excluded_term_ids'] );
		self::assertSame( array( 'internal-docs' ), $profile['excluded_term_slugs'] );
	}
}
