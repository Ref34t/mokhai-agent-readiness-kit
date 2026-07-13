<?php
/**
 * Hard-cache posture detection for the static `.md` mirror.
 *
 * The static mirror (#283 / AgDR-0067) writes publish-time `.md` files so
 * agents can fetch markdown even when a full-page cache serves HTML without
 * ever invoking PHP. Writing files on every install would be wasted risk and
 * disk on the majority of sites where PHP serves `/path.md` fine — so the
 * mirror is gated on this class: it runs only when a hard cache is actually
 * present (mode `auto`, the default) or the operator forces it (`on`/`off`).
 *
 * Detection covers BOTH cache layers:
 *
 *   1. Plugin caches — cheap constant / class sniffing (WP Rocket, W3TC,
 *      WP Super Cache, LiteSpeed, Cache Enabler, …) plus the generic
 *      `advanced-cache.php` drop-in signal. Live on every call.
 *   2. Host / CDN caches (Kinsta, Cloudflare APO, Varnish, …) — invisible to
 *      constant sniffing, so a loopback self-probe inspects the response
 *      headers of the site's own front page. The probe result is cached in a
 *      non-autoload option and refreshed lazily from admin_init.
 *
 * Same posture-snapshot pattern as the SEO detection
 * (`Admin\Schema_Coordination_Detector` + `Main::SEO_POSTURE_OPTION`).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Markdown_Views;

use Mokhai\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * Resolves "should the static mirror write files right now?".
 */
final class Cache_Posture {

	/**
	 * Option storing the loopback-probe snapshot:
	 * `{host_cache: string|null, checked_at: int}`.
	 *
	 * @var string
	 */
	public const SNAPSHOT_OPTION = 'mokhai_cache_posture';

	/**
	 * Probe snapshot time-to-live in seconds (12 hours). Host caching
	 * changes rarely; a half-day lag between a host enabling caching and
	 * the mirror activating is acceptable, and the daily mirror-sync cron
	 * backstop closes the file gap afterwards.
	 *
	 * @var int
	 */
	public const PROBE_TTL = 43200;

	/**
	 * Filter over the final "mirror active?" boolean. Lets tests and edge-case
	 * operators override the resolution without touching the profile.
	 *
	 * @var string
	 */
	public const FILTER_MIRROR_ACTIVE = 'mokhai_static_mirror_active';

	/**
	 * Plugin-cache signatures: detection callback → human label. Constants and
	 * classes are how these plugins announce themselves; each entry is a
	 * single `defined()` / `class_exists()` — no I/O.
	 *
	 * @return array<string, callable(): bool>
	 */
	private static function plugin_signatures(): array {
		return array(
			'WP Rocket'            => static fn(): bool => \defined( 'WP_ROCKET_VERSION' ),
			'W3 Total Cache'       => static fn(): bool => \defined( 'W3TC' ),
			'WP Super Cache'       => static fn(): bool => \defined( 'WPCACHEHOME' ),
			'LiteSpeed Cache'      => static fn(): bool => \defined( 'LSCWP_V' ),
			'Cache Enabler'        => static fn(): bool => \defined( 'CACHE_ENABLER_VERSION' ),
			'Breeze'               => static fn(): bool => \defined( 'BREEZE_VERSION' ),
			'Hummingbird'          => static fn(): bool => \defined( 'WPHB_VERSION' ),
			'WP Fastest Cache'     => static fn(): bool => \class_exists( 'WpFastestCache' ),
			'SiteGround Optimizer' => static fn(): bool => \defined( '\SiteGround_Optimizer\VERSION' ),
		);
	}

	/**
	 * Wire the lazy probe refresh. `admin_init` keeps the loopback request off
	 * the front-end hot path entirely — a stale snapshot refreshes on the next
	 * wp-admin page-load. Called once from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_init', array( self::class, 'maybe_refresh_probe' ) );
	}

	/**
	 * Should the static mirror be writing files right now?
	 *
	 * Resolution: profile `static_md_mode` — `on` → true, `off` → false,
	 * `auto` (default) → true when either detection layer finds a hard cache.
	 */
	public static function mirror_active(): bool {
		$profile = Context_Profile_Settings::get_profile();
		$mode    = isset( $profile['static_md_mode'] ) ? (string) $profile['static_md_mode'] : 'auto';

		if ( 'on' === $mode ) {
			$active = true;
		} elseif ( 'off' === $mode ) {
			$active = false;
		} else {
			$active = ( null !== self::detect_plugin_cache() ) || ( null !== self::snapshot_host_cache() );
		}

		/**
		 * Filter the resolved "static mirror active?" decision.
		 *
		 * @param bool $active The resolution from mode + detection.
		 */
		// The constant IS mokhai-prefixed ('mokhai_static_mirror_active') —
		// the sniff just can't resolve a hook name through a class constant.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		return (bool) \apply_filters( self::FILTER_MIRROR_ACTIVE, $active );
	}

	/**
	 * Detect an active page-caching plugin. Live on every call — each
	 * signature is a constant / class check, so the cost is negligible and a
	 * freshly-activated cache plugin is seen on the very next request.
	 *
	 * @return string|null Human label of the detected plugin, or null.
	 */
	public static function detect_plugin_cache(): ?string {
		foreach ( self::plugin_signatures() as $label => $matches ) {
			if ( $matches() ) {
				return $label;
			}
		}

		// Generic signal: WP_CACHE + an advanced-cache.php drop-in means SOME
		// full-page cache is installed even if we don't know its name.
		if ( \defined( 'WP_CACHE' ) && \WP_CACHE
			&& \file_exists( \WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			return 'advanced-cache drop-in';
		}

		return null;
	}

	/**
	 * Judge a set of HTTP response headers for host / CDN cache signatures.
	 *
	 * Pure — unit-tested directly. Keys are compared case-insensitively.
	 * HIT-family values are required where a header also appears on
	 * uncached responses (`cf-cache-status: DYNAMIC` means Cloudflare is
	 * proxying but NOT page-caching the HTML, so it must not activate the
	 * mirror).
	 *
	 * @param array<string, string> $headers Response headers (name → value).
	 *
	 * @return string|null Human label of the detected cache layer, or null.
	 */
	public static function evaluate_probe_headers( array $headers ): ?string {
		$normalized = array();
		foreach ( $headers as $name => $value ) {
			$normalized[ \strtolower( (string) $name ) ] = \strtolower( \trim( (string) $value ) );
		}

		if ( isset( $normalized['x-kinsta-cache'] ) ) {
			return 'Kinsta';
		}

		if ( isset( $normalized['cf-cache-status'] )
			&& \in_array( $normalized['cf-cache-status'], array( 'hit', 'stale', 'updating', 'revalidated' ), true ) ) {
			return 'Cloudflare';
		}

		if ( isset( $normalized['x-litespeed-cache'] ) && 'hit' === $normalized['x-litespeed-cache'] ) {
			return 'LiteSpeed server';
		}

		if ( isset( $normalized['x-varnish'] ) ) {
			return 'Varnish';
		}

		if ( isset( $normalized['x-sucuri-cache'] ) && 'hit' === $normalized['x-sucuri-cache'] ) {
			return 'Sucuri';
		}

		foreach ( array( 'x-cache', 'x-proxy-cache', 'x-nginx-cache', 'x-cache-status' ) as $generic ) {
			if ( isset( $normalized[ $generic ] ) && false !== \strpos( $normalized[ $generic ], 'hit' ) ) {
				return 'proxy cache (' . $generic . ')';
			}
		}

		if ( isset( $normalized['age'] ) && (int) $normalized['age'] > 0 ) {
			return 'shared cache (Age header)';
		}

		return null;
	}

	/**
	 * `admin_init` callback: refresh the probe snapshot when it's stale.
	 */
	public static function maybe_refresh_probe(): void {
		$snapshot = \get_option( self::SNAPSHOT_OPTION, null );
		$checked  = \is_array( $snapshot ) && isset( $snapshot['checked_at'] ) ? (int) $snapshot['checked_at'] : 0;

		if ( \time() - $checked < self::PROBE_TTL ) {
			return;
		}

		self::refresh_probe_snapshot();
	}

	/**
	 * Run the loopback probe and persist the snapshot.
	 *
	 * Fetches the site's own front page (up to twice: a first request may
	 * legitimately MISS and prime the cache; the second sees the HIT) and
	 * evaluates the response headers. Failures store a null result — the
	 * mirror then falls back to plugin-sniff-only in auto mode, never
	 * blocking anything.
	 *
	 * @return array{host_cache: string|null, checked_at: int} The stored snapshot.
	 */
	public static function refresh_probe_snapshot(): array {
		$host_cache = null;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$response = \wp_remote_get(
				\home_url( '/' ),
				array(
					'timeout'             => 3,
					'redirection'         => 2,
					'limit_response_size' => 1024,
					'user-agent'          => 'mokhai-cache-probe/1.0',
				)
			);

			if ( \is_wp_error( $response ) ) {
				break;
			}

			$headers = \wp_remote_retrieve_headers( $response );
			$headers = \is_object( $headers ) && \method_exists( $headers, 'getAll' )
				? $headers->getAll()
				: (array) $headers;

			// Requests\Utility\CaseInsensitiveDictionary can hold string[] values
			// for repeated headers — flatten to the last occurrence.
			$flat = array();
			foreach ( $headers as $name => $value ) {
				$flat[ (string) $name ] = \is_array( $value ) ? (string) \end( $value ) : (string) $value;
			}

			$host_cache = self::evaluate_probe_headers( $flat );
			if ( null !== $host_cache ) {
				break;
			}
		}

		$snapshot = array(
			'host_cache' => $host_cache,
			'checked_at' => \time(),
		);

		\update_option( self::SNAPSHOT_OPTION, $snapshot, false );

		return $snapshot;
	}

	/**
	 * Read the cached probe verdict. Never triggers a probe — refresh is
	 * admin_init's job, so front-end requests only pay one option read.
	 *
	 * @return string|null Host-cache label from the last probe, or null.
	 */
	private static function snapshot_host_cache(): ?string {
		$snapshot = \get_option( self::SNAPSHOT_OPTION, null );

		if ( ! \is_array( $snapshot ) || empty( $snapshot['host_cache'] ) ) {
			return null;
		}

		return (string) $snapshot['host_cache'];
	}
}
