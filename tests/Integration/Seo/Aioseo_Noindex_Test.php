<?php
/**
 * Integration tests for the AIOSEO noindex path (#187).
 *
 * Creates a real `{$wpdb->prefix}aioseo_posts` table, drives the AIOSEO
 * posture via the `active_plugins` option (NOT by defining the
 * AIOSEO\Plugin\AIOSEO class — class_exists is checked first in
 * Schema_Coordination_Detector and a defined class would leak the posture
 * into every later test in the process), and asserts both the raw filter
 * verdict and the wired `get_exposure_reason()` outcome.
 *
 * DDL caveat: CREATE/DROP TABLE auto-commit past the per-test transaction
 * wrapper, so the table is created in setUp and explicitly dropped in
 * tearDown rather than relying on rollback.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Seo;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Settings;
use WPContext\Admin\SEO_Noindex_Detector;
use WPContext\Markdown_Views\Schema as Markdown_Views_Schema;

final class Aioseo_Noindex_Test extends WP_UnitTestCase {

	private const AIOSEO_PLUGIN_FILE = 'all-in-one-seo-pack/all_in_one_seo_pack.php';

	public function set_up(): void {
		parent::set_up();

		// factory()->post->create fires save_post → Service::invalidate →
		// DELETE against the md_cache table; absent table = wpdb error output
		// = risky test. DDL auto-commits past the rollback wrapper, so
		// create/drop explicitly.
		Markdown_Views_Schema::create();

		global $wpdb;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aioseo_posts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				robots_default TINYINT(1) NOT NULL DEFAULT 1,
				robots_noindex TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY post_id (post_id)
			)"
		);

		$active   = \get_option( 'active_plugins', array() );
		$active[] = self::AIOSEO_PLUGIN_FILE;
		\update_option( 'active_plugins', $active );

		// Known profile shape — the fresh test install exposes nothing, so
		// without this every post fails the `cpt` gate before noindex runs.
		\update_option(
			Context_Profile_Settings::OPTION_KEY,
			\array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'     => array( 'post' ),
					'exposed_statuses' => array( 'publish' ),
				)
			)
		);

		SEO_Noindex_Detector::reset_runtime_cache();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aioseo_posts" );
		SEO_Noindex_Detector::reset_runtime_cache();
		Markdown_Views_Schema::drop();

		parent::tear_down();
	}

	private function insert_aioseo_row( int $post_id, int $robots_default, int $robots_noindex ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'aioseo_posts',
			array(
				'post_id'        => $post_id,
				'robots_default' => $robots_default,
				'robots_noindex' => $robots_noindex,
			),
			array( '%d', '%d', '%d' )
		);
	}

	public function test_explicit_noindex_row_denies_exposure_with_noindex_reason(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->insert_aioseo_row( $post->ID, 0, 1 );

		self::assertTrue( \apply_filters( 'agentready_post_is_noindexed', false, $post ) );
		self::assertSame( 'noindex', Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_robots_default_row_stays_exposable(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->insert_aioseo_row( $post->ID, 1, 1 );

		self::assertFalse( \apply_filters( 'agentready_post_is_noindexed', false, $post ) );
		self::assertNull( Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_post_without_aioseo_row_stays_exposable(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		self::assertFalse( \apply_filters( 'agentready_post_is_noindexed', false, $post ) );
		self::assertNull( Context_Profile_Settings::get_exposure_reason( $post ) );
	}

	public function test_missing_table_degrades_to_exposable(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aioseo_posts" );
		SEO_Noindex_Detector::reset_runtime_cache();

		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		self::assertFalse( \apply_filters( 'agentready_post_is_noindexed', false, $post ) );
		self::assertNull( Context_Profile_Settings::get_exposure_reason( $post ) );
	}
}
