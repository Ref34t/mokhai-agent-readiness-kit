<?php
/**
 * Unit tests for the shared orphaned-shortcode stripper (#147).
 *
 * Pure function — no WordPress load. Mirrors the behaviour the Walker (#145)
 * and the /llms.txt description generator (#147) both rely on.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WPContext\Support\Shortcode_Stripper;

final class Shortcode_Stripper_Test extends TestCase {

	public function test_strips_attribute_bearing_standalone(): void {
		$out = Shortcode_Stripper::strip_orphaned( 'Body with [vc_btn title="X"] residue.' );
		self::assertStringNotContainsString( '[vc_btn', $out );
		self::assertStringContainsString( 'Body with', $out );
		self::assertStringContainsString( 'residue.', $out );
	}

	public function test_strips_self_closing_standalone(): void {
		$out = Shortcode_Stripper::strip_orphaned( 'Embed [my_embed /] here.' );
		self::assertStringNotContainsString( '[my_embed', $out );
		self::assertStringContainsString( 'Embed', $out );
		self::assertStringContainsString( 'here.', $out );
	}

	public function test_unwraps_nested_paired_keeping_inner_content(): void {
		$out = Shortcode_Stripper::strip_orphaned(
			'[vc_row][vc_column width="1/2"][vc_column_text]Real copy here.[/vc_column_text][/vc_column][/vc_row]'
		);
		self::assertSame( 'Real copy here.', \trim( $out ) );
		self::assertStringNotContainsString( '[vc_', $out );
	}

	public function test_preserves_non_shortcode_bracketed_prose(): void {
		$in  = 'See footnote [1] and the [citation needed] marker.';
		$out = Shortcode_Stripper::strip_orphaned( $in );
		self::assertStringContainsString( '[1]', $out );
		self::assertStringContainsString( '[citation needed]', $out );
	}

	public function test_leaves_plain_text_untouched(): void {
		$in = 'A clean sentence with no shortcodes at all.';
		self::assertSame( $in, Shortcode_Stripper::strip_orphaned( $in ) );
	}

	public function test_strips_curly_quoted_attribute_shortcode(): void {
		// wptexturize converts straight quotes to curly in post_content, so the
		// stripper must not depend on ASCII quotes inside the attribute value.
		$out = Shortcode_Stripper::strip_orphaned( 'Two with [vc_btn title=”X”] residue.' );
		self::assertStringNotContainsString( '[vc_btn', $out );
		self::assertStringContainsString( 'residue.', $out );
	}
}
