<?php
/**
 * Admin notice surface for `/llms.txt` conflicts (#7 Phase B / AgDR-0024).
 *
 * Renders on two screens (Plugins screen, Tools → Context page) when
 * `Conflict_Detector::detect()` returns conflicts and the current user
 * hasn't dismissed this exact conflict fingerprint. Per-user dismissal
 * is stored as user-meta so one admin's dismiss doesn't suppress the
 * notice for the rest of the team.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\LlmsTxt;

use Mokhai\Admin\Context_Profile_Page;

\defined( 'ABSPATH' ) || exit;

/**
 * Admin notice + dismiss-AJAX surface for LLMs Index conflicts.
 */
final class Conflict_Notice {

	/**
	 * User-meta key storing the array of dismissed conflict fingerprints
	 * for the current admin. Stored per-user — a personal "don't show me
	 * this again" signal, not site-wide.
	 *
	 * @var string
	 */
	public const USER_META_KEY = 'mokhai_llms_txt_dismissed_conflicts';

	/**
	 * Transient that caches `Conflict_Detector::detect()` for 5 minutes.
	 * Detection is cheap (~1ms) but the cache keeps notice rendering
	 * predictable across an admin's page-load burst.
	 *
	 * @var string
	 */
	public const CACHE_TRANSIENT = 'mokhai_llms_txt_conflicts';

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	public const CACHE_TTL = 5 * \MINUTE_IN_SECONDS;

	/**
	 * AJAX action name for the dismiss endpoint.
	 *
	 * @var string
	 */
	public const DISMISS_ACTION = 'mokhai_llms_txt_dismiss_conflict';

	/**
	 * Wire the WordPress hooks owned by this class. Called once from
	 * `Main::register_hooks()`.
	 *
	 * The notice runs on `admin_notices`. The dismiss endpoint is an
	 * authenticated `wp_ajax_*` action — no non-priv variant because
	 * dismissing is a privileged admin action.
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
		\add_action( 'network_admin_notices', array( self::class, 'maybe_render' ) );
		\add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue_dismiss_handler' ) );

		// Conflicts that change after an admin action (deactivate competitor,
		// delete static file) should refresh the cached detection. Cheap
		// invalidation — just delete the transient and the next admin page
		// will re-detect.
		\add_action( 'deactivated_plugin', array( self::class, 'invalidate_cache' ) );
		\add_action( 'activated_plugin', array( self::class, 'invalidate_cache' ) );
	}

	/**
	 * Drop the conflict-detection transient. Called when the active-plugin
	 * set changes; the next admin pageload re-runs `detect()`.
	 */
	public static function invalidate_cache(): void {
		\delete_transient( self::CACHE_TRANSIENT );
	}

	/**
	 * Resolve the conflict list, served from the 5-minute transient cache
	 * when present. Exposes the cached list as the source of truth for
	 * the notice + the WP-CLI `status` command.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_conflicts(): array {
		$cached = \get_transient( self::CACHE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$conflicts = Conflict_Detector::detect();
		\set_transient( self::CACHE_TRANSIENT, $conflicts, self::CACHE_TTL );

		return $conflicts;
	}

	/**
	 * `admin_notices` callback. Filtered by screen + capability + dismissal.
	 */
	public static function maybe_render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::is_target_screen() ) {
			return;
		}

		$conflicts = self::get_conflicts();
		if ( array() === $conflicts ) {
			return;
		}

		$fingerprint = Conflict_Detector::fingerprint( $conflicts );
		if ( self::is_dismissed_for_current_user( $fingerprint ) ) {
			return;
		}

		self::render_notice( $conflicts, $fingerprint );
	}

	/**
	 * Enqueue the small inline dismiss handler ONLY on screens where the
	 * notice can render. Keeps every other admin page clean.
	 */
	public static function maybe_enqueue_dismiss_handler( string $hook_suffix ): void {
		unset( $hook_suffix );
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::is_target_screen() ) {
			return;
		}
		if ( array() === self::get_conflicts() ) {
			return;
		}

		\wp_register_script(
			'mokhai-llms-txt-dismiss',
			'',
			array(),
			\MOKHAI_VERSION,
			true
		);
		\wp_enqueue_script( 'mokhai-llms-txt-dismiss' );
		\wp_add_inline_script(
			'mokhai-llms-txt-dismiss',
			self::dismiss_inline_script()
		);
	}

	/**
	 * The tiny vanilla-JS dismiss handler — wires the notice's "Dismiss"
	 * button to a `fetch( admin-ajax.php )` POST. No React, no jQuery.
	 *
	 * Localised via inline rather than `wp_localize_script` because we
	 * only need three values (URL, nonce, action) and `wp_add_inline_script`
	 * keeps the bundle to one network call.
	 */
	private static function dismiss_inline_script(): string {
		$nonce = \wp_create_nonce( self::DISMISS_ACTION );
		$url   = \admin_url( 'admin-ajax.php' );
		$data  = array(
			'url'    => $url,
			'nonce'  => $nonce,
			'action' => self::DISMISS_ACTION,
		);
		return sprintf(
			'window.mokhaiLlmsTxtDismiss = %s;'
			. 'document.addEventListener("click",function(e){'
			. 'var btn = e.target.closest("[data-mokhai-dismiss-fingerprint]");'
			. 'if(!btn)return;'
			. 'e.preventDefault();'
			. 'var fp = btn.getAttribute("data-mokhai-dismiss-fingerprint");'
			. 'var cfg = window.mokhaiLlmsTxtDismiss;'
			. 'var body = new URLSearchParams({action: cfg.action, _wpnonce: cfg.nonce, fingerprint: fp});'
			. 'fetch(cfg.url,{method:"POST",credentials:"same-origin",body:body})'
			. '.then(function(r){if(r.ok){var n = btn.closest(".notice");if(n)n.style.display="none";}});'
			. '});',
			(string) \wp_json_encode( $data )
		);
	}

	/**
	 * AJAX dismiss handler. Records the fingerprint to user-meta.
	 */
	public static function handle_dismiss(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		\check_ajax_referer( self::DISMISS_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_fp = isset( $_POST['fingerprint'] ) ? \sanitize_key( \wp_unslash( $_POST['fingerprint'] ) ) : '';
		if ( '' === $raw_fp || ! \preg_match( '/^[a-f0-9]{40}$/', $raw_fp ) ) {
			\wp_send_json_error( array( 'message' => 'invalid_fingerprint' ), 400 );
		}

		self::add_dismissal_for_current_user( $raw_fp );
		\wp_send_json_success();
	}

	/**
	 * True if the active screen is the Plugins screen or the Tools →
	 * Context page. Both single-site and multisite network-admin variants
	 * count.
	 */
	private static function is_target_screen(): bool {
		if ( ! \function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = \get_current_screen();
		if ( null === $screen || empty( $screen->id ) ) {
			return false;
		}

		$target_ids = array(
			'plugins',
			'plugins-network',
			'tools_page_' . Context_Profile_Page::PAGE_SLUG,
		);

		return \in_array( (string) $screen->id, $target_ids, true );
	}

	/**
	 * @return array<int, string>
	 */
	private static function dismissed_fingerprints_for_current_user(): array {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		$stored = \get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( $stored, 'is_string' ) );
	}

	private static function is_dismissed_for_current_user( string $fingerprint ): bool {
		return in_array( $fingerprint, self::dismissed_fingerprints_for_current_user(), true );
	}

	private static function add_dismissal_for_current_user( string $fingerprint ): void {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$existing   = self::dismissed_fingerprints_for_current_user();
		$existing[] = $fingerprint;
		$existing   = array_values( array_unique( $existing ) );
		\update_user_meta( $user_id, self::USER_META_KEY, $existing );
	}

	/**
	 * Render the notice HTML.
	 *
	 * @param array<int, array<string, mixed>> $conflicts   Detected conflicts.
	 * @param string                           $fingerprint Hash for dismissal.
	 */
	private static function render_notice( array $conflicts, string $fingerprint ): void {
		$plugin_conflicts     = array_values( array_filter( $conflicts, static fn ( $c ) => 'plugin' === ( $c['kind'] ?? '' ) ) );
		$filesystem_conflicts = array_values( array_filter( $conflicts, static fn ( $c ) => 'filesystem' === ( $c['kind'] ?? '' ) ) );
		$rewrite_conflicts    = array_values( array_filter( $conflicts, static fn ( $c ) => 'rewrite' === ( $c['kind'] ?? '' ) ) );

		echo '<div class="notice notice-warning is-dismissible mokhai-llms-txt-conflict-notice">';
		echo '<p><strong>' . \esc_html__( 'Mokhai — /llms.txt conflict detected', 'mokhai-agent-readiness-kit' ) . '</strong></p>';

		if ( ! empty( $plugin_conflicts ) ) {
			self::render_plugin_section( $plugin_conflicts );
		}
		if ( ! empty( $filesystem_conflicts ) ) {
			self::render_filesystem_section( $filesystem_conflicts );
		}
		if ( ! empty( $rewrite_conflicts ) ) {
			self::render_rewrite_section( $rewrite_conflicts );
		}

		echo '<p style="margin-top:1em;">';
		\printf(
			'<a href="%1$s" class="button button-primary">%2$s</a> ',
			\esc_url( \admin_url( 'plugins.php' ) ),
			\esc_html__( 'Open Plugins screen', 'mokhai-agent-readiness-kit' )
		);
		\printf(
			'<button type="button" class="button" data-mokhai-dismiss-fingerprint="%1$s">%2$s</button>',
			\esc_attr( $fingerprint ),
			\esc_html__( 'Dismiss for this conflict', 'mokhai-agent-readiness-kit' )
		);
		echo '</p>';

		echo '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $plugins Plugin-conflict entries.
	 */
	private static function render_plugin_section( array $plugins ): void {
		echo '<p>';
		\esc_html_e(
			'The following plugins also publish /llms.txt and will compete with Mokhai\'s route:',
			'mokhai-agent-readiness-kit'
		);
		echo '</p><ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $plugins as $plugin ) {
			$name = isset( $plugin['name'] ) ? (string) $plugin['name'] : '';
			$url  = isset( $plugin['url'] ) ? (string) $plugin['url'] : '';
			echo '<li>';
			if ( '' !== $url ) {
				\printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					\esc_url( $url ),
					\esc_html( $name )
				);
			} else {
				echo \esc_html( $name );
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p>';
		\esc_html_e(
			'To switch to Mokhai\'s /llms.txt, deactivate the competing plugin(s) above. If you intentionally run both — e.g. one for /llms.txt, another for something else — dismiss this notice.',
			'mokhai-agent-readiness-kit'
		);
		echo '</p>';
		echo '<p><em>';
		\esc_html_e(
			'Note: if you manually curated entries in another plugin, they will not transfer automatically. Back them up before deactivating.',
			'mokhai-agent-readiness-kit'
		);
		echo '</em></p>';
	}

	/**
	 * @param array<int, array<string, mixed>> $files Filesystem-conflict entries.
	 */
	private static function render_filesystem_section( array $files ): void {
		echo '<p>';
		\esc_html_e(
			'A static /llms.txt file exists at the WordPress root. Web servers serve static files before WordPress loads, so Mokhai\'s /llms.txt route is being shadowed.',
			'mokhai-agent-readiness-kit'
		);
		echo '</p>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $files as $file ) {
			echo '<li><code>' . \esc_html( (string) ( $file['path'] ?? '' ) ) . '</code></li>';
		}
		echo '</ul>';
		echo '<p>';
		\esc_html_e(
			'To resolve: back up the existing file contents if you need any of them, then delete the file via FTP/SFTP or your hosting file manager.',
			'mokhai-agent-readiness-kit'
		);
		echo '</p>';
	}

	/**
	 * @param array<int, array<string, mixed>> $rules Rewrite-conflict entries.
	 */
	private static function render_rewrite_section( array $rules ): void {
		echo '<p>';
		\esc_html_e(
			'Another plugin registered a WordPress rewrite rule for /llms.txt that overrides Mokhai\'s route. The competing rule is:',
			'mokhai-agent-readiness-kit'
		);
		echo '</p>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $rules as $rule ) {
			echo '<li><code>' . \esc_html( (string) ( $rule['rule'] ?? '' ) ) . '</code></li>';
		}
		echo '</ul>';
		echo '<p>';
		\esc_html_e(
			'Identify the responsible plugin from the rewrite target above and deactivate it from the Plugins screen.',
			'mokhai-agent-readiness-kit'
		);
		echo '</p>';
	}
}
