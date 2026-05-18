<?php
/**
 * Markdown Views cache table schema.
 *
 * Owns the lifecycle (create, drop, version tracking) of the
 * `wp_agentready_md_cache` table introduced for #5 per AgDR-0011.
 *
 * Multisite-aware: `create_for_all_sites()` / `drop_for_all_sites()` iterate
 * `get_sites()` so each site has its own per-site cache table when the
 * plugin is network-active.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * Schema lifecycle for the Markdown Views cache table.
 *
 * The schema is fixed by AgDR-0011 § "Schema (initial)":
 *   - PRIMARY KEY (post_id)              — one row per post, overwrite on regen
 *   - KEY content_hash                   — hash-validated cache reads
 *   - KEY walker_version                 — bulk invalidation when the walker bumps
 *
 * The walker version is stored per-row so a fix to the deterministic
 * converter (AgDR-0010) can invalidate the entire cache via a single
 * `DELETE … WHERE walker_version != $current` without dropping the table.
 */
final class Schema {

	/**
	 * Schema version of the cache table.
	 *
	 * Bump when adding columns or changing key shape. Additive column adds
	 * pass through `dbDelta()` cleanly; destructive changes require a
	 * `/migration` ticket per `.claude/rules/workflow-gates.md` Gate 3a.
	 *
	 * Bumped from 1 to 2 for AgDR-0017: adds `quality_score` and
	 * `signals` columns so the Markdown Views LLM cleanup gate has the
	 * walker's verdict + raw signal counts available without re-walking.
	 *
	 * @var int
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Option key that stores the installed schema version, used to detect
	 * upgrades on plugin load.
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION_OPTION = 'agentready_md_cache_schema_version';

	/**
	 * Suffix appended to `$wpdb->prefix` to form the full table name.
	 *
	 * Kept as a constant so callers do not duplicate the literal — every
	 * read/write path resolves through `table_name()`.
	 *
	 * @var string
	 */
	public const TABLE_SUFFIX = 'agentready_md_cache';

	/**
	 * Resolve the fully-prefixed table name for the current site.
	 *
	 * On multisite, this returns the site-specific table for whichever site
	 * is currently active (i.e. after `switch_to_blog()`).
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Wire the schema-related WordPress hooks. Called once from
	 * `Main::register_hooks()`.
	 *
	 * Currently registers a single `admin_init` listener for
	 * `maybe_upgrade()` — the missing-piece counterpart to the
	 * activation-hook create. Without this, users who upgrade
	 * agentready from one version to a later one (any schema bump)
	 * keep running against the old columns until they manually
	 * deactivate + reactivate. See ticket #52.
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_init', array( self::class, 'maybe_upgrade' ) );
	}

	/**
	 * Re-run `dbDelta()` when the installed schema version lags the
	 * current `SCHEMA_VERSION`. Skipped when up-to-date so the typical
	 * admin page-load pays just one cheap option read.
	 *
	 * Multisite behaviour: runs for the **current site only**. The
	 * activation-time `create_for_all_sites()` provisions every site at
	 * once; here, the lazy upgrade catches a site as soon as an admin
	 * loads any admin page on it. Iterating `get_sites()` on every
	 * admin_init across the network is unacceptably expensive.
	 */
	public static function maybe_upgrade(): void {
		if ( self::installed_version() >= self::SCHEMA_VERSION ) {
			return;
		}

		self::create();
	}

	/**
	 * Create (or upgrade) the cache table for the current site.
	 *
	 * Idempotent via `dbDelta()`: safe to call repeatedly. Writes the
	 * schema-version option after a successful create so subsequent loads
	 * can detect an upgrade is needed.
	 */
	public static function create(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires very specific SQL formatting — two spaces between
		// PRIMARY KEY and the column list, KEY (not INDEX), each column on its
		// own line. See https://developer.wordpress.org/reference/functions/dbdelta/.
		//
		// `quality_score` (TINYINT UNSIGNED NULL) holds the 0..100 verdict
		// produced by the walker's signal pass per AgDR-0017. NULL is the
		// transient state on rows written by SCHEMA_VERSION 1 — the walker
		// version bump invalidates those rows on next read so the NULL
		// resolves automatically.
		//
		// `signals` (LONGTEXT NULL) holds the JSON-encoded raw signal
		// counts that contributed to the score. Used by the admin UI to
		// explain *why* a post triggered cleanup without re-running the
		// walker. Unbounded shape — future signal additions don't require
		// further schema migrations.
		$sql = "CREATE TABLE {$table} (
			post_id BIGINT(20) UNSIGNED NOT NULL,
			content_hash CHAR(40) NOT NULL,
			markdown LONGTEXT NOT NULL,
			generated_at DATETIME NOT NULL,
			walker_version VARCHAR(20) NOT NULL,
			quality_score TINYINT UNSIGNED NULL,
			signals LONGTEXT NULL,
			PRIMARY KEY  (post_id),
			KEY content_hash (content_hash),
			KEY walker_version (walker_version)
		) {$charset_collate};";

		require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );

		\update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Drop the cache table for the current site.
	 *
	 * Also deletes the schema-version option so re-activation starts clean.
	 * Called from `uninstall.php` (full plugin removal) and from the WP-CLI
	 * `cache reset` command — never from `on_deactivate()`, since per
	 * AgDR-0015 deactivation must preserve cache state for cheap re-enable.
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::table_name();

		// Table names cannot be parameterised; the value is built from a
		// hardcoded suffix plus the trusted `$wpdb->prefix`. The schema-change
		// warning is the documented pattern for plugin uninstall (we are
		// removing our own table, not modifying core/third-party schema).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		\delete_option( self::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Run `create()` for every site on a multisite network (or once on a
	 * single-site install). Used from the activation hook so network-wide
	 * activation provisions per-site tables.
	 */
	public static function create_for_all_sites(): void {
		if ( ! \is_multisite() ) {
			self::create();
			return;
		}

		/** @var array<int,int> $site_ids */
		$site_ids = \get_sites( array( 'fields' => 'ids' ) );

		foreach ( $site_ids as $site_id ) {
			\switch_to_blog( (int) $site_id );
			self::create();
			\restore_current_blog();
		}
	}

	/**
	 * Run `drop()` for every site on a multisite network (or once on a
	 * single-site install). Used from `uninstall.php`.
	 */
	public static function drop_for_all_sites(): void {
		if ( ! \is_multisite() ) {
			self::drop();
			return;
		}

		/** @var array<int,int> $site_ids */
		$site_ids = \get_sites( array( 'fields' => 'ids' ) );

		foreach ( $site_ids as $site_id ) {
			\switch_to_blog( (int) $site_id );
			self::drop();
			\restore_current_blog();
		}
	}

	/**
	 * Resolve the installed schema version, or `0` if the table has never
	 * been created. Callers compare against `SCHEMA_VERSION` to decide
	 * whether to run an upgrade routine.
	 */
	public static function installed_version(): int {
		$value = \get_option( self::SCHEMA_VERSION_OPTION, 0 );
		return \is_numeric( $value ) ? (int) $value : 0;
	}
}
