<?php
/**
 * Integration tests for empty-twin exclusion from /llms.txt (#292 / AgDR-0068).
 *
 * When entries link the `.md` twin (Markdown Views enabled), a page whose twin
 * is empty must not be advertised — an empty twin the agent trusts is worse
 * than no entry, and the `.md` route 404s the same page. When Markdown Views is
 * disabled the entry links the HTML permalink, so the empty-twin concern does
 * not apply and the page stays listed.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\LlmsTxt\Entry_Source;
use Mokhai\Markdown_Views\Schema;

final class Empty_Twin_Exclusion_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Schema::create();
		$this->set_markdown_views( true );
	}

	protected function tearDown(): void {
		Schema::drop();
		parent::tearDown();
	}

	private function set_markdown_views( bool $enabled ): void {
		update_option(
			Context_Profile_Settings::OPTION_KEY,
			array_merge(
				Context_Profile_Settings::get_defaults(),
				array(
					'exposed_cpts'           => array( 'post', 'page' ),
					'exposed_statuses'       => array( 'publish' ),
					'markdown_views_enabled' => $enabled,
				)
			)
		);
	}

	public function test_empty_twin_page_dropped_when_markdown_views_enabled(): void {
		$post = self::factory()->post->create_and_get( array( 'post_content' => '' ) );

		self::assertNull( Entry_Source::entry_for_post( $post ) );
	}

	public function test_page_with_content_is_listed(): void {
		$post = self::factory()->post->create_and_get(
			array( 'post_content' => '<p>Indexable body.</p>' )
		);

		$entry = Entry_Source::entry_for_post( $post );

		self::assertIsArray( $entry );
		self::assertSame( (int) $post->ID, $entry['post_id'] );
	}

	public function test_empty_page_still_listed_when_markdown_views_disabled(): void {
		// Entry links the HTML permalink, not the .md twin — the empty-twin
		// concern doesn't apply, so the page stays in the index.
		$this->set_markdown_views( false );
		$post = self::factory()->post->create_and_get( array( 'post_content' => '' ) );

		$entry = Entry_Source::entry_for_post( $post );

		self::assertIsArray( $entry );
		self::assertSame( (int) $post->ID, $entry['post_id'] );
	}
}
