<?php
/**
 * Integration tests for the LlmsTxt conflict detector — the WP-state side.
 *
 * Covers the three detection surfaces interacting with real WP globals:
 * `is_plugin_active`, the filesystem ABSPATH check, and the rewrite-rule
 * scan against `$wp_rewrite->extra_rules_top`.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\LlmsTxt;

use WP_UnitTestCase;
use Mokhai\LlmsTxt\Conflict_Detector;
use Mokhai\LlmsTxt\Conflict_Notice;
use Mokhai\LlmsTxt\Router;

final class Conflict_Detector_Test extends WP_UnitTestCase {

	private string $static_file_path = '';

	protected function setUp(): void {
		parent::setUp();

		$this->static_file_path = ABSPATH . 'llms.txt';

		Conflict_Notice::invalidate_cache();

		// Reset rewrite state so each test starts from a known shape.
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
	}

	protected function tearDown(): void {
		if ( '' !== $this->static_file_path && file_exists( $this->static_file_path ) ) {
			@unlink( $this->static_file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		Conflict_Notice::invalidate_cache();

		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();

		remove_all_filters( Conflict_Detector::SLUG_REGISTRY_FILTER );

		parent::tearDown();
	}

	public function test_clean_environment_returns_empty(): void {
		$this->assertSame( array(), Conflict_Detector::detect() );
	}

	public function test_our_own_rewrite_does_not_register_as_a_conflict(): void {
		Router::add_rewrite_rule();

		global $wp_rewrite;
		$this->assertArrayHasKey( Conflict_Detector::REWRITE_KEY, $wp_rewrite->extra_rules_top );

		$conflicts = Conflict_Detector::detect();
		$rewrite_conflicts = array_values(
			array_filter(
				$conflicts,
				static fn ( $c ) => 'rewrite' === ( $c['kind'] ?? '' )
			)
		);
		$this->assertSame( array(), $rewrite_conflicts );
	}

	public function test_competing_rewrite_rule_is_detected(): void {
		global $wp_rewrite;
		$wp_rewrite->extra_rules_top[ Conflict_Detector::REWRITE_KEY ] = 'index.php?some_other_plugin=1';

		$conflicts = Conflict_Detector::detect();
		$rewrite_conflicts = array_values(
			array_filter(
				$conflicts,
				static fn ( $c ) => 'rewrite' === ( $c['kind'] ?? '' )
			)
		);

		$this->assertCount( 1, $rewrite_conflicts );
		$this->assertSame( 'index.php?some_other_plugin=1', $rewrite_conflicts[0]['rule'] );
	}

	public function test_static_file_at_abspath_is_detected(): void {
		file_put_contents( $this->static_file_path, "# competitor\n" );

		$conflicts = Conflict_Detector::detect();
		$fs_conflicts = array_values(
			array_filter(
				$conflicts,
				static fn ( $c ) => 'filesystem' === ( $c['kind'] ?? '' )
			)
		);

		$this->assertCount( 1, $fs_conflicts );
		$this->assertSame( $this->static_file_path, $fs_conflicts[0]['path'] );
	}

	public function test_active_competing_plugin_is_detected(): void {
		// Simulate a registered competing plugin via the filter — we don't
		// need a real plugin installed; we just need is_plugin_active to
		// see the slug as active. Easiest way: add the slug to the active
		// plugins option directly for the duration of this test.
		$fake_slug = 'fake-test-llms-txt/fake-test-llms-txt.php';
		add_filter(
			Conflict_Detector::SLUG_REGISTRY_FILTER,
			static function ( $slugs ) use ( $fake_slug ) {
				$slugs[ $fake_slug ] = array(
					'name'  => 'Fake Test LLMs.txt',
					'url'   => 'https://example.test/fake',
					'shape' => 'rewrite',
				);
				return $slugs;
			}
		);

		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( (array) $active, array( $fake_slug ) ) );

		try {
			$conflicts = Conflict_Detector::detect();
			$plugin_conflicts = array_values(
				array_filter(
					$conflicts,
					static fn ( $c ) => 'plugin' === ( $c['kind'] ?? '' )
				)
			);

			$this->assertCount( 1, $plugin_conflicts );
			$this->assertSame( $fake_slug, $plugin_conflicts[0]['slug'] );
			$this->assertSame( 'Fake Test LLMs.txt', $plugin_conflicts[0]['name'] );
		} finally {
			update_option( 'active_plugins', $active );
		}
	}

	public function test_all_three_surfaces_report_independently(): void {
		// Plugin via filter + active option, filesystem file, and rewrite shadow
		// — all at once.
		$fake_slug = 'fake-multi/fake-multi.php';
		add_filter(
			Conflict_Detector::SLUG_REGISTRY_FILTER,
			static function ( $slugs ) use ( $fake_slug ) {
				$slugs[ $fake_slug ] = array(
					'name'  => 'Multi',
					'url'   => 'https://example.test/multi',
					'shape' => 'hybrid',
				);
				return $slugs;
			}
		);

		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( (array) $active, array( $fake_slug ) ) );

		file_put_contents( $this->static_file_path, "# competing static\n" );

		global $wp_rewrite;
		$wp_rewrite->extra_rules_top[ Conflict_Detector::REWRITE_KEY ] = 'index.php?other=1';

		try {
			$conflicts = Conflict_Detector::detect();

			$kinds = array_count_values( array_column( $conflicts, 'kind' ) );
			$this->assertSame( 1, $kinds['plugin'] ?? 0 );
			$this->assertSame( 1, $kinds['filesystem'] ?? 0 );
			$this->assertSame( 1, $kinds['rewrite'] ?? 0 );
		} finally {
			update_option( 'active_plugins', $active );
		}
	}

	public function test_filter_can_add_entries_to_registry(): void {
		add_filter(
			Conflict_Detector::SLUG_REGISTRY_FILTER,
			static function ( $slugs ) {
				$slugs['custom-vendor/custom-plugin.php'] = array(
					'name'  => 'Custom',
					'url'   => '',
					'shape' => 'rewrite',
				);
				return $slugs;
			}
		);

		$plugins = Conflict_Detector::known_plugins();
		$this->assertArrayHasKey( 'custom-vendor/custom-plugin.php', $plugins );
		$this->assertSame( 'Custom', $plugins['custom-vendor/custom-plugin.php']['name'] );
	}

	public function test_filter_drops_malformed_entries_silently(): void {
		add_filter(
			Conflict_Detector::SLUG_REGISTRY_FILTER,
			static function () {
				return array(
					'good/good.php' => array(
						'name'  => 'Good',
						'url'   => '',
						'shape' => 'rewrite',
					),
					42              => array( 'name' => 'Bad key' ), // non-string key
					'empty-name/empty.php' => array( 'name' => '' ),  // empty name
					'not-array/x.php' => 'string-instead-of-array',
				);
			}
		);

		$plugins = Conflict_Detector::known_plugins();

		$this->assertArrayHasKey( 'good/good.php', $plugins );
		$this->assertArrayNotHasKey( 42, $plugins );
		$this->assertArrayNotHasKey( 'empty-name/empty.php', $plugins );
		$this->assertArrayNotHasKey( 'not-array/x.php', $plugins );
	}

	public function test_filter_returning_non_array_falls_back_to_default(): void {
		add_filter(
			Conflict_Detector::SLUG_REGISTRY_FILTER,
			static function () {
				return 'oops';
			}
		);

		$plugins = Conflict_Detector::known_plugins();
		$this->assertSame( Conflict_Detector::default_known_plugins(), $plugins );
	}
}
