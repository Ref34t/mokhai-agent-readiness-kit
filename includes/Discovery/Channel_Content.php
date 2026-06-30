<?php
/**
 * Payload builders for the served AI-discovery channels (#172 / AgDR-0056).
 *
 * Pure composition: every builder derives its output from site identity
 * (`blogname`, `home_url()`) and the Context Profile's policy stance. No AI
 * provider, no external HTTP, no post queries — the payloads are O(1)
 * metadata, which is why these channels have no cache layer (AgDR-0056
 * § Options, option C).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Discovery;

use Mokhai\Admin\Context_Profile_Settings;

\defined( 'ABSPATH' ) || exit;

/**
 * Builds the ai.txt, llms-policy.json, and ai-layer payloads.
 */
final class Channel_Content {

	/**
	 * Payload format version stamped into both JSON channels so future
	 * shape changes are detectable by consumers.
	 */
	public const PAYLOAD_VERSION = 1;

	/**
	 * Compose the `ai.txt` body — a robots.txt-shaped, human-skimmable
	 * declaration pointing agents at the machine-readable channels.
	 */
	public static function ai_txt(): string {
		$policy  = self::policy_stance();
		$sitemap = self::sitemap_url();

		$lines   = array();
		$lines[] = '# ai.txt for ' . self::site_name();
		$lines[] = '# AI-agent guidance. Served dynamically by the Mokhai plugin.';
		$lines[] = '';
		$lines[] = 'User-agent: *';
		$lines[] = 'Allow: /';
		$lines[] = '';
		$lines[] = 'LLMs-Index: ' . \home_url( '/llms.txt' );
		if ( '' !== $sitemap ) {
			$lines[] = 'Sitemap: ' . $sitemap;
		}
		$lines[] = '';
		$lines[] = '# Usage policy (declarative — see /.well-known/llms-policy.json)';
		$lines[] = 'Inference: ' . ( $policy['allow_inference'] ? 'allowed' : 'disallowed' );
		$lines[] = 'Training: ' . ( $policy['allow_training'] ? 'allowed' : 'disallowed' );
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Compose the `/.well-known/llms-policy.json` payload.
	 *
	 * The access-stance fields are profile-driven (AgDR-0056): this file
	 * DECLARES the operator's policy, it does not enforce it.
	 *
	 * @return array<string, mixed>
	 */
	public static function llms_policy(): array {
		$payload = array(
			'version'      => self::PAYLOAD_VERSION,
			'organization' => self::site_name(),
			'website'      => \home_url( '/' ),
			'llms_txt'     => \home_url( '/llms.txt' ),
			'policy'       => self::policy_stance(),
		);

		$sitemap = self::sitemap_url();
		if ( '' !== $sitemap ) {
			$payload['sitemap'] = $sitemap;
		}

		return $payload;
	}

	/**
	 * Compose the `/.well-known/ai-layer` payload — a descriptor of the
	 * agent-facing channels this site serves, so an agent that finds any
	 * one channel can discover the rest.
	 *
	 * @return array<string, mixed>
	 */
	public static function ai_layer(): array {
		return array(
			'version'   => self::PAYLOAD_VERSION,
			'name'      => self::site_name(),
			'website'   => \home_url( '/' ),
			'generator' => 'ai-readiness-kit',
			'channels'  => array(
				'llms_txt'       => \home_url( '/llms.txt' ),
				'ai_txt'         => \home_url( '/ai.txt' ),
				'llms_policy'    => \home_url( '/.well-known/llms-policy.json' ),
				'markdown_views' => Context_Profile_Settings::is_module_enabled( 'markdown_views' ),
			),
		);
	}

	/**
	 * Read the operator's access stance from the Context Profile.
	 *
	 * @return array{allow_inference: bool, allow_training: bool}
	 */
	public static function policy_stance(): array {
		$profile = Context_Profile_Settings::get_profile();

		return array(
			'allow_inference' => (bool) ( $profile['policy_allow_inference'] ?? true ),
			'allow_training'  => (bool) ( $profile['policy_allow_training'] ?? false ),
		);
	}

	/**
	 * Site display name with a deterministic fallback for unnamed installs.
	 *
	 * Newlines are stripped defensively: the name is interpolated into the
	 * line-oriented ai.txt body, and an admin-set multi-line blogname must
	 * not be able to inject extra lines there.
	 */
	private static function site_name(): string {
		$name = trim( (string) \preg_replace( '/[\r\n]+/', ' ', (string) \get_option( 'blogname', '' ) ) );
		return '' !== $name ? $name : (string) \wp_parse_url( \home_url( '/' ), \PHP_URL_HOST );
	}

	/**
	 * Core sitemap index URL, or empty string when sitemaps are disabled.
	 */
	private static function sitemap_url(): string {
		if ( ! \function_exists( 'get_sitemap_url' ) ) {
			return '';
		}

		$url = \get_sitemap_url( 'index' );
		return \is_string( $url ) ? $url : '';
	}
}
