<?php
/**
 * Integration tests for the `ai-readiness-kit/md-view-preview` ability
 * (#21 / AgDR-0044).
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Abilities;

use WP_UnitTestCase;
use Mokhai\Abilities\Md_View_Ability;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class Md_View_Ability_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}

		Markdown_Views_Schema::create();

		// Expose `post` so the happy path is exposable.
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\wp_get_abilities();
	}

	protected function tearDown(): void {
		\delete_option( Context_Profile_Settings::OPTION_KEY );
		\wp_set_current_user( 0 );
		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	private function preview( array $input ) {
		return \wp_get_ability( Md_View_Ability::ID )->execute( $input );
	}

	private function make_post(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Preview Subject',
				'post_content' => '<p>Hello <strong>agents</strong>.</p>',
			)
		);
	}

	public function test_exposable_post_returns_deterministic_markdown(): void {
		$result = $this->preview( array( 'post_id' => $this->make_post() ) );

		self::assertIsArray( $result );
		self::assertTrue( $result['exposable'] );
		self::assertNull( $result['reason'] );
		self::assertNotSame( '', $result['deterministic_markdown'] );
	}

	public function test_resolves_by_url(): void {
		$post_id = $this->make_post();

		$result = $this->preview( array( 'url' => \get_permalink( $post_id ) ) );

		self::assertIsArray( $result );
		self::assertSame( $post_id, $result['post_id'] );
		self::assertTrue( $result['exposable'] );
	}

	public function test_non_exposable_post_returns_reason(): void {
		// `page` is not in exposed_cpts.
		$page = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = $this->preview( array( 'post_id' => $page ) );

		self::assertFalse( $result['exposable'] );
		self::assertSame( 'cpt', $result['reason'] );
		self::assertSame( '', $result['deterministic_markdown'] );
	}

	public function test_unknown_url_is_not_found(): void {
		$result = $this->preview( array( 'url' => 'https://example.com/nope-does-not-exist/' ) );

		self::assertWPError( $result );
		self::assertSame( 'ai_readiness_kit_post_not_found', $result->get_error_code() );
	}

	public function test_module_disabled_returns_error(): void {
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_profile(),
				array( 'markdown_views_enabled' => false )
			)
		);

		$result = $this->preview( array( 'post_id' => $this->make_post() ) );

		self::assertWPError( $result );
		self::assertSame( 'ai_readiness_kit_module_disabled', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->preview( array( 'post_id' => $this->make_post() ) );

		self::assertWPError( $result );
		self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_requires_url_or_post_id(): void {
		$result = $this->preview( array() );

		self::assertWPError( $result );
		self::assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
