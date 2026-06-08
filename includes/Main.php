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

		// Harden the plugin's REST surfaces against upstream BOM / whitespace
		// pollution: discard any leaked output buffer before WP echoes the
		// JSON body, scoped to the plugin's own namespace. See #175. The
		// /llms.txt and .md routes self-harden in their own dispatch().
		\WPContext\Support\Output_Buffer::register_hooks();

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
		\WPContext\Admin\Context_Profile_Rest_Controller::register_hooks();
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

		// Wire the LLMs Index WP-CLI command tree (#7 / AgDR-0022).
		// Same register() guard pattern as Markdown_Views_Command above.
		\WPContext\Cli\Llms_Txt_Command::register();

		// Wire the LLMs Index description-backfill WP-CLI command tree
		// (#8 / AgDR-0027). Mounted at `wp ai-readiness-kit llms-txt descriptions`.
		\WPContext\Cli\Llms_Txt_Descriptions_Command::register();

		// Wire the one-shot dead-cleanup-meta sweep (#159 / AgDR-0050).
		// Mounted at `wp ai-readiness-kit cleanup-meta sweep`. Deletes the
		// orphaned `_agentready_md_cleanup_*` post-meta left by the retired
		// cleanup pass (#153). No-op outside WP-CLI via the register() guard.
		\WPContext\Cli\Cleanup_Meta_Migration_Command::register();

		// Wire the Gutenberg sidebar React panel (#5 / AgDR-0014). Enqueues
		// only on block-editor screens via `enqueue_block_editor_assets`.
		Markdown_Views\Sidebar_Assets::register_hooks();

		// Wire the LLMs Index module (#7 / AgDR-0021-0023). Router owns the
		// `/llms.txt` rewrite + template_redirect dispatch; Service owns the
		// regen-on-save / regen-on-profile-change / regen-on-editorial-change
		// hooks plus the debounced single-event scheduling and the daily
		// cron backstop.
		LlmsTxt\Router::register_hooks();
		LlmsTxt\Service::register_hooks();

		// Wire the LLMs Index conflict notice (#7 Phase B / AgDR-0024).
		// Renders on Plugins screen + Tools → Context. Per-user dismissal
		// via user-meta. Detection is cached in a 5-minute transient,
		// invalidated by activate/deactivate hooks.
		LlmsTxt\Conflict_Notice::register_hooks();

		// Wire the editorial-entries Settings API (#7 Phase C / AgDR-0025).
		// Registers `agentready_llms_txt_editorial` and fires
		// `agentready_llms_txt_editorial_saved` on save — Service::register_hooks
		// above already subscribes that action to its regen-schedule path.
		LlmsTxt\Editorial_Settings::register_hooks();

		// Wire the LLM-powered entry description pipeline (#8 / AgDR-0027 / AgDR-0028).
		// Orchestrator schedules + runs per-post cron jobs; Filter subscribes to
		// Entry_Source::DESCRIPTION_FILTER so /llms.txt compose serves the cache.
		LlmsTxt\Description_Orchestrator::register_hooks();
		LlmsTxt\Description_Filter::register_hooks();

		// Wire the Phase B admin REST surface for description state +
		// inline edit + per-post regen + bulk-regen-stale (#8 / AgDR-0029).
		// Five routes under ai-readiness-kit/v1/llms-txt/descriptions/*, all
		// gated by manage_options (same as the rest of the Context
		// Profile screen).
		LlmsTxt\Descriptions_Rest_Controller::register_hooks();
		LlmsTxt\Editorial_Rest_Controller::register_hooks();

		// Wire the Context Score engine (#9 / AgDR-0030). Service owns the
		// cache option, the daily cron backstop, and the debounced
		// recompute on `agentready_context_profile_saved`. The WP-CLI
		// command exposes the breakdown as JSON (`wp ai-readiness-kit
		// context-score audit`).
		Context_Score\Service::register_hooks();
		\WPContext\Cli\Context_Score_Command::register();

		// Wire the Context Score admin surface (#10 / AgDR-0031). The
		// REST controller serves the cached breakdown to the React UI
		// and exposes the synchronous recompute endpoint backing the
		// "Recompute now" button. The Admin page owns the Tools menu
		// entry and the bundle enqueue. Site_Health registers a single
		// direct test on `site_status_tests` so WP core Site Health
		// surfaces the score without recomputing on the Site Health
		// page itself.
		Context_Score\Rest_Controller::register_hooks();
		\WPContext\Admin\Context_Score_Page::register_hooks();
		Context_Score\Site_Health::register_hooks();

		// Wire the AI Assistant Preview pane (#45 / AgDR-0046). Admin-only
		// REST surface that renders any URL the way an AI assistant consumes
		// it — raw HTML vs Markdown View vs the /llms.txt line, plus an
		// optional synchronous Sample AI Summary cached in post-meta. The
		// React panel mounts on the Context Score Tools page (PR B).
		Ai_Preview\Rest_Controller::register_hooks();

		// Wire the gap-fill JSON-LD emitter (#12 / AgDR-0033). Runs on
		// `wp_head` priority 10. When any of Yoast / Rank Math / AIOSEO is
		// detected the gap is empty and the emitter is a no-op — wp.org
		// Plugin Check stays free of duplicate-schema warnings. When no
		// SEO plugin is active, AI Readiness Kit emits a minimal baseline
		// (WebSite + Organization + WebPage/Article).
		Seo\Schema_Emitter::register_hooks();

		// Advertise the agent surfaces (#178 / AgDR-0053) so any agent reading
		// standard response metadata discovers them: per-page `.md` `<link>` +
		// `Link` header on exposable singular views, and a `/llms.txt` reference
		// in robots.txt. Gated on `advertise_alternates_enabled` + the exposure
		// model — noindex / excluded content is never advertised.
		Discovery\Alternate_Advertiser::register_hooks();

		// Wire the WordPress Abilities API surface (#21 / AgDR-0044). Registers
		// the `ai-readiness-kit` ability category + five abilities (audit-run,
		// profile-read, profile-set-exposure, llms-txt-regenerate,
		// md-view-preview) on the Abilities API init hooks. Self-guards on
		// `wp_register_ability` so it's a clean no-op if the API is absent.
		// Abilities are exposed via core's `wp-abilities/v1` REST namespace;
		// the optional mcp-adapter integration is a PR-B follow-up.
		Abilities\Registrar::register_hooks();
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

		// LLMs Index rewrite rule + daily backstop + initial cache
		// population (#7 / AgDR-0021-0023). The rewrite flush below
		// persists `^llms\.txt/?$` into rewrite_rules. Daily backstop
		// fires once per day so the cached body never sits stale
		// longer than 24 h even if a hook is missed. Initial sync
		// regen ensures a fresh install serves an empty document on
		// the first crawl (rather than triggering regen-under-lock
		// on the first agent fetch).
		LlmsTxt\Router::flush_on_activation();
		LlmsTxt\Service::schedule_daily_regen();
		LlmsTxt\Service::regen_sync();

		// Context Score daily cron backstop (#9 / AgDR-0030). Mirrors the
		// LlmsTxt daily regen — fires once per day so the cached breakdown
		// never sits stale longer than 24h, even if `agentready_context_profile_saved`
		// never fires (which is the steady-state on a quiet site).
		Context_Score\Service::schedule_daily_recompute();

		// SEO plugin detection at activation time (#12 AC #1). Stores the
		// detected posture in a non-autoload diagnostic option so the
		// admin Context Score panel has a posture to render even before
		// the first front-end request. Runtime reads stay live via
		// `Schema_Coordination_Detector::detect()` — this is purely a
		// post-activation snapshot.
		$posture = \WPContext\Admin\Schema_Coordination_Detector::detect();
		\update_option( 'agentready_seo_posture_last_seen', $posture, false );
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

		// Drop the `/llms.txt` rewrite and clear the scheduled cron
		// events (debounced single-event + daily backstop). The cached
		// body in wp_options survives — it'll be re-served verbatim if
		// the plugin is reactivated. Full purge happens on uninstall.
		LlmsTxt\Router::flush_on_deactivation();
		LlmsTxt\Service::clear_scheduled_regens();

		// Context Score cron cleanup (#9 / AgDR-0030). Cached breakdown
		// in wp_options is preserved per AgDR-0015 — only uninstall purges.
		Context_Score\Service::clear_scheduled_recomputes();
	}
}
