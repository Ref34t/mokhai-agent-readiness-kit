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
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use WP_Post;
use WPContext\Admin\Context_Profile_Settings;

final class Context_Profile_Exposure_Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpctx_test_post_types']     = array( 'post', 'page' );
		$GLOBALS['wpctx_test_options']        = array();
		$GLOBALS['wpctx_test_capabilities']   = array( 'manage_options' => true );
		$GLOBALS['wpctx_test_did_action']     = array();
		$GLOBALS['wpctx_test_active_plugins'] = array();
		$GLOBALS['wpctx_test_added_actions']  = array();
		$GLOBALS['wpctx_test_filters']        = array();
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
		$GLOBALS['wpctx_test_filters']['agentready_post_is_noindexed'][] = static function () {
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
}
