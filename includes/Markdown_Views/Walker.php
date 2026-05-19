<?php
/**
 * Deterministic HTML→Markdown walker.
 *
 * Pure function: `Walker::convert( string $html ): string`. No WordPress
 * dependency, no network calls, no filesystem access (beyond the WP filter
 * hook for handler extension). Same input always produces the same output —
 * the property that makes the cache (AgDR-0011) safe.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

// PHP's DOM API exposes camelCase property names ($node->childNodes,
// $node->nodeName, $node->textContent). The WPCS snake_case sniff flags every
// read of these; there's no way to rename the upstream API, so we disable the
// sniff for this file and keep the rest of WPCS active.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * DOM-walking HTML→MD converter per AgDR-0010.
 *
 * Approach: DOMDocument parses the input, the walker traverses the tree
 * depth-first, and per-element handlers emit Markdown fragments. Block
 * elements bracket their output with paragraph-separators; inline elements
 * emit inline-MD constructs. WP-specific markup (Gutenberg block comments,
 * gallery/caption shortcode residue, oembed wrappers) is handled before
 * the main walk so the standard handlers see clean semantic HTML.
 *
 * Bumping `WALKER_VERSION` invalidates every cached row at the next read
 * (Service compares stored vs current; mismatch → regenerate) — used when
 * the output of this class changes for the same input.
 */
final class Walker {

	/**
	 * Output-version tag stamped on each cache row. Bump when a change to
	 * this class would produce different MD for the same HTML.
	 *
	 * Bumped from '1' to '2' for AgDR-0017: the quality-score addition
	 * does not change MD output, but extending the cache schema with
	 * `quality_score` and `signals` columns means pre-bump rows have
	 * NULL in those fields. Invalidating lazily forces a rewrite that
	 * populates them.
	 *
	 * @var string
	 */
	public const WALKER_VERSION = '3';

	/**
	 * Hard upper bound on input size in bytes. Defends against pathological
	 * input that would OOM the DOMDocument parser. ~5MB covers any
	 * realistic post including pages full of embedded SVG.
	 *
	 * @var int
	 */
	public const MAX_INPUT_BYTES = 5_242_880;

	/**
	 * Hard upper bound on recursion depth during the walk. Defends against
	 * pathological input with deeply nested elements.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 64;

	/**
	 * Per-signal score weights summing to 100. See AgDR-0017. The shape
	 * is the durable contract; values here are tunable constants.
	 *
	 * @var array<string, int>
	 */
	private const QUALITY_WEIGHTS = array(
		'tag_strip_rate'            => 25,
		'orphan_inline_style_rate'  => 20,
		'table_fragment_rate'       => 15,
		'deep_div_nesting_rate'     => 10,
		'image_only_paragraph_rate' => 10,
		'empty_line_run_rate'       => 10,
		'shortcode_residue_rate'    => 10,
	);

	/**
	 * Source-element depth above which a `<div>` is considered "deeply
	 * nested" for the deep_div_nesting signal. Page-builders routinely
	 * produce 6–10 levels; classic-editor content rarely exceeds 3.
	 *
	 * @var int
	 */
	private const DEEP_DIV_DEPTH_THRESHOLD = 4;

	/**
	 * Style-attr count per kB of MD output at which the orphan-inline-style
	 * rate hits 1.0. Calibrated to typical Elementor output.
	 *
	 * @var float
	 */
	private const ORPHAN_STYLE_NORMALISATION = 10.0;

	/**
	 * Per-convert counter bag. Reset at the start of every `convert()`
	 * call; never read after `convert()` returns. Walker is not
	 * reentrant (no caller invokes `convert()` from inside `convert()`),
	 * so the static lifetime is safe.
	 *
	 * @var array<string, int>
	 */
	private static $counters = array();

	/**
	 * Convert an HTML string to Markdown.
	 *
	 * Returns an empty `Conversion_Result` for empty input or input that
	 * exceeds `MAX_INPUT_BYTES`. Never throws — the caller is the cache
	 * write path and must produce a result.
	 */
	public static function convert( string $html ): Conversion_Result {
		self::reset_counters();

		if ( '' === $html ) {
			return self::empty_result();
		}

		if ( \strlen( $html ) > self::MAX_INPUT_BYTES ) {
			return self::empty_result();
		}

		$prepared = self::preprocess( $html );

		if ( '' === $prepared ) {
			return self::empty_result();
		}

		$dom = self::load_dom( $prepared );

		if ( null === $dom ) {
			return self::empty_result();
		}

		$body = self::body_node( $dom );

		if ( null === $body ) {
			return self::empty_result();
		}

		$md = self::postprocess( self::render_children( $body, 0 ) );

		$signals = self::derive_signals( $md );
		$score   = self::compute_score( $signals );

		return new Conversion_Result( $md, $score, $signals );
	}

	/**
	 * Reset per-call counters. Each entry is documented inline at the
	 * point where it's incremented.
	 */
	private static function reset_counters(): void {
		self::$counters = array(
			'tag_total'            => 0, // every DOMElement we visit
			'tag_empty_output'     => 0, // elements whose handler emitted nothing
			'orphan_inline_style'  => 0, // elements carrying inline style or class attributes
			'div_total'            => 0, // every div visited
			'deep_div'             => 0, // divs at depth above the configured threshold
			'paragraph_total'      => 0, // every paragraph visited
			'image_only_paragraph' => 0, // paragraphs containing only an image
			'table_total'          => 0, // every table visited
			'table_fragment'       => 0, // tables with missing header or mismatched columns
		);
	}

	/**
	 * Zero-score, zero-signal result for the degenerate-input cases
	 * (empty / oversize / unparseable). 100 means "no issues" — there
	 * are literally no signals firing on empty input.
	 */
	private static function empty_result(): Conversion_Result {
		return new Conversion_Result( '', 100, self::derive_signals( '' ) );
	}

	/**
	 * Remove WP-specific markup that confuses generic semantic handlers
	 * before the DOM parse runs.
	 *
	 * - Gutenberg block-delimiter comments (`<!-- wp:* -->` / `<!-- /wp:* -->`)
	 *   carry no Markdown signal and would otherwise show as comment nodes
	 *   in the DOM tree.
	 * - Standalone shortcode residue (`[gallery]`, `[caption ...]…[/caption]`)
	 *   that survived `do_shortcode` is stripped — the deterministic pass
	 *   cannot expand shortcodes and a literal `[gallery ids="1,2,3"]` in MD
	 *   is noise.
	 */
	private static function preprocess( string $html ): string {
		// Strip Gutenberg block-delimiter comments.
		$html = (string) \preg_replace( '/<!--\s*\/?wp:[^>]*-->/u', '', $html );

		// Strip caption shortcodes but KEEP the inner caption text. The
		// caption text usually surrounds an `<img>` and reads as a figure
		// caption — the figure handler picks it up.
		$html = (string) \preg_replace_callback(
			'/\[caption[^\]]*\](.*?)\[\/caption\]/su',
			static function ( array $matches ): string {
				return $matches[1];
			},
			$html
		);

		// Strip standalone gallery shortcodes — no semantic equivalent in MD
		// without expanding to <img> tags, which is the renderer's job not
		// the walker's.
		$html = (string) \preg_replace( '/\[gallery[^\]]*\]/u', '', $html );

		return $html;
	}

	/**
	 * Load HTML into a DOMDocument with flags tuned to avoid auto-wrapping
	 * the fragment in `<html><body>` and to suppress libxml warnings on
	 * HTML5 elements.
	 */
	private static function load_dom( string $html ): ?\DOMDocument {
		$prev = \libxml_use_internal_errors( true );

		$dom = new \DOMDocument( '1.0', 'UTF-8' );

		// Force UTF-8 interpretation: DOMDocument defaults to ISO-8859-1
		// when no charset is declared. The XML processing instruction is
		// the most reliable hint across PHP versions.
		$wrapped = '<?xml encoding="utf-8" ?><wrapper>' . $html . '</wrapper>';

		$loaded = $dom->loadHTML(
			$wrapped,
			\LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD
		);

		\libxml_clear_errors();
		\libxml_use_internal_errors( $prev );

		if ( false === $loaded ) {
			return null;
		}

		return $dom;
	}

	/**
	 * Resolve the synthetic `<wrapper>` element introduced by `load_dom()`.
	 * Returns null if the document is structurally unexpected.
	 */
	private static function body_node( \DOMDocument $dom ): ?\DOMNode {
		$wrapper = $dom->getElementsByTagName( 'wrapper' )->item( 0 );
		return $wrapper instanceof \DOMNode ? $wrapper : null;
	}

	/**
	 * Trim trailing/leading whitespace and collapse runs of >2 blank lines
	 * down to exactly 2 — produces MD that diffs cleanly across runs.
	 */
	private static function postprocess( string $md ): string {
		$md = (string) \preg_replace( "/\r\n|\r/", "\n", $md );
		$md = (string) \preg_replace( "/\n{3,}/", "\n\n", $md );
		return \trim( $md ) . "\n";
	}

	/**
	 * Render every child of the given node, concatenating block-level
	 * output with paragraph breaks and inline output with no separator.
	 */
	private static function render_children( \DOMNode $node, int $depth ): string {
		if ( $depth > self::MAX_DEPTH ) {
			return '';
		}

		$out = '';

		foreach ( $node->childNodes as $child ) {
			$out .= self::render_node( $child, $depth + 1 );
		}

		return $out;
	}

	/**
	 * Dispatch a single node to its handler. Signal accumulation hangs
	 * off this central point: every visited element increments
	 * `tag_total`, has its style/class attrs measured, and contributes
	 * to depth-aware `<div>` counts. Per-handler counters (paragraphs,
	 * tables) fire inside their own render methods.
	 */
	private static function render_node( \DOMNode $node, int $depth ): string {
		if ( $node instanceof \DOMText ) {
			return self::render_text( $node );
		}

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		++self::$counters['tag_total'];

		// Count orphan inline styles / classes — MD has no concept of
		// either, so every occurrence is "lost" to the output.
		if ( $node->hasAttribute( 'style' ) || $node->hasAttribute( 'class' ) ) {
			++self::$counters['orphan_inline_style'];
		}

		$tag = \strtolower( $node->nodeName );

		if ( 'div' === $tag ) {
			++self::$counters['div_total'];
			if ( $depth > self::DEEP_DIV_DEPTH_THRESHOLD ) {
				++self::$counters['deep_div'];
			}
		}

		$result = self::dispatch( $node, $tag, $depth );

		if ( '' === $result ) {
			++self::$counters['tag_empty_output'];
		}

		return $result;
	}

	/**
	 * The original render_node switch lives here; render_node wraps it
	 * with counter accumulation. Keeping them separate keeps the
	 * per-tag dispatch readable.
	 *
	 * @param \DOMElement $node
	 */
	private static function dispatch( \DOMElement $node, string $tag, int $depth ): string {
		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return self::render_heading( $node, $tag, $depth );

			case 'p':
				return self::render_paragraph( $node, $depth );

			case 'br':
				return "  \n";

			case 'hr':
				return "\n\n---\n\n";

			case 'em':
			case 'i':
				return '*' . self::render_children( $node, $depth ) . '*';

			case 'strong':
			case 'b':
				return '**' . self::render_children( $node, $depth ) . '**';

			case 'code':
				return self::render_inline_code( $node );

			case 'pre':
				return self::render_pre( $node );

			case 'a':
				return self::render_link( $node, $depth );

			case 'img':
				return self::render_image( $node );

			case 'ul':
			case 'ol':
				return self::render_list( $node, $tag, $depth, 0 );

			case 'blockquote':
				return self::render_blockquote( $node, $depth );

			case 'figure':
				return self::render_figure( $node, $depth );

			case 'figcaption':
				// Handled inline by render_figure; standalone figcaptions
				// fall through as plain text.
				return self::render_children( $node, $depth );

			case 'table':
				return self::render_table( $node );

			// Structural wrappers — descend without emitting markup.
			case 'div':
			case 'section':
			case 'article':
			case 'main':
			case 'header':
			case 'footer':
			case 'aside':
			case 'span':
			case 'wrapper':
				return self::render_children( $node, $depth );

			// Anything we don't recognise: descend but emit no markup.
			default:
				return self::render_children( $node, $depth );
		}
	}

	private static function render_text( \DOMText $node ): string {
		$text = $node->nodeValue ?? '';

		if ( '' === \trim( $text ) ) {
			// Whitespace-only text node between two block elements is layout
			// noise from pretty-printed HTML — drop it. The same node between
			// two inline elements is a real separator and is preserved.
			return self::is_between_block_siblings( $node ) ? '' : ' ';
		}

		return (string) \preg_replace( '/\s+/u', ' ', $text );
	}

	private static function is_between_block_siblings( \DOMText $node ): bool {
		$prev_block = null === $node->previousSibling
			|| self::is_block_element( $node->previousSibling );
		$next_block = null === $node->nextSibling
			|| self::is_block_element( $node->nextSibling );

		return $prev_block && $next_block;
	}

	private static function is_block_element( \DOMNode $node ): bool {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		$block_tags = array(
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'p',
			'div',
			'section',
			'article',
			'main',
			'header',
			'footer',
			'aside',
			'ul',
			'ol',
			'li',
			'blockquote',
			'pre',
			'hr',
			'table',
			'thead',
			'tbody',
			'tr',
			'figure',
			'figcaption',
		);

		return \in_array( \strtolower( $node->nodeName ), $block_tags, true );
	}

	private static function render_heading( \DOMElement $node, string $tag, int $depth ): string {
		$level = (int) \substr( $tag, 1 );
		$inner = \trim( self::render_children( $node, $depth ) );

		if ( '' === $inner ) {
			return '';
		}

		return "\n\n" . \str_repeat( '#', $level ) . ' ' . $inner . "\n\n";
	}

	private static function render_paragraph( \DOMElement $node, int $depth ): string {
		++self::$counters['paragraph_total'];

		$image_only = self::contains_only_image( $node );
		if ( $image_only ) {
			++self::$counters['image_only_paragraph'];
		}

		$inner = \trim( self::render_children( $node, $depth ) );

		if ( '' === $inner ) {
			return '';
		}

		return "\n\n" . $inner . "\n\n";
	}

	private static function contains_only_image( \DOMElement $node ): bool {
		$image_count = 0;

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMText && '' === \trim( (string) $child->nodeValue ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'img' === \strtolower( $child->nodeName ) ) {
				++$image_count;
				continue;
			}

			return false;
		}

		return 1 === $image_count;
	}

	private static function render_inline_code( \DOMElement $node ): string {
		// `<pre><code>` is handled by render_pre; standalone <code> is inline.
		if ( $node->parentNode instanceof \DOMElement
			&& 'pre' === \strtolower( $node->parentNode->nodeName )
		) {
			return $node->textContent;
		}

		return self::fence_inline_code( $node->textContent );
	}

	/**
	 * Pick a backtick fence long enough not to collide with backticks inside
	 * `$content`, per CommonMark §6.3. The fence length is `max_run + 1`
	 * where `max_run` is the longest run of consecutive backticks inside
	 * the content. When the content begins or ends with a backtick, a
	 * single padding space is inserted on the inside of each fence to
	 * disambiguate (#38 / CommonMark "code spans" rule).
	 *
	 * Empty content keeps the single-backtick fence — there's nothing to
	 * collide with.
	 */
	private static function fence_inline_code( string $content ): string {
		if ( '' === $content ) {
			return '``';
		}

		$max_run = 0;
		if ( \preg_match_all( '/`+/', $content, $matches ) ) {
			foreach ( $matches[0] as $run ) {
				$len = \strlen( $run );
				if ( $len > $max_run ) {
					$max_run = $len;
				}
			}
		}

		$fence = \str_repeat( '`', $max_run + 1 );
		$pad   = ( 0 === \strncmp( $content, '`', 1 ) || '`' === \substr( $content, -1 ) )
			? ' '
			: '';

		return $fence . $pad . $content . $pad . $fence;
	}

	private static function render_pre( \DOMElement $node ): string {
		// Extract language hint from `<code class="language-xxx">` if present.
		$lang = '';
		$code = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'code' === \strtolower( $child->nodeName ) ) {
				$class = $child->getAttribute( 'class' );
				if ( \preg_match( '/language-([\w-]+)/', $class, $matches ) ) {
					$lang = $matches[1];
				}
				$code = $child->textContent;
				break;
			}
		}

		if ( '' === $code ) {
			$code = $node->textContent;
		}

		return "\n\n```" . $lang . "\n" . \rtrim( $code, "\n" ) . "\n```\n\n";
	}

	private static function render_link( \DOMElement $node, int $depth ): string {
		$href  = $node->getAttribute( 'href' );
		$inner = self::render_children( $node, $depth );

		if ( '' === $href ) {
			return $inner;
		}

		if ( '' === \trim( $inner ) ) {
			$inner = $href;
		}

		return '[' . self::escape_link_text( $inner ) . '](' . self::escape_link_url( $href ) . ')';
	}

	private static function render_image( \DOMElement $node ): string {
		$src = $node->getAttribute( 'src' );
		$alt = $node->getAttribute( 'alt' );

		if ( '' === $src ) {
			return '';
		}

		return '![' . self::escape_link_text( $alt ) . '](' . self::escape_link_url( $src ) . ')';
	}

	/**
	 * Backslash-escape `]` (and `[`) in the text segment of a Markdown link
	 * / image construct so an unescaped bracket can't terminate the link
	 * early (#37). CommonMark §6.6 specifies that `[`, `]`, and `\` are
	 * the link-text delimiters that need escaping; we escape all three for
	 * symmetry with `escape_link_url`.
	 */
	private static function escape_link_text( string $text ): string {
		return \strtr(
			$text,
			array(
				'\\' => '\\\\',
				'['  => '\\[',
				']'  => '\\]',
			)
		);
	}

	/**
	 * Backslash-escape `)` in the URL segment of a Markdown link / image
	 * construct so a literal `)` can't terminate the URL early (#37).
	 * CommonMark allows percent-encoding too — backslash-escaping is the
	 * narrower change and keeps the URL human-readable. Backslashes
	 * themselves are escaped first so we don't double-escape paths that
	 * already contain `\`.
	 */
	private static function escape_link_url( string $url ): string {
		return \strtr(
			$url,
			array(
				'\\' => '\\\\',
				')'  => '\\)',
			)
		);
	}

	private static function render_list( \DOMElement $node, string $tag, int $depth, int $indent ): string {
		$out     = '';
		$index   = 1;
		$ordered = 'ol' === $tag;

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			if ( 'li' !== \strtolower( $child->nodeName ) ) {
				continue;
			}

			$marker = $ordered ? ( $index . '.' ) : '-';
			$prefix = \str_repeat( '  ', $indent ) . $marker . ' ';
			$inner  = self::render_list_item( $child, $depth, $indent );
			$out   .= $prefix . $inner . "\n";
			++$index;
		}

		// Bracket the list with paragraph separators only at the top level
		// (depth-1 walks of nested lists are inlined inside their parent li).
		if ( 0 === $indent ) {
			return "\n\n" . \rtrim( $out, "\n" ) . "\n\n";
		}

		return $out;
	}

	private static function render_list_item( \DOMElement $node, int $depth, int $indent ): string {
		$inline = '';
		$nested = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement
				&& \in_array( \strtolower( $child->nodeName ), array( 'ul', 'ol' ), true )
			) {
				$nested .= "\n" . self::render_list( $child, \strtolower( $child->nodeName ), $depth, $indent + 1 );
				continue;
			}

			$inline .= self::render_node( $child, $depth + 1 );
		}

		$item = \trim( \preg_replace( '/\s+/u', ' ', $inline ) ?? '' );

		if ( '' !== $nested ) {
			$item .= \rtrim( $nested, "\n" );
		}

		return $item;
	}

	private static function render_blockquote( \DOMElement $node, int $depth ): string {
		$inner = \trim( self::render_children( $node, $depth ) );

		if ( '' === $inner ) {
			return '';
		}

		// Collapse 3+ newlines to the standard 2-newline paragraph gap before
		// quoting so multi-paragraph blockquotes produce exactly one blank
		// `>` line between paragraphs, not several.
		$inner = (string) \preg_replace( "/\n{3,}/", "\n\n", $inner );

		$lines  = \explode( "\n", $inner );
		$quoted = array();

		foreach ( $lines as $line ) {
			$quoted[] = '' === $line ? '>' : '> ' . $line;
		}

		return "\n\n" . \implode( "\n", $quoted ) . "\n\n";
	}

	private static function render_figure( \DOMElement $node, int $depth ): string {
		// WordPress oembed wrapper — pluck the inner iframe / link and render
		// just that, no figure markup needed.
		$class = $node->getAttribute( 'class' );

		if ( false !== \strpos( $class, 'wp-block-embed' ) ) {
			return self::render_oembed_figure( $node, $depth );
		}

		$image_md   = '';
		$caption_md = '';

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$child_tag = \strtolower( $child->nodeName );

			if ( 'img' === $child_tag ) {
				$image_md = self::render_image( $child );
				continue;
			}

			if ( 'figcaption' === $child_tag ) {
				$caption_md = \trim( self::render_children( $child, $depth ) );
				continue;
			}

			if ( 'a' === $child_tag ) {
				// Linked image — render the link, which contains the image.
				$image_md = self::render_link( $child, $depth );
			}
		}

		if ( '' === $image_md ) {
			return self::render_children( $node, $depth );
		}

		$out = "\n\n" . $image_md;
		if ( '' !== $caption_md ) {
			$out .= "\n*" . $caption_md . '*';
		}
		return $out . "\n\n";
	}

	private static function render_oembed_figure( \DOMElement $node, int $depth ): string {
		// Prefer an inner `<iframe src="...">` (YouTube, Vimeo, etc.).
		foreach ( $node->getElementsByTagName( 'iframe' ) as $iframe ) {
			$src = $iframe->getAttribute( 'src' );
			if ( '' !== $src ) {
				return "\n\n[" . $src . '](' . $src . ")\n\n";
			}
		}

		// Fall back to an inner `<a href="...">`.
		foreach ( $node->getElementsByTagName( 'a' ) as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( '' !== $href ) {
				return "\n\n[" . $href . '](' . $href . ")\n\n";
			}
		}

		return self::render_children( $node, $depth );
	}

	private static function render_table( \DOMElement $node ): string {
		++self::$counters['table_total'];

		$rows          = array();
		$has_thead     = false;
		$column_counts = array();

		foreach ( $node->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( ! $cell instanceof \DOMElement ) {
					continue;
				}
				$cell_tag = \strtolower( $cell->nodeName );
				if ( 'th' !== $cell_tag && 'td' !== $cell_tag ) {
					continue;
				}
				if ( 'th' === $cell_tag ) {
					$has_thead = true;
				}
				$cells[] = \trim( \preg_replace( '/\s+/u', ' ', $cell->textContent ) ?? '' );
			}

			if ( array() !== $cells ) {
				$rows[]          = $cells;
				$column_counts[] = \count( $cells );
			}
		}

		if ( array() === $rows ) {
			++self::$counters['table_fragment'];
			return '';
		}

		// Table is "fragmented" if it has no header row OR its row widths
		// disagree. Either signal usually means a builder produced soup
		// that the walker had to guess column counts for.
		if ( ! $has_thead || \count( \array_unique( $column_counts ) ) > 1 ) {
			++self::$counters['table_fragment'];
		}

		$columns   = \count( $rows[0] );
		$separator = \array_fill( 0, $columns, '---' );

		$lines   = array();
		$lines[] = '| ' . \implode( ' | ', $rows[0] ) . ' |';
		$lines[] = '| ' . \implode( ' | ', $separator ) . ' |';

		$row_count = \count( $rows );
		for ( $i = 1; $i < $row_count; $i++ ) {
			$padded     = $rows[ $i ];
			$cell_count = \count( $padded );
			while ( $cell_count < $columns ) {
				$padded[] = '';
				++$cell_count;
			}
			$lines[] = '| ' . \implode( ' | ', $padded ) . ' |';
		}

		return "\n\n" . \implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Build the signals map from the accumulated counters plus post-walk
	 * inspection of the final MD output (empty-line runs, shortcode
	 * residue — these are properties of the output, not the walk).
	 */
	private static function derive_signals( string $md ): array {
		$tag_total       = self::$counters['tag_total'];
		$tag_strip_count = self::$counters['tag_empty_output'];
		$tag_strip_rate  = $tag_total > 0 ? $tag_strip_count / $tag_total : 0.0;

		$div_total      = self::$counters['div_total'];
		$deep_div_count = self::$counters['deep_div'];
		$deep_div_rate  = $div_total > 0 ? $deep_div_count / $div_total : 0.0;

		$paragraph_total  = self::$counters['paragraph_total'];
		$image_only_count = self::$counters['image_only_paragraph'];
		$image_only_rate  = $paragraph_total > 0 ? $image_only_count / $paragraph_total : 0.0;

		$table_total         = self::$counters['table_total'];
		$table_fragment      = self::$counters['table_fragment'];
		$table_fragment_rate = $table_total > 0 ? $table_fragment / $table_total : 0.0;

		// Orphan inline-style normalisation: count per kB of MD output, with
		// the calibration constant capping the rate at 1.0.
		$orphan_style_count = self::$counters['orphan_inline_style'];
		$md_kb              = \max( 1.0, \strlen( $md ) / 1024.0 );
		$orphan_style_rate  = \min( 1.0, ( $orphan_style_count / $md_kb ) / self::ORPHAN_STYLE_NORMALISATION );

		// Post-walk MD inspection: runs of 3+ blank lines.
		$empty_run_match = \preg_match_all( "/(\n[ \t]*){3,}/", $md );
		$empty_run_count = false === $empty_run_match ? 0 : $empty_run_match;
		$empty_run_rate  = \min( 1.0, $empty_run_count / 10.0 );

		// Post-walk MD inspection: bare shortcode tokens that survived the
		// walker (the preprocess strips caption/gallery; anything else
		// that survives is residue we couldn't expand).
		$shortcode_match = \preg_match_all( '/\[[a-z][a-z0-9_-]*[\s\]\/]/i', $md );
		$shortcode_count = false === $shortcode_match ? 0 : $shortcode_match;
		$shortcode_rate  = \min( 1.0, $shortcode_count / 5.0 );

		return array(
			'tag_strip_rate'             => $tag_strip_rate,
			'tag_strip_count'            => $tag_strip_count,
			'tag_total_count'            => $tag_total,
			'orphan_inline_style_rate'   => $orphan_style_rate,
			'orphan_inline_style_count'  => $orphan_style_count,
			'table_fragment_rate'        => $table_fragment_rate,
			'table_fragment_count'       => $table_fragment,
			'table_total_count'          => $table_total,
			'deep_div_nesting_rate'      => $deep_div_rate,
			'deep_div_count'             => $deep_div_count,
			'div_total_count'            => $div_total,
			'image_only_paragraph_rate'  => $image_only_rate,
			'image_only_paragraph_count' => $image_only_count,
			'paragraph_total_count'      => $paragraph_total,
			'empty_line_run_rate'        => $empty_run_rate,
			'empty_line_run_count'       => $empty_run_count,
			'shortcode_residue_rate'     => $shortcode_rate,
			'shortcode_residue_count'    => $shortcode_count,
		);
	}

	/**
	 * Apply the weighted formula from AgDR-0017. Floor at 0, ceiling at
	 * 100. Result is the cleanup-trigger signal: < threshold → enqueue
	 * cleanup, >= threshold → ship deterministic.
	 *
	 * @param array<string, int|float> $signals
	 */
	private static function compute_score( array $signals ): int {
		$score = 100.0;

		foreach ( self::QUALITY_WEIGHTS as $signal_key => $weight ) {
			$rate   = isset( $signals[ $signal_key ] ) ? (float) $signals[ $signal_key ] : 0.0;
			$score -= $weight * $rate;
		}

		if ( $score < 0.0 ) {
			return 0;
		}

		if ( $score > 100.0 ) {
			return 100;
		}

		return (int) \round( $score );
	}
}
