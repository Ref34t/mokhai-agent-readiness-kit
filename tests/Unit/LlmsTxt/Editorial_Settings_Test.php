<?php
/**
 * Unit tests for LlmsTxt\Editorial_Settings::sanitize.
 *
 * The sanitiser is the single write path — every test pins one branch.
 * No WordPress is loaded; sanitize_text_field / esc_url_raw are stubbed
 * in tests/Unit/wp-stubs.php.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\LlmsTxt;

use PHPUnit\Framework\TestCase;
use Mokhai\LlmsTxt\Editorial_Settings;

final class Editorial_Settings_Test extends TestCase {

	public function test_non_array_input_returns_defaults(): void {
		$result = Editorial_Settings::sanitize( 'not-an-array' );

		$this->assertSame( Editorial_Settings::SCHEMA_VERSION, $result['schema_version'] );
		$this->assertSame( array(), $result['entries'] );
	}

	public function test_versioned_input_is_preserved(): void {
		$input = array(
			'schema_version' => 1,
			'entries'        => array(
				array(
					'title'   => 'Hello',
					'url'     => 'https://example.com/',
					'section' => 'Featured',
				),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertSame( 1, $result['schema_version'] );
		$this->assertCount( 1, $result['entries'] );
		$this->assertSame( 'Hello', $result['entries'][0]['title'] );
	}

	public function test_bare_list_input_is_wrapped(): void {
		$input = array(
			array(
				'title' => 'Hello',
				'url'   => 'https://example.com/',
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertSame( 1, $result['schema_version'] );
		$this->assertCount( 1, $result['entries'] );
	}

	public function test_empty_title_or_url_drops_entry(): void {
		$input = array(
			'entries' => array(
				array( 'title' => '', 'url' => 'https://example.com/a' ),
				array( 'title' => 'B', 'url' => '' ),
				array( 'title' => 'C', 'url' => 'https://example.com/c' ),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertCount( 1, $result['entries'] );
		$this->assertSame( 'C', $result['entries'][0]['title'] );
	}

	public function test_section_outside_picklist_defaults_to_featured(): void {
		$input = array(
			'entries' => array(
				array(
					'title'   => 'X',
					'url'     => 'https://example.com/',
					'section' => 'WhateverInvalid',
				),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertSame( 'Featured', $result['entries'][0]['section'] );
	}

	public function test_custom_section_with_label_is_preserved(): void {
		$input = array(
			'entries' => array(
				array(
					'title'         => 'X',
					'url'           => 'https://example.com/',
					'section'       => 'Custom',
					'section_label' => 'For Partners',
				),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertSame( 'Custom', $result['entries'][0]['section'] );
		$this->assertSame( 'For Partners', $result['entries'][0]['section_label'] );
	}

	public function test_custom_section_without_label_degrades_to_featured(): void {
		$input = array(
			'entries' => array(
				array(
					'title'   => 'X',
					'url'     => 'https://example.com/',
					'section' => 'Custom',
					// no section_label
				),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertSame( 'Featured', $result['entries'][0]['section'] );
		$this->assertArrayNotHasKey( 'section_label', $result['entries'][0] );
	}

	public function test_javascript_scheme_url_is_rejected(): void {
		$input = array(
			'entries' => array(
				array(
					'title' => 'Evil',
					'url'   => 'javascript:alert(1)',
				),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertSame( array(), $result['entries'] );
	}

	public function test_mailto_scheme_url_is_accepted(): void {
		$input = array(
			'entries' => array(
				array(
					'title' => 'Contact',
					'url'   => 'mailto:agent@example.com',
				),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertCount( 1, $result['entries'] );
		$this->assertStringContainsString( 'mailto:', $result['entries'][0]['url'] );
	}

	public function test_caller_schema_version_is_ignored(): void {
		$input = array(
			'schema_version' => 99,
			'entries'        => array(
				array( 'title' => 'X', 'url' => 'https://example.com/' ),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		// Reset to CURRENT regardless of caller input — prevents forging
		// a future schema version to skip migrations.
		$this->assertSame( Editorial_Settings::SCHEMA_VERSION, $result['schema_version'] );
	}

	public function test_entries_are_reindexed_after_sanitisation(): void {
		$input = array(
			'entries' => array(
				'a' => array( 'title' => 'A', 'url' => 'https://example.com/a' ),
				'b' => array( 'title' => 'B', 'url' => 'https://example.com/b' ),
			),
		);

		$result = Editorial_Settings::sanitize( $input );

		$this->assertSame( array( 0, 1 ), array_keys( $result['entries'] ) );
	}

	public function test_rendered_section_for_picklist_returns_value(): void {
		$this->assertSame(
			'Featured',
			Editorial_Settings::rendered_section_for( array( 'section' => 'Featured' ) )
		);
		$this->assertSame(
			'Resources',
			Editorial_Settings::rendered_section_for( array( 'section' => 'Resources' ) )
		);
	}

	public function test_rendered_section_for_custom_uses_section_label(): void {
		$this->assertSame(
			'My Custom',
			Editorial_Settings::rendered_section_for(
				array( 'section' => 'Custom', 'section_label' => 'My Custom' )
			)
		);
	}

	public function test_rendered_section_for_malformed_returns_empty(): void {
		$this->assertSame(
			'',
			Editorial_Settings::rendered_section_for( array( 'section' => 'Bogus' ) )
		);
		$this->assertSame(
			'',
			Editorial_Settings::rendered_section_for( array() )
		);
	}
}
