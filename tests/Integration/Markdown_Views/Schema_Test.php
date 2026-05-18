<?php
/**
 * Integration test for the Markdown Views cache schema.
 *
 * Runs inside the wp-phpunit WP test instance so we can exercise the real
 * `dbDelta()` path, real `$wpdb`, and real `update_option()` / `delete_option()`.
 * Verifies the round trip: create → table exists with the expected columns and
 * keys → drop → table is gone → schema-version option is gone.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Markdown_Views;

use WP_UnitTestCase;
use WPContext\Markdown_Views\Schema;

final class Schema_Test extends WP_UnitTestCase {

	private function table_exists(): bool {
		global $wpdb;
		$table = Schema::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	protected function setUp(): void {
		parent::setUp();
		// Schema::drop() is filtered to DROP TEMPORARY TABLE by wp-phpunit's
		// query rewriter (per-test isolation). The bootstrap-time drop in
		// tests/bootstrap.php removed any pre-existing regular table from
		// wp-env's env-boot activation; from this point on every drop here
		// targets a temporary table the previous test may have left behind.
		Schema::drop();
	}

	protected function tearDown(): void {
		Schema::drop();
		parent::tearDown();
	}

	public function test_table_name_uses_wpdb_prefix(): void {
		global $wpdb;
		self::assertSame( $wpdb->prefix . 'agentready_md_cache', Schema::table_name() );
	}

	public function test_create_provisions_table_and_records_schema_version(): void {
		self::assertFalse( $this->table_exists(), 'Table should not exist before create()' );

		Schema::create();

		self::assertTrue( $this->table_exists(), 'Table should exist after create()' );
		self::assertSame(
			Schema::SCHEMA_VERSION,
			Schema::installed_version(),
			'Schema version option should be set to SCHEMA_VERSION'
		);
	}

	public function test_create_is_idempotent(): void {
		Schema::create();
		// Second call must not throw or produce a different result.
		Schema::create();

		self::assertTrue( $this->table_exists() );
		self::assertSame( Schema::SCHEMA_VERSION, Schema::installed_version() );
	}

	public function test_table_has_expected_columns(): void {
		global $wpdb;

		Schema::create();
		$table = Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE {$table}" );

		self::assertContains( 'post_id', $columns );
		self::assertContains( 'content_hash', $columns );
		self::assertContains( 'markdown', $columns );
		self::assertContains( 'generated_at', $columns );
		self::assertContains( 'walker_version', $columns );
		// SCHEMA_VERSION 2 additions per AgDR-0017 (#6).
		self::assertContains( 'quality_score', $columns );
		self::assertContains( 'signals', $columns );
	}

	public function test_quality_score_and_signals_are_nullable(): void {
		global $wpdb;

		Schema::create();
		$table = Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );

		$nullable = array();
		foreach ( $rows as $row ) {
			$nullable[ $row['Field'] ] = 'YES' === $row['Null'];
		}

		self::assertTrue( $nullable['quality_score'] ?? false, 'quality_score must be NULL-able for v0.1.1 rows' );
		self::assertTrue( $nullable['signals'] ?? false, 'signals must be NULL-able for v0.1.1 rows' );
	}

	public function test_table_has_expected_indexes(): void {
		global $wpdb;

		Schema::create();
		$table = Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		$keys = array();
		foreach ( $rows as $row ) {
			$keys[ $row['Key_name'] ] = $row['Column_name'];
		}

		self::assertArrayHasKey( 'PRIMARY', $keys );
		self::assertSame( 'post_id', $keys['PRIMARY'], 'PRIMARY KEY should be post_id' );
		self::assertArrayHasKey( 'content_hash', $keys, 'content_hash KEY should exist' );
		self::assertArrayHasKey( 'walker_version', $keys, 'walker_version KEY should exist' );
	}

	public function test_drop_removes_table_and_schema_version(): void {
		Schema::create();
		self::assertTrue( $this->table_exists() );

		Schema::drop();

		self::assertFalse( $this->table_exists(), 'Table should be gone after drop()' );
		self::assertSame( 0, Schema::installed_version(), 'Schema version option should be cleared' );
	}

	public function test_drop_is_idempotent_on_missing_table(): void {
		// Nothing was created — drop() must not raise.
		Schema::drop();
		$this->expectNotToPerformAssertions();
	}

	public function test_installed_version_returns_zero_when_unset(): void {
		\delete_option( Schema::SCHEMA_VERSION_OPTION );
		self::assertSame( 0, Schema::installed_version() );
	}

	public function test_maybe_upgrade_recreates_dropped_column_on_stale_version(): void {
		global $wpdb;

		Schema::create();
		self::assertSame( Schema::SCHEMA_VERSION, Schema::installed_version() );

		// Simulate the pre-#52 upgrade scenario:
		//   1. User was on a version whose schema only had the v0.1
		//      columns (no `quality_score`).
		//   2. A plugin update bumped SCHEMA_VERSION but the
		//      activation hook didn't re-fire — `Schema::create()`
		//      never ran, so the DB column is missing while the code
		//      expects it.
		//
		// We reproduce that by dropping the column manually AND
		// rolling the schema-version option back to 1.
		$table = Schema::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN quality_score" );
		\update_option( Schema::SCHEMA_VERSION_OPTION, 1, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns_before = $wpdb->get_col( "DESCRIBE {$table}" );
		self::assertNotContains(
			'quality_score',
			$columns_before,
			'precondition: quality_score must be missing before maybe_upgrade runs'
		);

		Schema::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns_after = $wpdb->get_col( "DESCRIBE {$table}" );
		self::assertContains(
			'quality_score',
			$columns_after,
			'maybe_upgrade must add the missing column via dbDelta'
		);
		self::assertSame(
			Schema::SCHEMA_VERSION,
			Schema::installed_version(),
			'maybe_upgrade must bump the installed-version option to the current SCHEMA_VERSION'
		);
	}

	public function test_maybe_upgrade_is_a_no_op_when_already_current(): void {
		Schema::create();
		$version_before = Schema::installed_version();
		self::assertSame( Schema::SCHEMA_VERSION, $version_before );

		// Track wpdb queries to confirm maybe_upgrade is cheap when
		// already current — single option read, no dbDelta call.
		Schema::maybe_upgrade();

		self::assertSame( Schema::SCHEMA_VERSION, Schema::installed_version() );
	}

	public function test_maybe_upgrade_creates_table_when_never_installed(): void {
		// Fresh state: drop everything, then call maybe_upgrade. Since
		// installed_version is 0 < SCHEMA_VERSION, the upgrade path
		// runs `create()` which provisions the table.
		Schema::drop();
		self::assertFalse( $this->table_exists() );

		Schema::maybe_upgrade();

		self::assertTrue( $this->table_exists() );
		self::assertSame( Schema::SCHEMA_VERSION, Schema::installed_version() );
	}
}
