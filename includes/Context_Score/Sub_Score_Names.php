<?php
/**
 * Single source of truth for human-readable sub-score labels (#126).
 *
 * The breakdown shape (AgDR-0030) uses snake_case machine identifiers
 * (e.g. `md_conversion_quality`). Every surface that shows a sub-score to a
 * human — Site Health, the admin subtitle, future report exports — needs the
 * same translator-friendly label. Centralising the map here keeps the i18n
 * surface in one place and lets copy-bearing surfaces iterate
 * `Engine::WEIGHTS` instead of restating the inventory: adding an Nth
 * sub-score adds one `case` here and zero copy changes elsewhere.
 *
 * This class is WordPress-coupled (it calls `__()` and `wp_sprintf_l()`), so
 * it deliberately lives OUTSIDE `Engine`, which is documented as pure PHP with
 * no WordPress calls. Lifting the helper into `Engine` would have broken that
 * contract — see #126.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Context_Score;

\defined( 'ABSPATH' ) || exit;

/**
 * Maps sub-score machine names to translatable display labels.
 *
 * The label map is keyed by the same machine names as `Engine::WEIGHTS`.
 * Unknown names fall back to a `_` → space rewrite so a freshly-added weight
 * never renders as raw machine output even before its label `case` lands.
 */
final class Sub_Score_Names {

	/**
	 * Convert a sub-score machine name to a human-readable, translatable label.
	 *
	 * @param string $name Sub-score machine name (e.g. `md_conversion_quality`).
	 *
	 * @return string Translatable label.
	 */
	public static function label( string $name ): string {
		switch ( $name ) {
			case 'discoverability':
				return \__( 'discoverability', 'mokhai-agent-readiness-kit' );
			case 'content_readability':
				return \__( 'description coverage', 'mokhai-agent-readiness-kit' );
			case 'schema_coverage':
				return \__( 'schema coverage', 'mokhai-agent-readiness-kit' );
			case 'exposure_safety':
				return \__( 'exposure safety', 'mokhai-agent-readiness-kit' );
			case 'integration_health':
				return \__( 'integration health', 'mokhai-agent-readiness-kit' );
			case 'md_conversion_quality':
				return \__( 'Markdown conversion quality', 'mokhai-agent-readiness-kit' );
			case 'multi_channel_discovery':
				return \__( 'multi-channel discovery', 'mokhai-agent-readiness-kit' );
			default:
				return \str_replace( '_', ' ', $name );
		}
	}

	/**
	 * Labels for every sub-score, in `Engine::WEIGHTS` order.
	 *
	 * @return array<string, string> Machine name => translatable label.
	 */
	public static function all_labels(): array {
		$out = array();
		foreach ( \array_keys( Engine::WEIGHTS ) as $name ) {
			$out[ $name ] = self::label( $name );
		}
		return $out;
	}

	/**
	 * All sub-score labels joined into a locale-aware list
	 * (e.g. "a, b, and c"). Used by copy that enumerates the inventory.
	 *
	 * @return string Comma-and-joined list of every sub-score label.
	 */
	public static function joined_labels(): string {
		return \wp_sprintf_l( '%l', \array_values( self::all_labels() ) );
	}
}
