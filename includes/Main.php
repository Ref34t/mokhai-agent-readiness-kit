<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Top-level plugin singleton.
 *
 * Owns the lifecycle hooks (activation / deactivation), wires the public
 * subsystems (Profile, Markdown, LlmsTxt, Audit, Admin, REST, CLI), and is
 * the single entry-point the main plugin file calls into.
 */
final class Main {

	/**
	 * Singleton instance.
	 *
	 * @var Main|null
	 */
	private static ?Main $instance = null;

	/**
	 * Return the singleton, constructing it on first call.
	 */
	public static function get_instance(): Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {}

	/**
	 * Wire the WordPress action / filter hooks owned by the plugin core.
	 *
	 * Subsystems (Profile, Markdown, LlmsTxt, Audit, Admin, REST, CLI) wire
	 * their own hooks from their own bootstraps — Main only owns plugin-level
	 * lifecycle. Translations are auto-loaded by wp.org under the plugin
	 * slug since WP 4.6 (see AgDR-0009) — no manual loader registered here.
	 */
	private function register_hooks(): void {
		\register_activation_hook( \WPCTX_FILE, array( $this, 'on_activate' ) );
		\register_deactivation_hook( \WPCTX_FILE, array( $this, 'on_deactivate' ) );

		// Soft-degrade notice on plugin admin pages when WP AI Client is
		// unconfigured. See AgDR-0003 + ticket #2.
		Requirements::register_ai_client_notice();

		// Wire the deferred-retry cron handler. v0.1 ships a no-op handler;
		// #6 / #8 / #11 attach their module-scoped re-generation logic onto
		// the same action (Client_Wrapper::RETRY_ACTION).
		\WPContext\Ai\Client_Wrapper::register_hooks();

		// Wire the Context Profile admin screen (#4 / AgDR-0002).
		// Registers the Settings API option, the Tools → Context menu, and
		// the admin-only asset enqueue. Front-end requests pay no cost —
		// admin_init / admin_menu / admin_enqueue_scripts only fire in wp-admin.
		\WPContext\Admin\Context_Profile_Settings::register_hooks();
		\WPContext\Admin\Context_Profile_Page::register_hooks();

		// Wire the Markdown Views cache schema upgrade-on-admin_init
		// (#52). Comparison is one option read on every admin
		// page-load; the `dbDelta()` re-run only fires when the
		// installed version lags `SCHEMA_VERSION`. Without this, a
		// plugin update that bumps the schema sits with stale columns
		// until the user manually deactivates + reactivates.
		Markdown_Views\Schema::register_hooks();

		// Wire the Markdown Views cache-invalidation hooks (#5 / AgDR-0011).
		// `save_post`, `wp_trash_post`, `before_delete_post`, and
		// `wp_after_insert_post` all funnel into Service::invalidate().
		Markdown_Views\Service::register_hooks();

		// Wire the public route (#5 / AgDR-0013): registers the rewrite rule
		// + query var + template_redirect handler. Flush happens in
		// on_activate() so the rule persists into the rewrite_rules option.
		Markdown_Views\Router::register_hooks();

		// Wire the admin REST preview endpoint (#5 / AgDR-0014). Gated by
		// edit_post capability on the specific post; surfaces the exposure
		// reason for admin debugging (the public route stays uniformly 404
		// per AgDR-0015).
		Markdown_Views\Rest_Controller::register_hooks();

		// Wire the WP-CLI command tree (#5 / AgDR-0014). No-op when not
		// running under WP-CLI — the register() guard handles the runtime
		// check so the regular page-load path pays zero cost.
		\WPContext\Cli\Markdown_Views_Command::register();

		// Wire the Gutenberg sidebar React panel (#5 / AgDR-0014). Enqueues
		// only on block-editor screens via `enqueue_block_editor_assets`.
		Markdown_Views\Sidebar_Assets::register_hooks();

		// Wire the Markdown Views LLM cleanup cron handler (#6 / AgDR-0016-18).
		// Schedule decisions happen in Service::get_markdown_for_post; the
		// SCHEDULE_ACTION cron event fires the orchestrator's async cleanup
		// run. Service::register_hooks above also clears cleanup state on
		// post-edit lifecycle events.
		Markdown_Views\Cleanup_Orchestrator::register_hooks();

		// Wire the Phase-B admin REST surface for cleanup actions
		// (#6 / AgDR-0020). Four routes under agentready/v1/markdown-views/cleanup/*,
		// each gated by edit_post on the target post.
		Markdown_Views\Cleanup_Rest_Controller::register_hooks();
	}

	/**
	 * Activation callback.
	 *
	 * Runs once when the plugin is activated. The Context Profile (#4 /
	 * AgDR-002) stores settings in a single versioned wp_options entry and
	 * needs no schema. The Markdown Views cache (#5 / AgDR-0011) introduces
	 * the first custom table — provisioned here via `dbDelta()` so the
	 * activation is idempotent across re-activations and network activates
	 * every site at once. Future schema changes go through a /migration
	 * ticket per .claude/rules/workflow-gates.md Gate 3a.
	 */
	public function on_activate(): void {
		// Requirements gate FIRST — refuses activation on WP < 7.0 / PHP < 7.4
		// by calling deactivate_plugins() + wp_die(). If the gate refuses,
		// execution stops inside wp_die() and the version-option write below
		// never runs (correct: nothing was activated).
		Requirements::check_activation();

		if ( false === \get_option( 'agentready_version' ) ) {
			\add_option( 'agentready_version', \WPCTX_VERSION, '', false );
		} else {
			\update_option( 'agentready_version', \WPCTX_VERSION, false );
		}

		// Markdown Views cache table (#5 / AgDR-0011). Multisite-aware:
		// network activation provisions a per-site table on every site.
		Markdown_Views\Schema::create_for_all_sites();

		// Markdown Views rewrite rule (#5 / AgDR-0013). Persists the
		// `^(.+)\.md/?$` rule into the rewrite_rules option so the public
		// route survives to wp_loaded.
		Markdown_Views\Router::flush_on_activation();
	}

	/**
	 * Deactivation callback.
	 *
	 * Removes registered rewrite rules so the site reverts to vanilla
	 * permalink behaviour, and clears transient caches owned by individual
	 * modules. Persistent options (Context Profile, schema version, cache
	 * rows) are preserved — only the explicit uninstall path removes them.
	 * Re-activation should be cheap and lossless.
	 */
	public function on_deactivate(): void {
		// Drop our `/path.md` rewrite by flushing without our init hook
		// registered (deactivation has already unhooked the plugin).
		Markdown_Views\Router::flush_on_deactivation();
	}
}
