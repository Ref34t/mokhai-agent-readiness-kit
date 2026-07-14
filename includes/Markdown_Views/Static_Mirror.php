<?php
/**
 * Publish-time static `.md` file mirror.
 *
 * Writes each exposable post's Markdown View to a real file under
 * `wp-content/uploads/mokhai/md/` so agents can fetch markdown even when a
 * hard full-page cache (plugin or host-level) serves HTML without invoking
 * PHP — uploads are served statically by every host with zero server
 * configuration, Apache and nginx alike (#283 / AgDR-0067).
 *
 * Gated on `Cache_Posture::mirror_active()`: files are only written when a
 * hard cache is detected (profile mode `auto`, the default) or the operator
 * forces the mirror on. The canonical `.md` URL stays the request-time
 * `/path.md` route — the uploads file is a delivery fallback for the
 * worst case, advertised by `Discovery\Content_Link`, not a second canonical.
 *
 * Lifecycle:
 *   - save / insert  → refresh (write, or delete when no longer exposable)
 *   - trash / delete → delete the file
 *   - slug change    → the stored relative path in post-meta detects the
 *                      move; the old file is deleted before the new write
 *   - daily cron     → batch backstop for posts missed by hooks (bulk
 *                      imports, mode flipped on after content existed) and
 *                      full purge when the mirror has gone inactive
 *   - deactivation / uninstall → purge the mirror directory
 *
 * First filesystem-write code in the plugin: every write degrades gracefully
 * — an unwritable uploads dir simply leaves the request-time route as the
 * only delivery path (the pre-#283 behaviour).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

use Mokhai\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * File lifecycle for the static `.md` mirror.
 */
final class Static_Mirror {

	/**
	 * Post-meta key storing the relative path of the post's mirror file.
	 * The reliable record for deletes and slug-change moves — a trashed
	 * post's permalink already carries the `__trashed` suffix by the time
	 * our hook runs, so re-deriving the path from the permalink would miss.
	 *
	 * @var string
	 */
	public const META_KEY_PATH = '_mokhai_md_static_path';

	/**
	 * Daily cron hook for the batch backstop.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'mokhai_static_mirror_daily_sync';

	/**
	 * Per-cron-tick cap on posts examined by the backstop. Mirrors the
	 * bounded-batch convention of the descriptions pipeline
	 * (`Context_Profile_Settings::DESCRIPTIONS_MAX_PER_RUN`).
	 *
	 * @var int
	 */
	public const SYNC_BATCH_CAP = 50;

	/**
	 * Subdirectory of the uploads basedir holding the mirror tree.
	 *
	 * @var string
	 */
	private const SUBDIR = 'mokhai/md';

	/**
	 * Wire the lifecycle hooks. Called once from `Main::register_hooks()`.
	 *
	 * Priority 20 on the save-shaped hooks — AFTER `Service::invalidate()`
	 * at 10, so the refresh regenerates from fresh content instead of
	 * writing a stale cache row to disk.
	 */
	public static function register_hooks(): void {
		\add_action( 'save_post', array( self::class, 'refresh_for_post_id' ), 20, 1 );
		\add_action( 'wp_after_insert_post', array( self::class, 'refresh_for_post_id' ), 20, 1 );
		\add_action( 'wp_trash_post', array( self::class, 'delete_for_post_id' ), 20, 1 );
		\add_action( 'before_delete_post', array( self::class, 'delete_for_post_id' ), 20, 1 );
		\add_action( self::CRON_HOOK, array( self::class, 'run_daily_sync' ) );
	}

	/**
	 * Schedule the daily backstop. Idempotent — safe on re-activation.
	 */
	public static function schedule_daily_sync(): void {
		if ( ! \wp_next_scheduled( self::CRON_HOOK ) ) {
			\wp_schedule_event( \time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the daily backstop (deactivation).
	 */
	public static function clear_scheduled_sync(): void {
		\wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Path mapping
	 * ------------------------------------------------------------------- */

	/**
	 * Absolute path of the mirror root (no trailing slash).
	 */
	public static function base_dir(): string {
		$uploads = \wp_upload_dir( null, false );

		return \rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::SUBDIR;
	}

	/**
	 * Public URL of the mirror root (no trailing slash).
	 */
	public static function base_url(): string {
		$uploads = \wp_upload_dir( null, false );

		return \rtrim( (string) $uploads['baseurl'], '/' ) . '/' . self::SUBDIR;
	}

	/**
	 * Map a permalink to the mirror-relative file path. Pure — unit-tested
	 * directly.
	 *
	 * `/about/team/` → `about/team.md`; the front page / root → `index.md`;
	 * plain-permalink URLs (query-string form, no path identity) →
	 * `post-{ID}.md`. Every segment passes `sanitize_file_name()`, which
	 * strips traversal dots and filesystem-hostile characters.
	 *
	 * @param string $permalink Canonical permalink of the post.
	 * @param string $home_path Path component of home_url('/') — '/' on root
	 *                          installs, '/blog/' on subdirectory installs.
	 * @param int    $post_id   Post ID, used for the query-permalink fallback.
	 *
	 * @return string Relative path, always ending in `.md`.
	 */
	public static function relative_path_for_permalink( string $permalink, string $home_path, int $post_id ): string {
		$parsed = \wp_parse_url( $permalink );

		if ( ! empty( $parsed['query'] ) ) {
			return 'post-' . $post_id . '.md';
		}

		$path      = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		$home_path = '/' . \trim( $home_path, '/' );

		if ( '/' !== $home_path && 0 === \strpos( $path, $home_path ) ) {
			$path = (string) \substr( $path, \strlen( $home_path ) );
		}

		$path = \trim( $path, '/' );

		if ( '' === $path ) {
			return 'index.md';
		}

		$segments = array();
		foreach ( \explode( '/', $path ) as $segment ) {
			$clean = \sanitize_file_name( $segment );
			if ( '' !== $clean ) {
				$segments[] = $clean;
			}
		}

		if ( array() === $segments ) {
			return 'post-' . $post_id . '.md';
		}

		return \implode( '/', $segments ) . '.md';
	}

	/**
	 * Mirror-relative path for a post, derived from its current permalink.
	 *
	 * @param \WP_Post $post Post to map.
	 *
	 * @return string
	 */
	public static function relative_path_for( \WP_Post $post ): string {
		$permalink = (string) \get_permalink( $post );
		$home_path = (string) ( \wp_parse_url( \home_url( '/' ), \PHP_URL_PATH ) ?? '/' );

		return self::relative_path_for_permalink( $permalink, $home_path, (int) $post->ID );
	}

	/**
	 * Public URL of a post's mirror file, or null when the mirror isn't the
	 * right delivery surface for it right now (mirror inactive, or no file
	 * on disk). `Discovery\Content_Link` uses this to decide the hidden
	 * link's target.
	 *
	 * @param \WP_Post $post Post to resolve.
	 *
	 * @return string|null
	 */
	public static function file_url_for_post( \WP_Post $post ): ?string {
		if ( ! Cache_Posture::mirror_active() ) {
			return null;
		}

		$relative = (string) \get_post_meta( (int) $post->ID, self::META_KEY_PATH, true );
		if ( '' === $relative ) {
			return null;
		}

		if ( ! \file_exists( self::base_dir() . '/' . $relative ) ) {
			return null;
		}

		return self::base_url() . '/' . $relative;
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle callbacks
	 * ------------------------------------------------------------------- */

	/**
	 * Save-shaped hook callback: (re)write the post's mirror file, moving it
	 * on slug change and deleting it when the post is no longer exposable.
	 *
	 * @param int $post_id Post identifier.
	 */
	public static function refresh_for_post_id( int $post_id ): void {
		if ( $post_id <= 0 || \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! Cache_Posture::mirror_active() ) {
			return;
		}

		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$markdown = Service::get_markdown_for_post( $post );

		if ( \is_wp_error( $markdown ) ) {
			// Module off / not exposable (revoked exposure included) — the
			// mirror must not keep serving what the route would deny.
			self::delete_for_post_id( $post_id );
			return;
		}

		$relative = self::relative_path_for( $post );
		$previous = (string) \get_post_meta( $post_id, self::META_KEY_PATH, true );

		if ( '' !== $previous && $previous !== $relative ) {
			self::delete_file( $previous );
		}

		if ( self::write_file( $relative, $markdown ) ) {
			\update_post_meta( $post_id, self::META_KEY_PATH, $relative );
		}
	}

	/**
	 * Trash / delete hook callback: remove the post's mirror file + meta.
	 *
	 * Runs regardless of `mirror_active()` — a file written while the mirror
	 * was on must not survive its post just because the cache went away.
	 *
	 * @param int $post_id Post identifier.
	 */
	public static function delete_for_post_id( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$relative = (string) \get_post_meta( $post_id, self::META_KEY_PATH, true );
		if ( '' === $relative ) {
			return;
		}

		self::delete_file( $relative );
		\delete_post_meta( $post_id, self::META_KEY_PATH );
	}

	/**
	 * Daily cron backstop.
	 *
	 * Mirror active   → write missing / stale files for up to
	 *                   `SYNC_BATCH_CAP` exposed posts (covers bulk imports,
	 *                   posts that existed before the cache appeared, and
	 *                   hook-suppressed programmatic writes).
	 * Mirror inactive → purge the whole tree once (mode flipped off, cache
	 *                   plugin removed) so stale markdown never outlives the
	 *                   reason the files existed.
	 */
	public static function run_daily_sync(): void {
		if ( ! Cache_Posture::mirror_active() ) {
			if ( \is_dir( self::base_dir() ) ) {
				self::purge_all();
			}
			return;
		}

		$profile  = Context_Profile_Settings::get_profile();
		$cpts     = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] ) ? $profile['exposed_cpts'] : array();
		$statuses = isset( $profile['exposed_statuses'] ) && \is_array( $profile['exposed_statuses'] ) ? $profile['exposed_statuses'] : array( 'publish' );

		if ( array() === $cpts || array() === $statuses ) {
			return;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $cpts,
				'post_status'            => $statuses,
				'posts_per_page'         => self::SYNC_BATCH_CAP,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( self::is_file_fresh( $post ) ) {
				continue;
			}

			self::refresh_for_post_id( (int) $post->ID );
		}

		\wp_reset_postdata();
	}

	/**
	 * Delete the entire mirror tree (deactivation / uninstall / cron purge).
	 * The files are regenerable artifacts — removal is lossless.
	 */
	public static function purge_all(): void {
		$base = self::base_dir();

		if ( ! \is_dir( $base ) ) {
			return;
		}

		if ( ! self::init_filesystem() ) {
			return;
		}

		global $wp_filesystem;
		$wp_filesystem->delete( $base, true );
	}

	/* ---------------------------------------------------------------------
	 * File operations
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a post's mirror file exists and is at least as new as the post.
	 *
	 * @param \WP_Post $post Post to check.
	 */
	private static function is_file_fresh( \WP_Post $post ): bool {
		$relative = (string) \get_post_meta( (int) $post->ID, self::META_KEY_PATH, true );
		if ( '' === $relative || self::relative_path_for( $post ) !== $relative ) {
			return false;
		}

		$file = self::base_dir() . '/' . $relative;
		if ( ! \file_exists( $file ) ) {
			return false;
		}

		$mtime    = (int) \filemtime( $file );
		$modified = (int) \strtotime( $post->post_modified_gmt . ' UTC' );

		return $mtime >= $modified;
	}

	/**
	 * Write one mirror file. Creates intermediate directories, refuses any
	 * resolved path outside the mirror root (defence-in-depth on top of the
	 * per-segment `sanitize_file_name()`), and degrades gracefully when the
	 * filesystem isn't writable.
	 *
	 * @param string $relative Mirror-relative path (from `relative_path_for*`).
	 * @param string $markdown File body.
	 *
	 * @return bool Whether the write happened.
	 */
	private static function write_file( string $relative, string $markdown ): bool {
		$base   = self::base_dir();
		$target = $base . '/' . $relative;

		// Containment check: the normalised target must stay inside the
		// mirror root. sanitize_file_name() already strips traversal dots;
		// this guards against future path-derivation regressions.
		if ( 0 !== \strpos( \wp_normalize_path( $target ), \wp_normalize_path( $base ) . '/' ) ) {
			return false;
		}

		if ( ! \wp_mkdir_p( \dirname( $target ) ) ) {
			return false;
		}

		if ( ! self::init_filesystem() ) {
			return false;
		}

		self::ensure_htaccess();

		global $wp_filesystem;

		return (bool) $wp_filesystem->put_contents( $target, $markdown, \FS_CHMOD_FILE );
	}

	/**
	 * Write the mirror root's `.htaccess` so Apache serves the static `.md`
	 * files with the same delivery headers as the request-time route:
	 * `text/plain` (ChatGPT's fetcher 400s on `text/markdown` — #293, spike
	 * #291) and `Content-Disposition: inline`. nginx ignores `.htaccess`;
	 * without an `md` entry in its mime map it falls back to the configured
	 * default type, so nginx guidance lives in the plugin docs instead.
	 *
	 * Best-effort and idempotent: existing file with current content is left
	 * untouched; an unwritable dir degrades to the host's default headers.
	 */
	private static function ensure_htaccess(): void {
		$rules = 'ForceType text/plain' . "\n"
			. 'AddDefaultCharset utf-8' . "\n"
			. '<IfModule mod_headers.c>' . "\n"
			. "\t" . 'Header set Content-Disposition "inline"' . "\n"
			. '</IfModule>' . "\n";

		$target = self::base_dir() . '/.htaccess';

		global $wp_filesystem;

		if ( $wp_filesystem->exists( $target ) && $wp_filesystem->get_contents( $target ) === $rules ) {
			return;
		}

		$wp_filesystem->put_contents( $target, $rules, \FS_CHMOD_FILE );
	}

	/**
	 * Delete one mirror file (best-effort; missing file is a no-op).
	 *
	 * @param string $relative Mirror-relative path.
	 */
	private static function delete_file( string $relative ): void {
		$base   = self::base_dir();
		$target = $base . '/' . $relative;

		if ( 0 !== \strpos( \wp_normalize_path( $target ), \wp_normalize_path( $base ) . '/' ) ) {
			return;
		}

		if ( ! \file_exists( $target ) || ! self::init_filesystem() ) {
			return;
		}

		global $wp_filesystem;
		$wp_filesystem->delete( $target );
	}

	/**
	 * Initialise the WP_Filesystem API (direct method — uploads are always
	 * direct-writable when writable at all; a credentials-requiring setup
	 * returns false and the caller degrades to the request-time route).
	 */
	private static function init_filesystem(): bool {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return true;
		}

		if ( ! \function_exists( 'WP_Filesystem' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
		}

		return (bool) \WP_Filesystem();
	}
}
