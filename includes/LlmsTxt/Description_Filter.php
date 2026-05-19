<?php
/**
 * Read-side subscriber for `Entry_Source::DESCRIPTION_FILTER`.
 *
 * Plugs cached LLM-generated / admin-overridden descriptions into the
 * `/llms.txt` composer. Stateless — every call is a single
 * `get_post_meta` round-trip via `Description_Orchestrator`.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

\defined( 'ABSPATH' ) || exit;

/**
 * Filter subscriber. See AgDR-0027 § "Read-side filter resolution order".
 */
final class Description_Filter {

	/**
	 * Wire the filter. Called from `Main::register_hooks()`.
	 */
	public static function register_hooks(): void {
		\add_filter(
			Entry_Source::DESCRIPTION_FILTER,
			array( self::class, 'filter_description' ),
			10,
			2
		);
	}

	/**
	 * Filter callback. Returns the cached description when one exists,
	 * else passes through the previous value (default '' → Entry_Source
	 * falls back to the post excerpt).
	 *
	 * Other plugins or theme code could be subscribing to the same
	 * filter; we honour the existing `$description` argument by leaving
	 * non-empty prior values alone. This keeps the filter contract
	 * additive — a site that already returns a custom description from
	 * a different subscriber doesn't get clobbered.
	 *
	 * @param string   $description Current resolved description (empty
	 *                              by default, possibly non-empty if
	 *                              another subscriber ran first).
	 * @param \WP_Post $post        Post being indexed.
	 */
	public static function filter_description( $description, $post ): string {
		if ( \is_string( $description ) && '' !== \trim( $description ) ) {
			return $description;
		}

		if ( ! $post instanceof \WP_Post ) {
			return \is_string( $description ) ? $description : '';
		}

		$cached = Description_Orchestrator::get_cached_description( (int) $post->ID );
		if ( '' !== $cached ) {
			return $cached;
		}

		return \is_string( $description ) ? $description : '';
	}
}
