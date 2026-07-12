<?php
/**
 * Integration tests for the hidden in-content discovery link (#283 /
 * AgDR-0067).
 *
 * Drives the real `the_content` filter against a booted WP inside a genuine
 * main-query loop (`go_to()` + `the_post()`), plus the guards that keep the
 * anchor OUT of every non-loop `the_content` application — most critically
 * the plugin's own markdown pipeline, which would otherwise embed the link
 * recursively in the `.md` body it points at.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Discovery;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Discovery\Content_Link;
use Mokhai\Markdown_Views\Schema;
use Mokhai\Markdown_Views\Service;

final class Content_Link_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Pretty permalinks so the link target takes the `.md`-suffix form
		// asserted below (plain permalinks produce `?p=N&format=md`).
		$this->set_permalink_structure( '/%postname%/' );

		Schema::create();

		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post' ),
					'exposed_statuses'       => array( 'publish' ),
					'markdown_views_enabled' => true,
					'content_link_enabled'   => true,
				)
			)
		);
	}

	protected function tearDown(): void {
		Schema::drop();
		parent::tearDown();
	}

	private function create_exposable_post(): int {
		return (int) $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_content' => '<p>Article body.</p>',
			)
		);
	}

	/**
	 * Render a post's content through `the_content` inside the real main
	 * loop, the way a theme template does.
	 */
	private function render_in_loop( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );

		$output = '';
		while ( have_posts() ) {
			the_post();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$output .= (string) apply_filters( 'the_content', get_the_content() );
		}

		return $output;
	}

	public function test_anchor_appended_in_main_loop_on_exposable_post(): void {
		$post_id = $this->create_exposable_post();

		$output = $this->render_in_loop( $post_id );

		self::assertStringContainsString( 'class="' . Content_Link::CSS_CLASS . '"', $output );
		self::assertStringContainsString( '.md', $output );
		self::assertStringContainsString( 'Markdown version of this page:', $output );
	}

	public function test_anchor_absent_outside_the_loop(): void {
		$post_id = $this->create_exposable_post();
		$this->go_to( get_permalink( $post_id ) );

		// Same singular request, but the_content applied outside the loop —
		// widgets, page builders, and the plugin's own pipeline all look
		// like this.
		$post = get_post( $post_id );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$output = (string) apply_filters( 'the_content', $post->post_content );

		self::assertStringNotContainsString( Content_Link::CSS_CLASS, $output );
	}

	public function test_generated_markdown_never_contains_the_anchor(): void {
		// The recursion guard in practice: Service renders through
		// the_content; the anchor leaking in would embed the discovery link
		// inside the very .md body it points at.
		$post_id = $this->create_exposable_post();
		$this->go_to( get_permalink( $post_id ) );

		$post     = get_post( $post_id );
		$markdown = Service::get_markdown_for_post( $post );

		self::assertIsString( $markdown );
		self::assertStringNotContainsString( Content_Link::CSS_CLASS, $markdown );
		self::assertStringNotContainsString( 'Markdown version of this page:', $markdown );
	}

	public function test_anchor_absent_when_disabled(): void {
		$profile                         = get_option( Context_Profile_Settings::OPTION_KEY );
		$profile['content_link_enabled'] = false;
		update_option( Context_Profile_Settings::OPTION_KEY, $profile );

		$post_id = $this->create_exposable_post();
		$output  = $this->render_in_loop( $post_id );

		self::assertStringNotContainsString( Content_Link::CSS_CLASS, $output );
	}

	public function test_anchor_absent_on_non_exposable_post(): void {
		$post_id = $this->create_exposable_post();

		$profile                 = get_option( Context_Profile_Settings::OPTION_KEY );
		$profile['excluded_ids'] = array( $post_id );
		update_option( Context_Profile_Settings::OPTION_KEY, $profile );

		$output = $this->render_in_loop( $post_id );

		self::assertStringNotContainsString( Content_Link::CSS_CLASS, $output );
	}

	public function test_style_printed_on_singular_view(): void {
		$post_id = $this->create_exposable_post();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		Content_Link::print_style();
		$out = (string) ob_get_clean();

		self::assertStringContainsString( Content_Link::CSS_CLASS, $out );
		self::assertStringContainsString( 'position:absolute', $out );
	}

	public function test_style_absent_on_non_singular_view(): void {
		$this->create_exposable_post();
		$this->go_to( home_url( '/' ) );

		ob_start();
		Content_Link::print_style();
		$out = (string) ob_get_clean();

		self::assertSame( '', $out );
	}
}
