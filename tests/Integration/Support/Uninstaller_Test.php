<?php
/**
 * Integration tests for the uninstall cleanup (#189).
 *
 * Pins the contract that an explicit plugin delete removes the FULL
 * persistent footprint: every option, post-meta, user-meta, and transient the
 * plugin writes, plus the Markdown Views cache table. The previous literal
 * lists in `uninstall.php` drifted from the real write sites — the central
 * Context Profile option survived delete while a dead `agentready_settings`
 * key was "cleaned" in its place — so these tests seed from the SAME class
 * constants the production code writes with, then sweep the database for any
 * `agentready` residue. A future feature that adds a key without extending
 * `Uninstaller`'s accessors fails the residue sweep here once its key is
 * seeded, and the accessor lists themselves are exercised end-to-end.
 *
 * DDL caveat (see Activation_Lifecycle_Test): `Uninstaller::run()` drops the
 * md_cache table, which auto-commits the per-test transaction. tearDown
 * recreates the table and clears every cron hook the seeding listeners may
 * have scheduled, so sibling suites observe a clean slate.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Support;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Context_Score\Service as Context_Score_Service;
use WPContext\LlmsTxt\Description_Orchestrator;
use WPContext\LlmsTxt\Service as Llms_Txt_Service;
use WPContext\Support\Uninstaller;
use WPContext\Markdown_Views\Schema;

final class Uninstaller_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// run() drops this table; make sure each test starts with it present
		// so the "table existed and was dropped" assertion is meaningful and
		// save_post listeners that delete from it stay quiet.
		Schema::create();

		// factory()->post->create() fires save_post → per-post description
		// cron entries that sibling suites count globally. Detach for the
		// duration of this suite (parent::tearDown() restores hooks).
		remove_action( 'save_post', array( Description_Orchestrator::class, 'on_save_post' ), 30 );
	}

	protected function tearDown(): void {
		// The DROP TABLE inside run() auto-committed the test transaction, so
		// deletions persist past rollback — restore the table and clear any
		// cron entries the profile-saved listener chain scheduled during
		// seeding, so downstream suites see the pre-test state.
		Schema::create();
		wp_clear_scheduled_hook( Llms_Txt_Service::REGEN_ACTION );
		wp_clear_scheduled_hook( Context_Score_Service::RECOMPUTE_ACTION );

		parent::tearDown();
	}

	/**
	 * Seed every key the accessors enumerate with real values.
	 *
	 * @return int The post ID carrying the seeded post-meta.
	 */
	private function seed_full_footprint(): int {
		update_option( Context_Profile_Settings::OPTION_KEY, Context_Profile_Settings::get_defaults() );
		foreach ( Uninstaller::option_keys() as $option_key ) {
			if ( false === get_option( $option_key ) ) {
				update_option( $option_key, 'wpctx-seed' );
			}
		}

		$post_id = self::factory()->post->create( array( 'post_title' => 'Uninstall fixture' ) );
		foreach ( Uninstaller::post_meta_keys() as $meta_key ) {
			update_post_meta( $post_id, $meta_key, 'wpctx-seed' );
		}

		$user_id = self::factory()->user->create();
		foreach ( Uninstaller::user_meta_keys() as $meta_key ) {
			update_user_meta( $user_id, $meta_key, 'wpctx-seed' );
		}

		foreach ( Uninstaller::transient_keys() as $transient_key ) {
			set_transient( $transient_key, 'wpctx-seed', 300 );
		}

		return $post_id;
	}

	public function test_option_keys_cover_the_real_write_sites(): void {
		$keys = Uninstaller::option_keys();

		// The two regressions #189 was filed about: the central profile
		// option must be on the list, the dead legacy key must not be.
		$this->assertContains( Context_Profile_Settings::OPTION_KEY, $keys );
		$this->assertContains( 'agentready_seo_posture_last_seen', $keys );
		$this->assertNotContains( 'agentready_settings', $keys );
	}

	public function test_run_removes_every_listed_key(): void {
		$post_id = $this->seed_full_footprint();

		Uninstaller::run();

		foreach ( Uninstaller::option_keys() as $option_key ) {
			$this->assertFalse( get_option( $option_key ), "Option '{$option_key}' survived uninstall." );
		}
		foreach ( Uninstaller::post_meta_keys() as $meta_key ) {
			$this->assertSame( '', get_post_meta( $post_id, $meta_key, true ), "Post meta '{$meta_key}' survived uninstall." );
		}
		foreach ( Uninstaller::transient_keys() as $transient_key ) {
			$this->assertFalse( get_transient( $transient_key ), "Transient '{$transient_key}' survived uninstall." );
		}
	}

	public function test_run_leaves_zero_agentready_residue_in_the_database(): void {
		global $wpdb;

		$this->seed_full_footprint();

		Uninstaller::run();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- residue sweep needs raw reads.
		$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%agentready%'" );
		// Cron entries are out of scope: WP forces deactivate-before-delete
		// and on_deactivate() owns clearing scheduled hooks.
		$options = array_values( array_diff( $options, array( 'cron' ) ) );

		$post_meta = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '%agentready%'" );
		$user_meta = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '%agentready%'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$this->assertSame( array(), $options, 'wp_options rows survived uninstall.' );
		$this->assertSame( array(), $post_meta, 'wp_postmeta rows survived uninstall.' );
		$this->assertSame( array(), $user_meta, 'wp_usermeta rows survived uninstall.' );
	}

	public function test_run_drops_the_md_cache_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'agentready_md_cache';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- table existence probe.
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		Uninstaller::run();

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function test_uninstall_entrypoint_delegates_to_the_uninstaller(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'agentable/agentable.php' );
		}

		update_option( Context_Profile_Settings::OPTION_KEY, Context_Profile_Settings::get_defaults() );

		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertFalse(
			get_option( Context_Profile_Settings::OPTION_KEY ),
			'uninstall.php did not remove the Context Profile option.'
		);
	}
}
