<?php
/**
 * Integration tests for the static `.md` mirror lifecycle (#283 / AgDR-0067).
 *
 * Drives the real save / trash hooks against a booted WP with the mirror
 * forced active via the profile's `static_md_mode = on` override: file
 * written on save, moved on slug change, deleted on trash and on exposure
 * revoke, and the whole tree purged when the mirror goes inactive.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Schema;
use Mokhai\Markdown_Views\Static_Mirror;

final class Static_Mirror_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Pretty permalinks: the path mapper mirrors permalink paths; under
		// the default plain permalinks every post falls back to post-{ID}.md
		// and the slug-change move has nothing to observe.
		$this->set_permalink_structure( '/%postname%/' );

		Schema::create();

		$this->set_profile(
			array(
				'exposed_cpts'           => array( 'post', 'page' ),
				'exposed_statuses'       => array( 'publish' ),
				'markdown_views_enabled' => true,
				'static_md_mode'         => 'on',
			)
		);
	}

	protected function tearDown(): void {
		Static_Mirror::purge_all();
		Schema::drop();
		parent::tearDown();
	}

	/** @param array<string,mixed> $overrides */
	private function set_profile( array $overrides ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge( Context_Profile_Settings::get_defaults(), $overrides )
		);
	}

	private function create_published_post( array $overrides = array() ): int {
		return (int) $this->factory->post->create(
			array_merge(
				array(
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_title'   => 'Mirror Target',
					'post_content' => '<p>Body text for the mirror.</p>',
				),
				$overrides
			)
		);
	}

	private function mirror_file_for( int $post_id ): string {
		$relative = (string) get_post_meta( $post_id, Static_Mirror::META_KEY_PATH, true );

		return '' === $relative ? '' : Static_Mirror::base_dir() . '/' . $relative;
	}

	public function test_refresh_writes_markdown_file_and_meta(): void {
		$post_id = $this->create_published_post();

		Static_Mirror::refresh_for_post_id( $post_id );

		$file = $this->mirror_file_for( $post_id );
		self::assertNotSame( '', $file );
		self::assertFileExists( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		self::assertStringContainsString( 'Body text for the mirror.', (string) file_get_contents( $file ) );
	}

	public function test_refresh_writes_htaccess_with_delivery_headers(): void {
		$post_id = $this->create_published_post();

		Static_Mirror::refresh_for_post_id( $post_id );

		$htaccess = Static_Mirror::base_dir() . '/.htaccess';
		self::assertFileExists( $htaccess );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$rules = (string) file_get_contents( $htaccess );
		self::assertStringContainsString( 'ForceType text/plain', $rules, '#293: ChatGPT rejects text/markdown' );
		self::assertStringContainsString( 'Content-Disposition "inline"', $rules );
	}

	public function test_slug_change_moves_the_file(): void {
		$post_id = $this->create_published_post( array( 'post_name' => 'old-slug' ) );

		Static_Mirror::refresh_for_post_id( $post_id );
		$old_file = $this->mirror_file_for( $post_id );
		self::assertFileExists( $old_file );

		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => 'new-slug',
			)
		);
		Static_Mirror::refresh_for_post_id( $post_id );

		$new_file = $this->mirror_file_for( $post_id );
		self::assertFileExists( $new_file );
		self::assertStringContainsString( 'new-slug', $new_file );
		self::assertFileDoesNotExist( $old_file );
	}

	public function test_trash_deletes_file_and_meta(): void {
		$post_id = $this->create_published_post();

		Static_Mirror::refresh_for_post_id( $post_id );
		$file = $this->mirror_file_for( $post_id );
		self::assertFileExists( $file );

		Static_Mirror::delete_for_post_id( $post_id );

		self::assertFileDoesNotExist( $file );
		self::assertSame( '', (string) get_post_meta( $post_id, Static_Mirror::META_KEY_PATH, true ) );
	}

	public function test_exposure_revoke_deletes_existing_file_on_refresh(): void {
		$post_id = $this->create_published_post();

		Static_Mirror::refresh_for_post_id( $post_id );
		$file = $this->mirror_file_for( $post_id );
		self::assertFileExists( $file );

		// Revoke exposure via the per-post exclude deny-list.
		$profile                 = get_option( Context_Profile_Settings::OPTION_KEY );
		$profile['excluded_ids'] = array( $post_id );
		update_option( Context_Profile_Settings::OPTION_KEY, $profile );

		Static_Mirror::refresh_for_post_id( $post_id );

		self::assertFileDoesNotExist( $file );
	}

	public function test_mirror_off_mode_writes_nothing(): void {
		$this->set_profile(
			array(
				'exposed_cpts'           => array( 'post' ),
				'exposed_statuses'       => array( 'publish' ),
				'markdown_views_enabled' => true,
				'static_md_mode'         => 'off',
			)
		);

		$post_id = $this->create_published_post();
		Static_Mirror::refresh_for_post_id( $post_id );

		self::assertSame( '', (string) get_post_meta( $post_id, Static_Mirror::META_KEY_PATH, true ) );
	}

	public function test_daily_sync_purges_tree_when_mirror_inactive(): void {
		$post_id = $this->create_published_post();
		Static_Mirror::refresh_for_post_id( $post_id );
		self::assertDirectoryExists( Static_Mirror::base_dir() );

		$profile                   = get_option( Context_Profile_Settings::OPTION_KEY );
		$profile['static_md_mode'] = 'off';
		update_option( Context_Profile_Settings::OPTION_KEY, $profile );

		Static_Mirror::run_daily_sync();

		self::assertDirectoryDoesNotExist( Static_Mirror::base_dir() );
	}

	public function test_daily_sync_backfills_missing_files(): void {
		$post_id = $this->create_published_post();

		// Erase the hook-driven write (factory create fires save_post) to
		// simulate content that pre-dates the cache appearing — the
		// backstop must recreate the file from nothing.
		Static_Mirror::delete_for_post_id( $post_id );
		self::assertSame( '', (string) get_post_meta( $post_id, Static_Mirror::META_KEY_PATH, true ) );

		Static_Mirror::run_daily_sync();

		$file = $this->mirror_file_for( $post_id );
		self::assertNotSame( '', $file );
		self::assertFileExists( $file );
	}

	public function test_save_post_hook_writes_file_end_to_end(): void {
		// Through the real hook chain: factory create fires save_post /
		// wp_after_insert_post, which Static_Mirror::register_hooks() wired
		// in Main. The file must exist without any direct refresh call.
		$post_id = $this->create_published_post();

		$file = $this->mirror_file_for( $post_id );
		self::assertNotSame( '', $file );
		self::assertFileExists( $file );
	}

	public function test_file_url_null_when_mirror_inactive(): void {
		$post_id = $this->create_published_post();
		Static_Mirror::refresh_for_post_id( $post_id );

		$profile                   = get_option( Context_Profile_Settings::OPTION_KEY );
		$profile['static_md_mode'] = 'off';
		update_option( Context_Profile_Settings::OPTION_KEY, $profile );

		$post = get_post( $post_id );
		self::assertNotNull( $post );
		self::assertNull( Static_Mirror::file_url_for_post( $post ) );
	}
}
