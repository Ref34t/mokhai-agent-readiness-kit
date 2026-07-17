<?php
/**
 * First-run onboarding nudge (#251 / AgDR-0071).
 *
 * A fresh install exposes nothing by design (`exposed_cpts` defaults to
 * an empty array), so `/llms.txt` stays header-only until the owner
 * discovers Tools → Context. This notice closes that discoverability
 * gap: it renders while exposure is effectively empty and offers a
 * confirm-gated one-click "expose posts + pages" action alongside a
 * link to manual setup. It never auto-exposes — the click + confirm is
 * the consent moment.
 *
 * The secondary-action list is filterable via `mokhai_first_run_actions`;
 * that filter is the documented seam where the 1.0 Mokhai Agent registers
 * its "agent-guided setup" entry point (AgDR-0071).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Admin notice + one-click-expose surface for first-run onboarding.
 */
final class First_Run_Notice {

	/**
	 * User-meta key marking the notice dismissed for that admin. Per-user —
	 * one admin's dismiss doesn't suppress the nudge for the rest of the
	 * team. Mirrors `Conflict_Notice::USER_META_KEY`'s per-user semantics.
	 *
	 * @var string
	 */
	public const USER_META_KEY = 'mokhai_first_run_notice_dismissed';

	/**
	 * AJAX action name for the one-click expose endpoint.
	 *
	 * @var string
	 */
	public const EXPOSE_ACTION = 'mokhai_first_run_expose';

	/**
	 * AJAX action name for the dismiss endpoint.
	 *
	 * @var string
	 */
	public const DISMISS_ACTION = 'mokhai_first_run_dismiss';

	/**
	 * CPTs the one-click action exposes. Posts + pages at publish is the
	 * confirmed sensible default (AgDR-0071) — the typical site's real
	 * content in one click, still whitelisted through `set_exposure()`.
	 *
	 * @var array<int, string>
	 */
	public const EXPOSE_CPTS = array( 'post', 'page' );

	/**
	 * Statuses the one-click action exposes.
	 *
	 * @var array<int, string>
	 */
	public const EXPOSE_STATUSES = array( 'publish' );

	/**
	 * Wire the WordPress hooks owned by this class. Called once from
	 * `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
		\add_action( 'network_admin_notices', array( self::class, 'maybe_render' ) );
		\add_action( 'wp_ajax_' . self::EXPOSE_ACTION, array( self::class, 'handle_expose' ) );
		\add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue_action_handler' ) );
	}

	/**
	 * True while the profile exposes nothing. The render condition — the
	 * notice yields automatically the moment ANY flow (this notice, Tools →
	 * Context, the REST controller, a future agent) exposes content.
	 */
	public static function is_exposure_empty(): bool {
		$profile = Context_Profile_Settings::get_profile();
		$cpts    = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();

		return array() === $cpts;
	}

	/**
	 * `admin_notices` callback. Filtered by capability + screen + exposure
	 * state + per-user dismissal.
	 */
	public static function maybe_render(): void {
		if ( ! self::should_render() ) {
			return;
		}

		self::render_notice();
	}

	/**
	 * Shared gate for rendering and script enqueueing.
	 */
	private static function should_render(): bool {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! self::is_target_screen() ) {
			return false;
		}
		if ( ! self::is_exposure_empty() ) {
			return false;
		}

		return ! self::is_dismissed_for_current_user();
	}

	/**
	 * Enqueue the small inline action handler ONLY on screens where the
	 * notice can render. Keeps every other admin page clean.
	 */
	public static function maybe_enqueue_action_handler( string $hook_suffix ): void {
		unset( $hook_suffix );
		if ( ! self::should_render() ) {
			return;
		}

		\wp_register_script(
			'mokhai-first-run',
			'',
			array(),
			\MOKHAI_VERSION,
			true
		);
		\wp_enqueue_script( 'mokhai-first-run' );
		\wp_add_inline_script(
			'mokhai-first-run',
			self::action_inline_script()
		);
	}

	/**
	 * The vanilla-JS handler for both CTAs — no React, no jQuery. The
	 * expose button asks for explicit confirmation before POSTing; on
	 * success the page reloads so the notice disappears via its own render
	 * condition (exposure no longer empty) and the regenerated state shows.
	 * The dismiss button POSTs and hides the notice in place.
	 */
	private static function action_inline_script(): string {
		$data = array(
			'url'           => \admin_url( 'admin-ajax.php' ),
			'exposeAction'  => self::EXPOSE_ACTION,
			'exposeNonce'   => \wp_create_nonce( self::EXPOSE_ACTION ),
			'dismissAction' => self::DISMISS_ACTION,
			'dismissNonce'  => \wp_create_nonce( self::DISMISS_ACTION ),
			'confirmText'   => \__(
				'Expose all published posts and pages to AI agents via /llms.txt and per-page Markdown? You can adjust or undo this any time under Tools → Context.',
				'mokhai-agent-readiness-kit'
			),
		);

		return sprintf(
			'window.mokhaiFirstRun = %s;'
			. 'document.addEventListener("click",function(e){'
			. 'var cfg = window.mokhaiFirstRun;'
			. 'var expose = e.target.closest("[data-mokhai-first-run-expose]");'
			. 'if(expose){'
			. 'e.preventDefault();'
			. 'if(!window.confirm(cfg.confirmText))return;'
			. 'expose.disabled = true;'
			. 'var body = new URLSearchParams({action: cfg.exposeAction, _wpnonce: cfg.exposeNonce});'
			. 'fetch(cfg.url,{method:"POST",credentials:"same-origin",body:body})'
			. '.then(function(r){if(r.ok){window.location.reload();}else{expose.disabled = false;}});'
			. 'return;'
			. '}'
			. 'var dismiss = e.target.closest("[data-mokhai-first-run-dismiss]");'
			. 'if(dismiss){'
			. 'e.preventDefault();'
			. 'var db = new URLSearchParams({action: cfg.dismissAction, _wpnonce: cfg.dismissNonce});'
			. 'fetch(cfg.url,{method:"POST",credentials:"same-origin",body:db})'
			. '.then(function(r){if(r.ok){var n = dismiss.closest(".notice");if(n)n.style.display="none";}});'
			. '}'
			. '});',
			(string) \wp_json_encode( $data )
		);
	}

	/**
	 * AJAX handler for the one-click expose. Requires `manage_options` +
	 * a valid nonce; the browser-side confirm() is UX, not security — the
	 * nonce + capability pair is the real gate. Writes through
	 * `Context_Profile_Settings::set_exposure()` (never the option
	 * directly) so the saved-event cascade — Context Score recompute and
	 * /llms.txt regeneration — fires exactly as on an admin save.
	 */
	public static function handle_expose(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		\check_ajax_referer( self::EXPOSE_ACTION );

		$profile = Context_Profile_Settings::set_exposure( self::EXPOSE_CPTS, self::EXPOSE_STATUSES );

		\wp_send_json_success(
			array(
				'exposed_cpts'     => $profile['exposed_cpts'] ?? array(),
				'exposed_statuses' => $profile['exposed_statuses'] ?? array(),
			)
		);
	}

	/**
	 * AJAX dismiss handler. Records a per-user flag; durable — the notice
	 * never returns for that admin unless the meta is deleted.
	 */
	public static function handle_dismiss(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		\check_ajax_referer( self::DISMISS_ACTION );

		$user_id = \get_current_user_id();
		if ( $user_id > 0 ) {
			\update_user_meta( $user_id, self::USER_META_KEY, '1' );
		}

		\wp_send_json_success();
	}

	/**
	 * True if the active screen is the Dashboard, the Plugins screen, or
	 * the Tools → Context page. Single-site and network-admin variants
	 * both count.
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
			'dashboard',
			'dashboard-network',
			'plugins',
			'plugins-network',
			'tools_page_' . Context_Profile_Page::PAGE_SLUG,
		);

		return \in_array( (string) $screen->id, $target_ids, true );
	}

	/**
	 * Per-user dismissal check.
	 */
	private static function is_dismissed_for_current_user(): bool {
		$user_id = \get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		return '1' === \get_user_meta( $user_id, self::USER_META_KEY, true );
	}

	/**
	 * The filterable secondary-action list (AgDR-0071's 1.0 seam).
	 *
	 * Each entry: `array{label: string, url: string}` rendered as a plain
	 * link button after the primary one-click CTA. The 1.0 Mokhai Agent
	 * prepends its "Set up with the Mokhai Agent" entry through this
	 * filter; the notice itself needs no changes for that.
	 *
	 * @return array<string, array{label: string, url: string}>
	 */
	public static function get_secondary_actions(): array {
		$default = array(
			'manual' => array(
				'label' => \__( 'Choose content manually', 'mokhai-agent-readiness-kit' ),
				'url'   => \admin_url( 'tools.php?page=' . Context_Profile_Page::PAGE_SLUG ),
			),
		);

		/**
		 * Filters the first-run notice's secondary actions.
		 *
		 * @since 0.8.0
		 *
		 * @param array<string, array{label: string, url: string}> $default Secondary actions keyed by slug.
		 */
		$actions = \apply_filters( 'mokhai_first_run_actions', $default );

		if ( ! \is_array( $actions ) ) {
			return $default;
		}

		$clean = array();
		foreach ( $actions as $slug => $action ) {
			if ( ! \is_array( $action ) || ! isset( $action['label'], $action['url'] ) ) {
				continue;
			}
			$clean[ (string) $slug ] = array(
				'label' => (string) $action['label'],
				'url'   => (string) $action['url'],
			);
		}

		return $clean;
	}

	/**
	 * Render the notice HTML.
	 */
	private static function render_notice(): void {
		echo '<div class="notice notice-info mokhai-first-run-notice">';
		echo '<p><strong>' . \esc_html__( 'Mokhai — choose what AI agents can read', 'mokhai-agent-readiness-kit' ) . '</strong></p>';

		echo '<p>';
		\esc_html_e(
			'Mokhai is active but not exposing any content yet — your /llms.txt is intentionally empty. That is the safe default, not a malfunction: nothing is shared until you decide.',
			'mokhai-agent-readiness-kit'
		);
		echo '</p>';

		echo '<p style="margin-top:1em;">';
		\printf(
			'<button type="button" class="button button-primary" data-mokhai-first-run-expose>%s</button> ',
			\esc_html__( 'Expose published posts & pages', 'mokhai-agent-readiness-kit' )
		);

		foreach ( self::get_secondary_actions() as $action ) {
			\printf(
				'<a href="%1$s" class="button">%2$s</a> ',
				\esc_url( $action['url'] ),
				\esc_html( $action['label'] )
			);
		}

		\printf(
			'<button type="button" class="button-link" data-mokhai-first-run-dismiss>%s</button>',
			\esc_html__( 'Dismiss — keep everything hidden', 'mokhai-agent-readiness-kit' )
		);
		echo '</p>';

		echo '</div>';
	}
}
