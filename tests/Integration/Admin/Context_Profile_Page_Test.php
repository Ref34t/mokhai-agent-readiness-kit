<?php
/**
 * Integration tests for `WPContext\Admin\Context_Profile_Page` admin-glue
 * behaviour — specifically the Plugins-list "Settings" action link (#207).
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Admin;

use WP_UnitTestCase;
use WPContext\Admin\Context_Profile_Page;

final class Context_Profile_Page_Test extends WP_UnitTestCase {

	public function test_settings_action_link_is_prepended_and_points_to_context_page(): void {
		$links  = array( '<a href="https://example.test/deactivate">Deactivate</a>' );
		$result = Context_Profile_Page::add_settings_action_link( $links );

		// The Settings link is prepended (appears before the existing links).
		self::assertCount( 2, $result );
		self::assertStringContainsString( 'Settings', $result[0] );
		self::assertStringContainsString( 'page=' . Context_Profile_Page::PAGE_SLUG, $result[0] );
		self::assertStringContainsString( 'tools.php', $result[0] );

		// Existing links are preserved.
		self::assertStringContainsString( 'Deactivate', $result[1] );
	}
}
