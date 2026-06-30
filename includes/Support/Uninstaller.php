<?php
/**
 * Uninstall cleanup — the single source of truth for every persistent key
 * the plugin owns.
 *
 * `uninstall.php` (the WordPress uninstall entry point) delegates here so the
 * cleanup lists live next to the classes that define the keys, referenced via
 * their public constants. Re-typed literal lists in `uninstall.php` drifted
 * twice (#189): the central Context Profile option was never deleted while a
 * dead `agentready_settings` key was, and the #180 exclude meta plus the
 * description / AI-summary meta were orphaned.
 *
 * When a feature adds a new option, post-meta, user-meta, or transient key,
 * extend the matching accessor below — the uninstall integration test seeds
 * every listed key and then asserts the whole `agentready` footprint is gone,
 * so a missing entry fails the suite.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Support;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Ai_Preview\Summary_Generator;
use Mokhai\Cli\Cleanup_Meta_Migration_Command;
use Mokhai\Context_Score\Service as Context_Score_Service;
use Mokhai\Discovery\Channel_Router;
use Mokhai\LlmsTxt\Conflict_Notice;
use Mokhai\LlmsTxt\Description_Orchestrator;
use Mokhai\LlmsTxt\Editorial_Settings;
use Mokhai\LlmsTxt\Router as Llms_Txt_Router;
use Mokhai\LlmsTxt\Service as Llms_Txt_Service;
use Mokhai\Main;
use Mokhai\Markdown_Views\Schema;

\defined( 'ABSPATH' ) || exit;

/**
 * Deletes every option, post-meta, user-meta, transient, and custom table the
 * plugin writes. Multisite-aware: per-site surfaces are swept on every site.
 */
final class Uninstaller {

	/**
	 * Every wp_options key the plugin writes, referenced from the constant
	 * on the class that owns the write.
	 *
	 * @return array<int, string>
	 */
	public static function option_keys(): array {
		return array(
			Context_Profile_Settings::OPTION_KEY,
			Main::VERSION_OPTION,
			Main::SEO_POSTURE_OPTION,
			Schema::SCHEMA_VERSION_OPTION,
			Llms_Txt_Service::CACHE_OPTION,
			Llms_Txt_Service::FULL_CACHE_OPTION,
			Llms_Txt_Router::ROUTES_VERSION_OPTION,
			Editorial_Settings::OPTION_KEY,
			Context_Score_Service::CACHE_OPTION,
			Channel_Router::ROUTES_VERSION_OPTION,
		);
	}

	/**
	 * Every post-meta key the plugin writes.
	 *
	 * The retired cleanup-pass keys (#153 / AgDR-0050) stay listed so an
	 * uninstall also sweeps installs that never ran the `cleanup-meta sweep`
	 * migration command.
	 *
	 * @return array<int, string>
	 */
	public static function post_meta_keys(): array {
		return \array_merge(
			array(
				Context_Profile_Settings::EXCLUDE_META_KEY,
				Description_Orchestrator::META_KEY_AUTO,
				Description_Orchestrator::META_KEY_MANUAL,
				Description_Orchestrator::META_KEY_GENERATED_FOR_MODIFIED,
				Description_Orchestrator::META_KEY_STATUS,
				Description_Orchestrator::META_KEY_DIAGNOSTICS,
				Description_Orchestrator::META_KEY_GENERATED_BY_VERSION,
				Summary_Generator::META_KEY_TEXT,
				Summary_Generator::META_KEY_GENERATED,
			),
			Cleanup_Meta_Migration_Command::META_KEYS
		);
	}

	/**
	 * Every user-meta key the plugin writes.
	 *
	 * @return array<int, string>
	 */
	public static function user_meta_keys(): array {
		return array(
			Conflict_Notice::USER_META_KEY,
		);
	}

	/**
	 * Every transient key the plugin writes.
	 *
	 * @return array<int, string>
	 */
	public static function transient_keys(): array {
		return array(
			Llms_Txt_Service::REGEN_LOCK_TRANSIENT,
			Conflict_Notice::CACHE_TRANSIENT,
		);
	}

	/**
	 * Remove the plugin's full persistent footprint.
	 *
	 * Per-site surfaces (options, post-meta, user-meta, transients) are swept
	 * per site on multisite via `switch_to_blog()`; site options live once in
	 * `wp_sitemeta` and are deleted once; the Markdown Views cache table drop
	 * iterates sites internally (see Schema::drop_for_all_sites).
	 */
	public static function run(): void {
		if ( \is_multisite() ) {
			/** @var array<int, int> $site_ids */
			$site_ids = \get_sites( array( 'fields' => 'ids' ) );
			foreach ( $site_ids as $site_id ) {
				\switch_to_blog( (int) $site_id );
				self::clean_current_site();
				\restore_current_blog();
			}
		} else {
			self::clean_current_site();
		}

		/*
		 * Network-wide site options live in wp_sitemeta, not per-site
		 * wp_options. On single-site installs delete_site_option() is a
		 * no-op. Called once (not inside the per-site loop) because the
		 * network option exists in exactly one place.
		 */
		foreach ( self::option_keys() as $option_key ) {
			\delete_site_option( $option_key );
		}

		// Drop the Markdown Views cache table (#5 / AgDR-0011). The Schema
		// helper iterates `get_sites()` internally on multisite, so this call
		// stays outside the per-site loop above to avoid double-drop.
		Schema::drop_for_all_sites();
	}

	/**
	 * Sweep the per-site surfaces for the current site context.
	 *
	 * `delete_post_meta_by_key`, `delete_metadata( 'user', … )`, and
	 * `delete_transient` all operate against the current site, which is why
	 * `run()` does the multisite switching rather than a network-scope sweep.
	 */
	private static function clean_current_site(): void {
		foreach ( self::option_keys() as $option_key ) {
			\delete_option( $option_key );
		}

		foreach ( self::post_meta_keys() as $meta_key ) {
			\delete_post_meta_by_key( $meta_key );
		}

		foreach ( self::user_meta_keys() as $meta_key ) {
			\delete_metadata( 'user', 0, $meta_key, '', true );
		}

		foreach ( self::transient_keys() as $transient_key ) {
			\delete_transient( $transient_key );
		}
	}
}
