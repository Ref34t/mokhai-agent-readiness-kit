<?php
/**
 * Unit tests for the LlmsTxt conflict detector — the pure parts.
 *
 * The detector itself reaches into WP state (is_plugin_active, $wp_rewrite,
 * filesystem). The unit suite focuses on the parts that don't require a
 * booted WP: the slug registry, the fingerprint hash, and the filter
 * handling. Integration tests cover the WP-state-touching code paths.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Unit\LlmsTxt;

use PHPUnit\Framework\TestCase;
use Mokhai\LlmsTxt\Conflict_Detector;

final class Conflict_Detector_Test extends TestCase {

	public function test_default_known_plugins_contains_all_five_seed_entries(): void {
		$plugins = Conflict_Detector::default_known_plugins();

		$this->assertArrayHasKey( 'website-llms-txt/website-llms-txt.php', $plugins );
		$this->assertArrayHasKey( 'llms-full-txt-generator/llms-txt-generator.php', $plugins );
		$this->assertArrayHasKey( 'llms-txt-generator/llms-txt-generator.php', $plugins );
		$this->assertArrayHasKey( 'markdown-mirror/markdown-mirror.php', $plugins );
		$this->assertArrayHasKey( 'jumpsuitai-llms-txt/jumpsuitai-llms-txt.php', $plugins );
	}

	public function test_default_known_plugins_entries_have_required_shape(): void {
		foreach ( Conflict_Detector::default_known_plugins() as $slug => $meta ) {
			$this->assertIsString( $slug );
			$this->assertNotSame( '', $slug );
			$this->assertArrayHasKey( 'name', $meta );
			$this->assertArrayHasKey( 'url', $meta );
			$this->assertArrayHasKey( 'shape', $meta );
			$this->assertIsString( $meta['name'] );
			$this->assertIsString( $meta['url'] );
			$this->assertContains( $meta['shape'], array( 'static-file', 'rewrite', 'hybrid' ) );
		}
	}

	public function test_llms_full_txt_generator_uses_correct_entry_file_path(): void {
		// The gotcha entry: directory `llms-full-txt-generator` but entry
		// file `llms-txt-generator.php`. If someone refactors this to a
		// dir-derived guess, the detector silently stops catching the
		// 4k-installs plugin.
		$plugins = Conflict_Detector::default_known_plugins();

		$this->assertArrayHasKey( 'llms-full-txt-generator/llms-txt-generator.php', $plugins );
		$this->assertArrayNotHasKey( 'llms-full-txt-generator/llms-full-txt-generator.php', $plugins );
	}

	public function test_fingerprint_is_stable_across_key_order(): void {
		$a = array(
			array( 'kind' => 'plugin', 'slug' => 'x/x.php', 'name' => 'X' ),
		);
		$b = array(
			array( 'name' => 'X', 'slug' => 'x/x.php', 'kind' => 'plugin' ),
		);

		$this->assertSame(
			Conflict_Detector::fingerprint( $a ),
			Conflict_Detector::fingerprint( $b )
		);
	}

	public function test_fingerprint_is_stable_across_entry_order(): void {
		$a = array(
			array( 'kind' => 'plugin', 'slug' => 'x/x.php' ),
			array( 'kind' => 'filesystem', 'path' => '/var/www/llms.txt' ),
		);
		$b = array(
			array( 'kind' => 'filesystem', 'path' => '/var/www/llms.txt' ),
			array( 'kind' => 'plugin', 'slug' => 'x/x.php' ),
		);

		$this->assertSame(
			Conflict_Detector::fingerprint( $a ),
			Conflict_Detector::fingerprint( $b )
		);
	}

	public function test_fingerprint_differs_when_conflict_set_changes(): void {
		$one = array(
			array( 'kind' => 'plugin', 'slug' => 'x/x.php' ),
		);
		$two = array(
			array( 'kind' => 'plugin', 'slug' => 'x/x.php' ),
			array( 'kind' => 'plugin', 'slug' => 'y/y.php' ),
		);

		$this->assertNotSame(
			Conflict_Detector::fingerprint( $one ),
			Conflict_Detector::fingerprint( $two )
		);
	}

	public function test_fingerprint_of_empty_conflicts_is_stable(): void {
		$hash = Conflict_Detector::fingerprint( array() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $hash );
	}

	public function test_rewrite_key_matches_router_regex(): void {
		// Defence against the two constants drifting. If Router::add_rewrite_rule
		// changes its regex, Conflict_Detector::REWRITE_KEY must change with
		// it — otherwise the rewrite-scan signal silently goes dead.
		$this->assertSame( '^llms\.txt/?$', Conflict_Detector::REWRITE_KEY );
	}

	public function test_rewrite_fingerprint_matches_router_query_var(): void {
		// Same defence for the query-var name used in the rewrite target.
		$this->assertSame( 'agentready_llms_txt', Conflict_Detector::REWRITE_FINGERPRINT );
	}
}
