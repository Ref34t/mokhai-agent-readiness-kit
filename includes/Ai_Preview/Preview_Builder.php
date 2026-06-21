<?php
/**
 * Aggregator for the AI Assistant Preview pane (#45 / AgDR-0046).
 *
 * Given a post, assembles the three "what an assistant sees" panes plus the
 * cached Sample AI Summary into a single payload for the REST controller:
 *
 *   - raw_html   — `the_content` filter output (what bots parse WITHOUT the
 *                  plugin), truncated for display
 *   - markdown   — the Markdown View (what bots get WITH the plugin), proxied
 *                  through `Markdown_Views\Service` so the #6 no-hallucination
 *                  guard applies; carries the same visibility verdict the MV
 *                  preview endpoint returns
 *   - llms_entry — the single `/llms.txt` line for this URL, from the live
 *                  `Entry_Source` so it matches the published file
 *   - summary    — the cached Sample AI Summary, or null
 *
 * Read-only and side-effect-free.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Ai_Preview;

use WPContext\Admin\Context_Profile_Settings;
use WPContext\LlmsTxt\Description_Orchestrator;
use WPContext\LlmsTxt\Entry_Source;
use WPContext\Markdown_Views\Service as Markdown_Service;

\defined( 'ABSPATH' ) || exit;

/**
 * Pure-ish aggregator. The only WordPress reads are `the_content`,
 * the Context Profile, the MV cache, and post-meta — no writes.
 */
final class Preview_Builder {

	/**
	 * Raw-HTML display cap, in characters. The pane truncates + scroll-
	 * collapses; the cap keeps the REST payload bounded for large pages.
	 * `full_length` is reported so the UI can show "showing X of Y".
	 *
	 * @var int
	 */
	public const RAW_HTML_CAP = 20_000;

	/**
	 * Build the full preview payload for a post.
	 *
	 * @param \WP_Post $post Post being previewed.
	 *
	 * @return array<string, mixed>
	 */
	public static function build( \WP_Post $post ): array {
		return array(
			'post'       => self::post_summary( $post ),
			'raw_html'   => self::raw_html( $post ),
			'markdown'   => self::markdown_for( $post ),
			'llms_entry' => self::llms_entry( $post ),
			'summary'    => self::summary( (int) $post->ID ),
		);
	}

	/**
	 * Identity block for the selected post.
	 *
	 * @return array{id: int, title: string, type: string, url: string}
	 */
	public static function post_summary( \WP_Post $post ): array {
		$permalink = \get_permalink( $post );

		return array(
			'id'    => (int) $post->ID,
			'title' => self::display_title( $post ),
			'type'  => (string) $post->post_type,
			'url'   => false === $permalink ? '' : (string) $permalink,
		);
	}

	/**
	 * Pane 1 — the rendered post content as bots parse it WITHOUT the
	 * plugin. `the_content` is the main content block; we deliberately do
	 * not loopback-fetch the full page (theme chrome, auth cookies, edge
	 * caches) — see AgDR-0046 § "Raw HTML source".
	 *
	 * @return array{html: string, full_length: int, truncated: bool}
	 */
	private static function raw_html( \WP_Post $post ): array {
		// `the_content` filters expect the loop's globals in places; this is
		// an admin read of one specific post, so we apply the filter to the
		// stored content directly. Shortcodes / blocks render as they would
		// on the front end. `the_content` is a WordPress core hook we are
		// invoking (not registering), so the prefix sniff is a false positive.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$rendered = (string) \apply_filters( 'the_content', (string) $post->post_content );

		$full_length = \strlen( $rendered );
		$truncated   = $full_length > self::RAW_HTML_CAP;
		$html        = $truncated ? \substr( $rendered, 0, self::RAW_HTML_CAP ) : $rendered;

		return array(
			'html'        => $html,
			'full_length' => $full_length,
			'truncated'   => $truncated,
		);
	}

	/**
	 * Pane 2 — the Markdown View as bots get it WITH the plugin. Mirrors the
	 * gating in `Markdown_Views\Rest_Controller::handle_preview`: module
	 * toggle first, exposure reason second, then the cached/regenerated MD.
	 * The #6 no-hallucination guard runs inside `get_markdown_for_post`.
	 *
	 * Public so the `POST /summary` route resolves the summary input through
	 * the exact same gating as the displayed pane.
	 *
	 * @return array<string, mixed>
	 */
	public static function markdown_for( \WP_Post $post ): array {
		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return array(
				'markdown'   => '',
				'visibility' => array(
					'verdict' => 'module_disabled',
					'reason'  => 'markdown_views',
				),
			);
		}

		$reason = Context_Profile_Settings::get_exposure_reason( $post );
		if ( null !== $reason ) {
			return array(
				'markdown'   => '',
				'visibility' => array(
					'verdict' => 'not_exposable',
					'reason'  => $reason,
				),
			);
		}

		$result = Markdown_Service::get_markdown_for_post( $post );

		if ( \is_wp_error( $result ) ) {
			return array(
				'markdown'   => '',
				'visibility' => array(
					'verdict' => 'error',
					'reason'  => (string) $result->get_error_code(),
				),
			);
		}

		return array(
			'markdown'   => (string) $result,
			'visibility' => array(
				'verdict' => 'exposable',
				'reason'  => null,
			),
		);
	}

	/**
	 * Pane 3 — the single `/llms.txt` line for this URL, from the live
	 * `Entry_Source` so it matches the published file. `present` is false
	 * when the post is not exposable (no line in the file).
	 *
	 * @return array{present: bool, line: string, description_source: string}
	 */
	private static function llms_entry( \WP_Post $post ): array {
		$entry = Entry_Source::entry_for_post( $post );

		if ( null === $entry ) {
			return array(
				'present'            => false,
				'line'               => '',
				'description_source' => 'none',
			);
		}

		return array(
			'present'            => true,
			'line'               => self::format_entry_line( $entry ),
			'description_source' => self::classify_description_source( $post, $entry ),
		);
	}

	/**
	 * Render the entry line in the documented `/llms.txt` shape
	 * (`Composer` §18-19): `- [Title](url): Description` or `- [Title](url)`.
	 *
	 * @param array{title: string, url: string, description?: string} $entry
	 */
	private static function format_entry_line( array $entry ): string {
		$line = '- [' . $entry['title'] . '](' . $entry['url'] . ')';

		if ( isset( $entry['description'] ) && '' !== $entry['description'] ) {
			$line .= ': ' . $entry['description'];
		}

		return $line;
	}

	/**
	 * Label where the entry's description came from, for the UI badge.
	 *
	 * @param array{title: string, url: string, description?: string} $entry
	 */
	private static function classify_description_source( \WP_Post $post, array $entry ): string {
		if ( ! isset( $entry['description'] ) || '' === $entry['description'] ) {
			return 'none';
		}

		if ( '' !== Description_Orchestrator::get_cached_description( (int) $post->ID ) ) {
			return 'llm';
		}

		return 'excerpt';
	}

	/**
	 * Pane 4 (optional) — the cached Sample AI Summary, or null when none
	 * has been generated yet.
	 *
	 * @return array{text: string, generated_at: string, source: 'llm'}|null
	 */
	private static function summary( int $post_id ): ?array {
		return Summary_Generator::get_cached( $post_id );
	}

	/**
	 * Display title with a fallback so an untitled post still renders.
	 */
	private static function display_title( \WP_Post $post ): string {
		$title = \get_the_title( $post );
		if ( '' === \trim( (string) $title ) ) {
			/* translators: shown in the AI preview dropdown for posts saved without a title. */
			return \__( '(no title)', 'mokhai-agent-readiness-kit' );
		}
		return (string) $title;
	}
}
