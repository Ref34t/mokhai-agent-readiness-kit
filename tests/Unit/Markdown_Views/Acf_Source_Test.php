<?php
/**
 * Unit tests for the ACF Markdown-source adapter (#292 / AgDR-0068).
 *
 * The adapter sources ACF field text into the Markdown body when `the_content`
 * is empty, so ACF/page-builder pages (empty `post_content`) no longer render a
 * 0-byte `.md` body. These tests exercise:
 *
 * 1. The pure recursive extractor (`extract_text_html`) over the ACF
 *    field-object shape — text types emitted in order, non-text types excluded,
 *    Flexible Content / Group / Repeater containers walked.
 * 2. The filter callback (`append_field_text`) — no-op when `the_content`
 *    already produced content or ACF has no fields; sources fields when empty.
 * 3. The cache-hash extension (`extend_content_hash`) — a field change alters
 *    the hash input.
 *
 * A global `get_field_objects()` double stands in for ACF; the active test
 * places its return value in `$GLOBALS['wpctx_test_acf_fields']`.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'get_field_objects' ) ) {
		function get_field_objects( $post_id ) {
			return $GLOBALS['wpctx_test_acf_fields'] ?? false;
		}
	}
}

namespace Mokhai\Tests\Unit\Markdown_Views {

	use PHPUnit\Framework\TestCase;
	use Mokhai\Markdown_Views\Acf_Source;
	use WP_Post;

	final class Acf_Source_Test extends TestCase {

		protected function tearDown(): void {
			unset( $GLOBALS['wpctx_test_acf_fields'] );
			parent::tearDown();
		}

		private function make_post(): WP_Post {
			$post            = new WP_Post();
			$post->ID        = 42;
			$post->post_type = 'page';
			return $post;
		}

		// --- extract_text_html: text types ------------------------------------

		public function test_top_level_text_types_emitted_in_order(): void {
			$objects = array(
				'intro'   => array( 'type' => 'text', 'value' => 'Welcome line.' ),
				'body'    => array( 'type' => 'textarea', 'value' => "First\nSecond" ),
				'rich'    => array( 'type' => 'wysiwyg', 'value' => '<p>Rich <strong>copy</strong>.</p>' ),
			);

			$html = Acf_Source::extract_text_html( $objects );

			self::assertStringContainsString( 'Welcome line.', $html );
			self::assertStringContainsString( 'First', $html );
			self::assertStringContainsString( 'Rich', $html );
			// Registration order preserved.
			self::assertLessThan( strpos( $html, 'First' ), strpos( $html, 'Welcome line.' ) );
			self::assertLessThan( strpos( $html, 'Rich' ), strpos( $html, 'First' ) );
			// wysiwyg kept as raw HTML.
			self::assertStringContainsString( '<strong>copy</strong>', $html );
		}

		public function test_non_text_types_excluded(): void {
			$objects = array(
				'hero_image' => array( 'type' => 'image', 'value' => 1234 ),
				'featured'   => array( 'type' => 'true_false', 'value' => true ),
				'category'   => array( 'type' => 'select', 'value' => 'blue' ),
				'count'      => array( 'type' => 'number', 'value' => 7 ),
				'headline'   => array( 'type' => 'text', 'value' => 'The one real line.' ),
			);

			$html = Acf_Source::extract_text_html( $objects );

			self::assertSame( '<p>The one real line.</p>', trim( $html ) );
			self::assertStringNotContainsString( '1234', $html );
			self::assertStringNotContainsString( 'blue', $html );
		}

		public function test_empty_and_nonstring_values_skipped(): void {
			$objects = array(
				'blank'  => array( 'type' => 'text', 'value' => '   ' ),
				'null'   => array( 'type' => 'text', 'value' => null ),
				'real'   => array( 'type' => 'text', 'value' => 'Kept.' ),
			);

			self::assertSame( '<p>Kept.</p>', trim( Acf_Source::extract_text_html( $objects ) ) );
		}

		// --- extract_text_html: containers ------------------------------------

		public function test_group_recursed(): void {
			$objects = array(
				'hero' => array(
					'type'       => 'group',
					'sub_fields' => array(
						array( 'name' => 'title', 'type' => 'text' ),
						array( 'name' => 'lead', 'type' => 'textarea' ),
						array( 'name' => 'icon', 'type' => 'image' ),
					),
					'value'      => array(
						'title' => 'Group Title',
						'lead'  => 'Group lead.',
						'icon'  => 999,
					),
				),
			);

			$html = Acf_Source::extract_text_html( $objects );

			self::assertStringContainsString( 'Group Title', $html );
			self::assertStringContainsString( 'Group lead.', $html );
			self::assertStringNotContainsString( '999', $html );
		}

		public function test_repeater_rows_recursed_in_order(): void {
			$objects = array(
				'faqs' => array(
					'type'       => 'repeater',
					'sub_fields' => array(
						array( 'name' => 'q', 'type' => 'text' ),
						array( 'name' => 'a', 'type' => 'textarea' ),
					),
					'value'      => array(
						array( 'q' => 'First question?', 'a' => 'First answer.' ),
						array( 'q' => 'Second question?', 'a' => 'Second answer.' ),
					),
				),
			);

			$html = Acf_Source::extract_text_html( $objects );

			self::assertStringContainsString( 'First question?', $html );
			self::assertStringContainsString( 'Second answer.', $html );
			self::assertLessThan(
				strpos( $html, 'Second question?' ),
				strpos( $html, 'First question?' )
			);
		}

		public function test_flexible_content_layouts_recursed(): void {
			$objects = array(
				'sections' => array(
					'type'    => 'flexible_content',
					'layouts' => array(
						array(
							'name'       => 'hero',
							'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text' ) ),
						),
						array(
							'name'       => 'prose',
							'sub_fields' => array( array( 'name' => 'body', 'type' => 'wysiwyg' ) ),
						),
					),
					'value'   => array(
						array( 'acf_fc_layout' => 'hero', 'heading' => 'Hero heading.' ),
						array( 'acf_fc_layout' => 'prose', 'body' => '<p>Prose body.</p>' ),
						array( 'acf_fc_layout' => 'unknown_layout', 'body' => 'Should not appear.' ),
					),
				),
			);

			$html = Acf_Source::extract_text_html( $objects );

			self::assertStringContainsString( 'Hero heading.', $html );
			self::assertStringContainsString( 'Prose body.', $html );
			// A layout with no matching definition contributes nothing.
			self::assertStringNotContainsString( 'Should not appear.', $html );
		}

		// --- append_field_text: filter callback -------------------------------

		public function test_noop_when_the_content_already_produced_html(): void {
			$GLOBALS['wpctx_test_acf_fields'] = array(
				'intro' => array( 'type' => 'text', 'value' => 'ACF copy.' ),
			);
			$html = '<p>Real post_content body.</p>';

			$out = Acf_Source::append_field_text( $html, $this->make_post() );

			self::assertSame( $html, $out );
			self::assertStringNotContainsString( 'ACF copy.', $out );
		}

		public function test_noop_when_acf_has_no_fields(): void {
			// get_field_objects() returns false → nothing to source.
			$out = Acf_Source::append_field_text( '', $this->make_post() );
			self::assertSame( '', $out );
		}

		public function test_sources_fields_when_the_content_empty(): void {
			$GLOBALS['wpctx_test_acf_fields'] = array(
				'intro' => array( 'type' => 'text', 'value' => 'Sourced from ACF.' ),
			);

			$out = Acf_Source::append_field_text( '', $this->make_post() );

			self::assertStringContainsString( 'Sourced from ACF.', $out );
		}

		public function test_noop_when_fields_have_no_text(): void {
			$GLOBALS['wpctx_test_acf_fields'] = array(
				'img' => array( 'type' => 'image', 'value' => 5 ),
			);

			// No extractable text → leave the (empty) html unchanged.
			self::assertSame( '', Acf_Source::append_field_text( '', $this->make_post() ) );
		}

		// --- extend_content_hash ----------------------------------------------

		public function test_hash_input_changes_when_field_value_changes(): void {
			$post = $this->make_post();
			$base = 'base-hash-input';

			$GLOBALS['wpctx_test_acf_fields'] = array(
				'intro' => array( 'type' => 'text', 'value' => 'Version one.' ),
			);
			$first = Acf_Source::extend_content_hash( $base, $post );

			$GLOBALS['wpctx_test_acf_fields'] = array(
				'intro' => array( 'type' => 'text', 'value' => 'Version two.' ),
			);
			$second = Acf_Source::extend_content_hash( $base, $post );

			self::assertNotSame( $first, $second );
			self::assertStringStartsWith( $base, $first );
		}

		public function test_hash_input_unchanged_when_no_acf_fields(): void {
			$base = 'base-hash-input';
			self::assertSame( $base, Acf_Source::extend_content_hash( $base, $this->make_post() ) );
		}
	}
}
