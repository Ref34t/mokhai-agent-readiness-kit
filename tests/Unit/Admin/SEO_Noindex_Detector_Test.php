<?php
/**
 * Unit tests for SEO_Noindex_Detector (#180 — folds in #176).
 *
 * Drives Schema_Coordination_Detector's posture via the is_plugin_active()
 * seam ($GLOBALS['wpctx_test_active_plugins']) and the post-meta via
 * $GLOBALS['wpctx_test_post_meta'], then asserts the filter verdict for the
 * Yoast / Rank Math meta shapes and the short-circuit contract.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use WP_Post;
use WPContext\Admin\SEO_Noindex_Detector;

final class SEO_Noindex_Detector_Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpctx_test_active_plugins'] = array();
		$GLOBALS['wpctx_test_post_meta']      = array();
	}

	private function make_post( int $id ): WP_Post {
		$post     = new WP_Post();
		$post->ID = $id;
		return $post;
	}

	private function activate_yoast(): void {
		$GLOBALS['wpctx_test_active_plugins'] = array( 'wordpress-seo/wp-seo.php' );
	}

	private function activate_rank_math(): void {
		$GLOBALS['wpctx_test_active_plugins'] = array( 'seo-by-rank-math/rank-math.php' );
	}

	public function test_already_true_short_circuits_regardless_of_plugin(): void {
		// No SEO plugin active, but a prior subscriber already said noindex —
		// the detector must never flip a true back to false.
		self::assertTrue( SEO_Noindex_Detector::filter_is_noindexed( true, $this->make_post( 1 ) ) );
	}

	public function test_no_seo_plugin_returns_false(): void {
		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 1 ) ) );
	}

	public function test_yoast_noindex_meta_one_is_noindexed(): void {
		$this->activate_yoast();
		$GLOBALS['wpctx_test_post_meta'][5]['_yoast_wpseo_meta-robots-noindex'] = '1';

		self::assertTrue( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 5 ) ) );
	}

	public function test_yoast_index_meta_two_is_not_noindexed(): void {
		$this->activate_yoast();
		$GLOBALS['wpctx_test_post_meta'][5]['_yoast_wpseo_meta-robots-noindex'] = '2';

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 5 ) ) );
	}

	public function test_yoast_absent_meta_is_not_noindexed(): void {
		$this->activate_yoast();

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 5 ) ) );
	}

	public function test_rank_math_robots_array_with_noindex_is_noindexed(): void {
		$this->activate_rank_math();
		$GLOBALS['wpctx_test_post_meta'][9]['rank_math_robots'] = array( 'noindex', 'nofollow' );

		self::assertTrue( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 9 ) ) );
	}

	public function test_rank_math_robots_array_without_noindex_is_not_noindexed(): void {
		$this->activate_rank_math();
		$GLOBALS['wpctx_test_post_meta'][9]['rank_math_robots'] = array( 'index', 'follow' );

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 9 ) ) );
	}

	public function test_rank_math_non_array_meta_is_not_noindexed(): void {
		$this->activate_rank_math();
		$GLOBALS['wpctx_test_post_meta'][9]['rank_math_robots'] = '';

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 9 ) ) );
	}
}
