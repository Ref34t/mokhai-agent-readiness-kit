<?php
/**
 * Integration tests for the agent-surface advertiser (#178).
 *
 * Drives the real hook callbacks against a booted WP: `wp_head` `<link>`
 * emission, exposure/toggle gating, and the `robots_txt` filter. The
 * `send_headers` Link header isn't asserted here — `header()` is a no-op under
 * the CLI SAPI and `headers_sent()` is already true once the runner has output,
 * so the callback short-circuits; its string is covered by the unit builder test
 * and its gating shares `current_md_url()` with the `wp_head` path tested below.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Discovery;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Discovery\Alternate_Advertiser;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Alternate_Advertiser_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// save_post invalidation hook in Markdown_Views\Service needs its table.
		Markdown_Views_Schema::create();

		// Opted-in profile: post@publish exposed, advertising + MD views on.
		$this->set_profile(
			array(
				'exposed_cpts'                 => array( 'post' ),
				'exposed_statuses'             => array( 'publish' ),
				'markdown_views_enabled'       => true,
				'advertise_alternates_enabled' => true,
			)
		);
	}

	protected function tearDown(): void {
		Markdown_Views_Schema::drop();
		parent::tearDown();
	}

	/** @param array<string,mixed> $overrides */
	private function set_profile( array $overrides ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge( Context_Profile_Settings::get_defaults(), $overrides )
		);
	}

	private function capture_head(): string {
		ob_start();
		Alternate_Advertiser::render_head_links();
		return (string) ob_get_clean();
	}

	public function test_exposable_singular_advertises_md_alternate(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$out = $this->capture_head();

		self::assertStringContainsString( 'rel="alternate"', $out );
		self::assertStringContainsString( 'type="text/markdown"', $out );
	}

	public function test_non_exposable_post_advertises_nothing(): void {
		// Draft is outside exposed_statuses → not exposable.
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		self::assertStringNotContainsString( 'type="text/markdown"', $this->capture_head() );
	}

	public function test_password_protected_post_advertises_nothing(): void {
		// cpt + status pass, but the .md route denies password-protected posts
		// (is_url_exposable → 'password'), so advertising one would 404.
		$post_id = $this->factory->post->create(
			array(
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => 'secret',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		self::assertStringNotContainsString( 'type="text/markdown"', $this->capture_head() );
	}

	public function test_noindexed_post_advertises_nothing(): void {
		// The #12 SEO-coordination surface marks a post noindex; the .md route
		// denies it (is_url_exposable → 'noindex'), so we must not advertise it.
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		add_filter( 'agentready_post_is_noindexed', '__return_true' );
		$this->go_to( get_permalink( $post_id ) );

		try {
			self::assertStringNotContainsString( 'type="text/markdown"', $this->capture_head() );
		} finally {
			remove_filter( 'agentready_post_is_noindexed', '__return_true' );
		}
	}

	public function test_markdown_views_disabled_suppresses_md_alternate(): void {
		$this->set_profile(
			array(
				'exposed_cpts'                 => array( 'post' ),
				'exposed_statuses'             => array( 'publish' ),
				'markdown_views_enabled'       => false,
				'advertise_alternates_enabled' => true,
			)
		);
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		self::assertStringNotContainsString( 'type="text/markdown"', $this->capture_head() );
	}

	public function test_toggle_off_suppresses_all_head_links(): void {
		$this->set_profile(
			array(
				'exposed_cpts'                 => array( 'post' ),
				'exposed_statuses'             => array( 'publish' ),
				'markdown_views_enabled'       => true,
				'advertise_alternates_enabled' => false,
			)
		);
		$post_id = $this->factory->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		self::assertSame( '', $this->capture_head() );
	}

	public function test_front_page_advertises_llms_txt(): void {
		$this->go_to( home_url( '/' ) );
		self::assertTrue( is_front_page(), 'Test setup must resolve to the front page.' );

		$out = $this->capture_head();

		self::assertStringContainsString( 'type="text/plain"', $out );
		self::assertStringContainsString( 'llms.txt', $out );
	}

	public function test_robots_txt_filter_appends_llms_reference_when_public(): void {
		$out = Alternate_Advertiser::filter_robots_txt( "User-agent: *\nDisallow:\n", true );

		self::assertStringContainsString( 'llms.txt', $out );
		self::assertStringContainsString( home_url( '/llms.txt' ), $out );
	}

	public function test_robots_txt_filter_untouched_when_not_public(): void {
		$base = "User-agent: *\nDisallow: /\n";
		self::assertSame( $base, Alternate_Advertiser::filter_robots_txt( $base, false ) );
	}

	public function test_robots_txt_filter_untouched_when_toggle_off(): void {
		$this->set_profile( array( 'advertise_alternates_enabled' => false ) );
		$base = "User-agent: *\nDisallow:\n";

		self::assertSame( $base, Alternate_Advertiser::filter_robots_txt( $base, true ) );
	}
}
