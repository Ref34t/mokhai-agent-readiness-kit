<?php
/**
 * Auto-listed entry generator for `/llms.txt`.
 *
 * Reads `exposed_cpts × exposed_statuses` from the Context Profile (AgDR-0002)
 * and produces a flat list of section DTOs keyed by post-type label. Phase A
 * scope: title + URL + description (from post excerpt). Phase #8 will plug
 * LLM-generated descriptions onto post-meta and have this class prefer the
 * stored meta when present — no Phase-A change needed beyond the
 * description-fallback hook below.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\LlmsTxt;

use WPContext\Admin\Context_Profile_Settings;
use WPContext\Markdown_Views\Url_Mapper;

\defined( 'ABSPATH' ) || exit;

/**
 * Resolve the auto-listed sections for `Composer::compose()`.
 *
 * The output shape matches the `sections` key of Composer's input array.
 * Each post type in `exposed_cpts` becomes one section; entries inside are
 * ordered by post date descending (newest first) — the same default
 * WordPress archives use, so the order is unsurprising to admins.
 */
final class Entry_Source {

	/**
	 * Per-post-type entry cap. Defence against a misconfigured Context
	 * Profile (admin enables a CPT with tens of thousands of entries) — the
	 * llms.txt spec frames the document as an *index*, not a complete dump.
	 * For sites that exceed this cap an admin notice (Phase C) will point
	 * at the `/llms-full.txt` v0.1.1 follow-up.
	 *
	 * @var int
	 */
	public const PER_CPT_CAP = 1000;

	/**
	 * Filter hook fired before a description fallback is applied. Phase #8
	 * (LLM-powered descriptions) subscribes to this filter to inject the
	 * cached post-meta description. Returning a non-empty string short-
	 * circuits the excerpt fallback.
	 *
	 * @var string
	 */
	public const DESCRIPTION_FILTER = 'agentready_llms_txt_entry_description';

	/**
	 * Build the auto-listed sections from the current Context Profile.
	 *
	 * @return array<int, array{label: string, entries: array<int, array{title: string, url: string, post_id: int, description?: string}>}>
	 */
	public static function get_sections(): array {
		$profile  = Context_Profile_Settings::get_profile();
		$cpts     = isset( $profile['exposed_cpts'] ) && is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();
		$statuses = isset( $profile['exposed_statuses'] ) && is_array( $profile['exposed_statuses'] )
			? $profile['exposed_statuses']
			: array( 'publish' );

		if ( array() === $cpts || array() === $statuses ) {
			return array();
		}

		$sections = array();

		foreach ( $cpts as $cpt ) {
			$cpt = (string) $cpt;
			if ( '' === $cpt ) {
				continue;
			}

			$entries = self::collect_entries_for_cpt( $cpt, $statuses );
			if ( array() === $entries ) {
				continue;
			}

			$sections[] = array(
				'label'   => self::label_for_post_type( $cpt ),
				'entries' => $entries,
			);
		}

		return $sections;
	}

	/**
	 * Run the WP_Query for one post type and project each post into an entry.
	 *
	 * Posts that fail `Context_Profile_Settings::is_url_exposable()` are
	 * skipped — they may match the CPT and status filter but still be denied
	 * by the password or noindex gates (AgDR-0012).
	 *
	 * @param string             $cpt      Post-type slug.
	 * @param array<int, string> $statuses Allowed statuses.
	 *
	 * @return array<int, array{title: string, url: string, post_id: int, description?: string}>
	 */
	private static function collect_entries_for_cpt( string $cpt, array $statuses ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $cpt,
				'post_status'            => $statuses,
				'posts_per_page'         => self::PER_CPT_CAP,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
			)
		);

		$entries = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$entry = self::entry_for_post( $post );
			if ( null === $entry ) {
				continue;
			}

			$entries[] = $entry;
		}

		\wp_reset_postdata();

		return $entries;
	}

	/**
	 * Project a single post into its `/llms.txt` entry, or null when the
	 * post is not exposable (password / noindex / status / CPT gate).
	 *
	 * This is the single source of truth for how one URL is rendered in
	 * `/llms.txt`: the same title / URL / description resolution the
	 * auto-listing loop uses. The AI Assistant Preview pane (#45) calls
	 * this directly so its "llms.txt entry" column matches the live file
	 * byte-for-byte rather than re-deriving the shape.
	 *
	 * The `post_id` key rides along so consumers that need to resolve the
	 * source post (the `/llms-full.txt` composition, #179) don't have to
	 * reverse-map the URL. `Composer::compose()` normalises entries down to
	 * title / url / description, so the extra key never reaches the
	 * published `/llms.txt` body.
	 *
	 * @param \WP_Post $post Post to project.
	 *
	 * @return array{title: string, url: string, post_id: int, description?: string}|null
	 */
	public static function entry_for_post( \WP_Post $post ): ?array {
		if ( ! Context_Profile_Settings::is_url_exposable( $post ) ) {
			return null;
		}

		$entry = array(
			'title'   => self::resolve_title( $post ),
			'url'     => self::resolve_url( $post ),
			'post_id' => (int) $post->ID,
		);

		$description = self::resolve_description( $post );
		if ( '' !== $description ) {
			$entry['description'] = $description;
		}

		return $entry;
	}

	/**
	 * Resolve the entry title. Falls back to `(no title)` so a saved-without-
	 * title post still appears as an entry rather than being dropped silently.
	 * Translation domain matches the plugin slug.
	 */
	private static function resolve_title( \WP_Post $post ): string {
		$title = \get_the_title( $post );
		if ( '' === trim( (string) $title ) ) {
			/* translators: placeholder shown in /llms.txt for posts saved without a title. */
			return \__( '(no title)', 'agentready-ai-readiness-kit' );
		}
		return (string) $title;
	}

	/**
	 * Resolve the entry URL.
	 *
	 * When Markdown Views is enabled in the Context Profile, the URL is
	 * transformed to the `.md` form (pretty permalinks) or `?format=md`
	 * (plain permalinks) so AI agents fetching from `/llms.txt` pull the
	 * clean Markdown body (~4–8 KB) instead of the full HTML page
	 * (~50–100 KB). The whole reason `/llms.txt` is the agent discovery
	 * surface is to point at agent-shaped content — `get_permalink()`
	 * alone defeats that. See `Ref34t/agentready#105`.
	 *
	 * When Markdown Views is disabled, returns the canonical permalink
	 * unchanged.
	 */
	private static function resolve_url( \WP_Post $post ): string {
		$url = \get_permalink( $post );
		if ( false === $url ) {
			return '';
		}
		$url = (string) $url;

		if ( ! self::markdown_views_enabled() ) {
			return $url;
		}

		return self::to_md_url( $url );
	}

	/**
	 * Whether the Markdown Views module is enabled in the Context Profile.
	 * Drives whether `/llms.txt` links point at the `.md` form (#105).
	 *
	 * Delegates to the canonical module-toggle accessor like every other
	 * call site, so this consumer can't drift from the
	 * "unknown module defaults to enabled" contract (#191).
	 */
	private static function markdown_views_enabled(): bool {
		return Context_Profile_Settings::is_module_enabled( 'markdown_views' );
	}

	/**
	 * Transform a canonical permalink to its Markdown View URL form.
	 *
	 * Two URL shapes, matching the rewrite contract in
	 * `Markdown_Views\Router`:
	 *
	 *   - **Pretty permalinks** (`/lessons/foo/`): strip the trailing
	 *     slash, append `.md`. Result `/lessons/foo.md`.
	 *   - **Plain permalinks** (`/?p=42`): append `&format=md` to the
	 *     existing query string. Result `/?p=42&format=md`.
	 *
	 * Both shapes resolve to the same `Handler::dispatch()` code path
	 * once WordPress parses the request — the rewrite handles the path
	 * form, the query var handles the query form.
	 *
	 * **Edge case — a pretty permalink carrying a query string.** The
	 * branch is chosen by *presence of a query string*, not by the site's
	 * permalink mode. So a pretty URL that already carries a query string
	 * (e.g. `/lessons/foo/?ver=2`) takes the query branch and becomes
	 * `/lessons/foo/?ver=2&format=md` — it does NOT get the `.md` suffix.
	 * That's intentional: `?format=md` content-negotiation reaches the
	 * same Markdown response regardless of permalink mode, whereas
	 * appending `.md` to a path that still has a query string would
	 * produce a malformed URL. Pretty permalinks only get the `.md` form
	 * when their permalink is query-free (the common case — WordPress
	 * pretty permalinks don't carry a query string unless something
	 * downstream added one).
	 *
	 * Idempotent: a URL already in `.md` or `format=md` shape is
	 * returned unchanged.
	 *
	 * Delegates to the canonical {@see Url_Mapper::to_md_url()} so `/llms.txt`
	 * links and agent-surface advertising (#178) never drift apart.
	 */
	private static function to_md_url( string $url ): string {
		return Url_Mapper::to_md_url( $url );
	}

	/**
	 * Resolve a one-line description for the entry.
	 *
	 * Source priority:
	 *   1. The `agentready_llms_txt_entry_description` filter — Phase #8
	 *      plugs LLM-generated cached descriptions here.
	 *   2. The post excerpt (if explicitly set by the editor).
	 *   3. Empty string — the entry is rendered as a bare `- [title](url)`
	 *      line. We do NOT fall back to auto-generated content excerpts
	 *      because they leak HTML formatting and feel uncurated.
	 *
	 * Collapses to one line; truncates at 160 chars (one Twitter card worth
	 * of preview text, plenty for an index entry).
	 */
	private static function resolve_description( \WP_Post $post ): string {
		/**
		 * Filter the entry description for a post before the excerpt fallback.
		 *
		 * @param string   $description Empty by default; returning non-empty
		 *                              short-circuits the excerpt fallback.
		 * @param \WP_Post $post        The post being indexed.
		 */
		// Hook name resolves to `agentready_llms_txt_entry_description` —
		// the constant is prefixed; phpcs can't see through the constant ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$filtered = \apply_filters( self::DESCRIPTION_FILTER, '', $post );
		$filtered = is_string( $filtered ) ? trim( $filtered ) : '';
		if ( '' !== $filtered ) {
			return self::normalise_description( $filtered );
		}

		// `get_post_field()` reads the raw column value (no auto-excerpt
		// generation from `post_content`, which is what `get_the_excerpt()`
		// would do). Phase A only surfaces excerpts the editor set
		// deliberately — auto-extracted excerpts leak HTML and feel
		// uncurated in the index.
		$excerpt = trim( (string) \get_post_field( 'post_excerpt', $post, 'raw' ) );
		if ( '' !== $excerpt ) {
			return self::normalise_description( $excerpt );
		}

		return '';
	}

	/**
	 * Collapse whitespace, strip tags, truncate to 160 chars.
	 */
	private static function normalise_description( string $text ): string {
		$text = \wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = is_string( $text ) ? trim( $text ) : '';

		if ( strlen( $text ) > 160 ) {
			$text = rtrim( substr( $text, 0, 157 ) ) . '...';
		}

		return $text;
	}

	/**
	 * Resolve the section label for a post type.
	 *
	 * Uses the registered plural label when available so custom post types
	 * appear under their human-readable name. Falls back to the slug for
	 * post types that haven't registered labels (rare but possible).
	 */
	private static function label_for_post_type( string $cpt ): string {
		$object = \get_post_type_object( $cpt );
		if ( $object && isset( $object->labels->name ) && '' !== (string) $object->labels->name ) {
			return (string) $object->labels->name;
		}
		return $cpt;
	}
}
