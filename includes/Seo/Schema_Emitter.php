<?php
/**
 * Gap-fill JSON-LD emitter.
 *
 * Renders the minimal set of schema.org JSON-LD nodes AI Readiness Kit knows how to
 * emit (`WebSite`, `Organization`, `WebPage`, `Article`) on `wp_head`, scoped
 * by the gap returned from `Plugin_Coverage::compute_gap()`. When any of the
 * supported SEO plugins is active (Yoast / Rank Math / AIOSEO) the gap is
 * empty by default — AI Readiness Kit emits nothing and the SEO plugin's schema
 * stays the single source of truth.
 *
 * Full design rationale: docs/agdr/AgDR-0033-seo-defer-gap-fill-emitter.md.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Seo;

\defined( 'ABSPATH' ) || exit;

use WPContext\Admin\Context_Profile_Settings;
use WPContext\Admin\Schema_Coordination_Detector;

/**
 * Stateless emitter. Calls into `Schema_Coordination_Detector` on every
 * request — detection is cheap (class_exists + options-array read) and the
 * absence of a cache means a freshly-activated SEO plugin starts being
 * deferred-to on the very next page-load.
 */
final class Schema_Emitter {

	/**
	 * Filter applied to the boolean "should we emit anything this request?"
	 * decision. Returning false suppresses all output — themes that already
	 * ship their own per-content schema can opt out without disabling the
	 * detector.
	 *
	 * @var string
	 */
	public const FILTER_EMIT_DECISION = 'agentready_schema_emit';

	/**
	 * Filter applied to the final JSON-LD node array before render. Allows
	 * site-level enrichment (e.g. adding `logo` to `Organization`) without
	 * forcing a callback to also rebuild the rest of the graph.
	 *
	 * @var string
	 */
	public const FILTER_NODES = 'agentready_schema_nodes';

	/**
	 * Wire `wp_head` at priority 10 (same priority WP core uses for theme-
	 * supplied output, late enough to read query context).
	 *
	 * Called once from `Main::register_hooks`.
	 */
	public static function register_hooks(): void {
		\add_action( 'wp_head', array( self::class, 'render' ), 10, 0 );
	}

	/**
	 * Render the gap-fill JSON-LD block, or nothing.
	 *
	 * Detects the active SEO plugin posture, then delegates to
	 * `render_for_posture()`. Splitting the resolve step from the render
	 * step lets unit tests drive the render path without depending on
	 * `class_exists()` state leaking across tests.
	 */
	public static function render(): void {
		$posture = Schema_Coordination_Detector::detect();
		$slug    = (string) ( $posture['posture'] ?? Schema_Coordination_Detector::POSTURE_NONE );
		self::render_for_posture( $slug );
	}

	/**
	 * Render the gap-fill JSON-LD block for an explicit posture slug.
	 *
	 * Resolution order:
	 *   1. Per-request opt-out filter (`agentready_schema_emit`).
	 *   2. Compute the gap (`baseline ∖ covered`) for the supplied slug.
	 *   3. Build a node for each type in the gap that applies to the
	 *      current query context (`Article` only on singular post,
	 *      `WebPage` on singular page / front-page, site-identity types
	 *      always when no SEO plugin).
	 *   4. Filter the node list and emit a single `<script type=
	 *      "application/ld+json">` block.
	 *
	 * @param string $posture_slug Slug returned by Schema_Coordination_Detector.
	 */
	public static function render_for_posture( string $posture_slug ): void {
		$profile = Context_Profile_Settings::get_profile();
		if ( empty( $profile['schema_emit_enabled'] ) ) {
			return;
		}

		// Hook name resolves to `agentready_schema_emit` — the constant is
		// prefixed; phpcs can't see through the constant ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$should_emit = \apply_filters( self::FILTER_EMIT_DECISION, true );
		if ( false === $should_emit ) {
			return;
		}

		$gap = Plugin_Coverage::compute_gap( $posture_slug );

		if ( array() === $gap ) {
			return;
		}

		$nodes = self::build_nodes( $gap );

		// Hook name resolves to `agentready_schema_nodes` — the constant is
		// prefixed; phpcs can't see through the constant ref.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		$filtered_nodes = \apply_filters( self::FILTER_NODES, $nodes, $gap, $posture_slug );
		$nodes          = \is_array( $filtered_nodes ) ? \array_values( $filtered_nodes ) : $nodes;

		if ( array() === $nodes ) {
			return;
		}

		self::print_jsonld( $nodes );
	}

	/**
	 * Build one JSON-LD node per gap type that applies to this request.
	 *
	 * Types whose preconditions fail (e.g. `Article` on a non-singular
	 * page) are silently skipped. The caller (`render`) handles the empty-
	 * result case.
	 *
	 * @param array<int, string> $gap Types to consider, baseline order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_nodes( array $gap ): array {
		$nodes = array();

		foreach ( $gap as $type ) {
			$node = self::build_node( $type );
			if ( null !== $node ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Build a single node by type. Returns null when the type doesn't apply
	 * to the current request (or when we don't know how to emit it).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function build_node( string $type ): ?array {
		switch ( $type ) {
			case 'WebSite':
				return self::build_website_node();
			case 'Organization':
				return self::build_organization_node();
			case 'WebPage':
				return self::build_webpage_node();
			case 'Article':
				return self::build_article_node();
			default:
				return null;
		}
	}

	/**
	 * Build a `WebSite` site-identity node.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_website_node(): array {
		$home = \home_url( '/' );
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'WebSite',
			'@id'        => $home . '#website',
			'url'        => $home,
			'name'       => \get_bloginfo( 'name' ),
			'inLanguage' => \get_bloginfo( 'language' ),
		);
	}

	/**
	 * Build an `Organization` site-identity node. Logo / sameAs are
	 * intentionally absent in v0.1 — Context Profile doesn't store them yet.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_organization_node(): array {
		$home = \home_url( '/' );
		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'@id'      => $home . '#organization',
			'name'     => \get_bloginfo( 'name' ),
			'url'      => $home,
		);
	}

	/**
	 * Build a `WebPage` node — applies on `is_front_page()`, or on any
	 * singular request whose CPT resolves to `WebPage` via
	 * `schema_type_for_cpt()` (`page` by default; custom CPTs default to
	 * `WebPage` too). Per-content emission is gated by Context Profile's
	 * `exposed_cpts` + `exposed_statuses`: a post that isn't exposed to
	 * agents shouldn't be exposed in schema either (#73 / AgDR-0034 /
	 * #104 / AgDR-0040).
	 *
	 * Returns null when neither precondition holds.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function build_webpage_node(): ?array {
		$is_front_page = \function_exists( 'is_front_page' ) && \is_front_page();
		$is_singular   = \function_exists( 'is_singular' ) && \is_singular();

		if ( ! $is_front_page && ! $is_singular ) {
			return null;
		}

		// Singular content additionally gates on exposed_cpts +
		// exposed_statuses AND on the resolved schema type being WebPage.
		// The front page in latest-posts mode has no queried WP_Post —
		// we treat it as site-identity-class (no per-content gate) so
		// the home URL gets a WebPage node even before any post is exposed.
		if ( $is_singular && ! $is_front_page ) {
			$post = \get_post();
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}
			if ( ! self::post_is_exposed( $post ) ) {
				return null;
			}
			if ( 'WebPage' !== self::schema_type_for_cpt( $post->ID, (string) $post->post_type ) ) {
				return null;
			}
		}

		$url   = self::current_url();
		$title = self::current_title();

		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebPage',
			'@id'      => $url . '#webpage',
			'url'      => $url,
			'name'     => $title,
			'isPartOf' => array( '@id' => \home_url( '/' ) . '#website' ),
		);
	}

	/**
	 * Build an `Article` node — applies on any singular request whose CPT
	 * resolves to `Article` via `schema_type_for_cpt()` (the built-in
	 * `post` CPT by default; subscribers to the
	 * `agentready_schema_type_for_cpt` filter may map custom CPTs to
	 * Article too). Per-content emission is gated by Context Profile's
	 * `exposed_cpts` + `exposed_statuses` (#73 / AgDR-0034 / #104 /
	 * AgDR-0040). `headline`, `datePublished`, and `dateModified` are
	 * derived from the current `WP_Post`; `mainEntityOfPage` points at
	 * the canonical permalink.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function build_article_node(): ?array {
		if ( ! \function_exists( 'is_singular' ) || ! \is_singular() ) {
			return null;
		}

		$post = \get_post();
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( ! self::post_is_exposed( $post ) ) {
			return null;
		}

		if ( 'Article' !== self::schema_type_for_cpt( $post->ID, (string) $post->post_type ) ) {
			return null;
		}

		$url = \get_permalink( $post );
		if ( ! \is_string( $url ) || '' === $url ) {
			$url = self::current_url();
		}

		return array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'@id'              => $url . '#article',
			'headline'         => \get_the_title( $post ),
			'datePublished'    => \get_post_time( 'c', true, $post ),
			'dateModified'     => \get_post_modified_time( 'c', true, $post ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'url'              => $url,
		);
	}

	/**
	 * Resolve the schema.org `@type` to emit for a given CPT.
	 *
	 * Defaults (closes #104):
	 *
	 *   - `post`        -> `Article`
	 *   - `page`        -> `WebPage`
	 *   - any other CPT -> `WebPage` (safe semantic generic for the
	 *                      gap-fill emitter; custom blog-shaped CPTs
	 *                      can opt into Article via the filter below)
	 *
	 * Subscribers to the `agentready_schema_type_for_cpt` filter may
	 * map custom CPTs to other @types (e.g. `Recipe`, `Course`,
	 * `Product`) or return `null`/`''` to suppress per-content emission
	 * entirely.
	 *
	 * **v0.1.1 contract:** only `'Article'` and `'WebPage'` return
	 * values are honored — any other string is treated as
	 * suppress-for-now. Full custom-`@type` support is queued for
	 * v0.1.2 with dedicated node builders.
	 *
	 * @param int    $post_id The post being rendered.
	 * @param string $cpt     The post's `post_type`.
	 * @return string|null    Either `'Article'`, `'WebPage'`, or `null`.
	 */
	private static function schema_type_for_cpt( int $post_id, string $cpt ): ?string {
		if ( 'post' === $cpt ) {
			$default = 'Article';
		} else {
			$default = 'WebPage';
		}

		/**
		 * Filter the schema.org `@type` emitted for a given CPT.
		 *
		 * Return `'Article'` or `'WebPage'` to swap the emitted type.
		 * Return `null` or `''` to suppress per-content emission for
		 * this CPT entirely (only site-identity nodes — WebSite +
		 * Organization — will emit).
		 *
		 * v0.1.1 honors only `Article` and `WebPage`. Other strings
		 * are treated as suppress until full custom-`@type` builders
		 * land in v0.1.2.
		 *
		 * @param string $default Plugin default for this CPT.
		 * @param string $cpt     The post's `post_type`.
		 * @param int    $post_id The post being rendered.
		 */
		$type = \apply_filters( 'agentready_schema_type_for_cpt', $default, $cpt, $post_id );

		if ( ! \is_string( $type ) || '' === $type ) {
			return null;
		}

		return $type;
	}

	/**
	 * Compare a post's `post_type` + `post_status` against the Context
	 * Profile allowlists. Returns true when both pass — the exposure
	 * model the rest of agentready honours (#73 AC #2).
	 */
	private static function post_is_exposed( \WP_Post $post ): bool {
		$profile  = Context_Profile_Settings::get_profile();
		$cpts     = isset( $profile['exposed_cpts'] ) && \is_array( $profile['exposed_cpts'] )
			? $profile['exposed_cpts']
			: array();
		$statuses = isset( $profile['exposed_statuses'] ) && \is_array( $profile['exposed_statuses'] )
			? $profile['exposed_statuses']
			: array();

		if ( ! \in_array( $post->post_type, $cpts, true ) ) {
			return false;
		}
		if ( ! \in_array( $post->post_status, $statuses, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Best-effort current URL — uses the global `wp` query when available,
	 * else falls back to `home_url($_SERVER['REQUEST_URI'])`.
	 */
	private static function current_url(): string {
		if ( \function_exists( 'is_singular' ) && \is_singular() ) {
			$id = \get_queried_object_id();
			if ( $id > 0 ) {
				$link = \get_permalink( $id );
				if ( \is_string( $link ) && '' !== $link ) {
					return $link;
				}
			}
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		return \home_url( $path );
	}

	/**
	 * Best-effort current page title — falls back to the site name when no
	 * queried object is available.
	 */
	private static function current_title(): string {
		if ( \function_exists( 'is_singular' ) && \is_singular() ) {
			$id = \get_queried_object_id();
			if ( $id > 0 ) {
				$title = \get_the_title( $id );
				if ( \is_string( $title ) && '' !== $title ) {
					return $title;
				}
			}
		}

		return (string) \get_bloginfo( 'name' );
	}

	/**
	 * Print the assembled nodes as a single `<script type="application/ld+json">`
	 * block. JSON_UNESCAPED_SLASHES keeps URLs readable; the JSON body is
	 * escaped via `esc_html()` so any operator-injected node content can't
	 * break out of the script tag.
	 *
	 * @param array<int, array<string, mixed>> $nodes Final node list.
	 */
	private static function print_jsonld( array $nodes ): void {
		$payload = count( $nodes ) === 1 ? $nodes[0] : $nodes;
		$json    = \wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );

		if ( ! \is_string( $json ) || '' === $json ) {
			return;
		}

		echo "\n<script type=\"application/ld+json\" data-emitted-by=\"agentready\">\n";
		echo \esc_html( $json );
		echo "\n</script>\n";
	}
}
