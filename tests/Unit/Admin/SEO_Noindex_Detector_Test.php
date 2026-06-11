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
		SEO_Noindex_Detector::reset_runtime_cache();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		SEO_Noindex_Detector::reset_runtime_cache();
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

	// ---- AIOSEO (#187) — robots flags live in the wp_aioseo_posts table ----

	private function activate_aioseo(): void {
		$GLOBALS['wpctx_test_active_plugins'] = array( 'all-in-one-seo-pack/all_in_one_seo_pack.php' );
	}

	/**
	 * Minimal wpdb fake covering the three calls aioseo_is_noindexed makes:
	 * prepare() (passthrough substitution), get_var() (SHOW TABLES probe),
	 * get_row() (per-post flags keyed by post_id).
	 *
	 * @param bool                 $table_exists Whether the probe finds the table.
	 * @param array<int, object>   $rows         post_id => row object.
	 */
	private function fake_wpdb( bool $table_exists, array $rows = array() ): void {
		$GLOBALS['wpdb'] = new class( $table_exists, $rows ) {
			public $prefix = 'wp_';

			/** @var bool */
			private $table_exists;

			/** @var array<int, object> */
			private $rows;

			public function __construct( bool $table_exists, array $rows ) {
				$this->table_exists = $table_exists;
				$this->rows         = $rows;
			}

			public function prepare( string $query, ...$args ): string {
				return \vsprintf( \str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
			}

			public function get_var( string $query ): ?string {
				return $this->table_exists ? $this->prefix . 'aioseo_posts' : null;
			}

			public function get_row( string $query ): ?object {
				\preg_match( '/post_id = (\d+)/', $query, $m );
				return $this->rows[ (int) ( $m[1] ?? 0 ) ] ?? null;
			}
		};
	}

	public function test_aioseo_explicit_noindex_row_is_noindexed(): void {
		$this->activate_aioseo();
		$this->fake_wpdb(
			true,
			array(
				7 => (object) array(
					'robots_default' => 0,
					'robots_noindex' => 1,
				),
			)
		);

		self::assertTrue( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 7 ) ) );
	}

	public function test_aioseo_robots_default_defers_to_index(): void {
		$this->activate_aioseo();
		$this->fake_wpdb(
			true,
			array(
				7 => (object) array(
					'robots_default' => 1,
					'robots_noindex' => 1,
				),
			)
		);

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 7 ) ) );
	}

	public function test_aioseo_missing_row_is_not_noindexed(): void {
		$this->activate_aioseo();
		$this->fake_wpdb( true, array() );

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 7 ) ) );
	}

	public function test_aioseo_missing_table_degrades_to_not_noindexed(): void {
		$this->activate_aioseo();
		$this->fake_wpdb( false );

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 7 ) ) );
	}

	public function test_aioseo_explicit_index_row_is_not_noindexed(): void {
		$this->activate_aioseo();
		$this->fake_wpdb(
			true,
			array(
				7 => (object) array(
					'robots_default' => 0,
					'robots_noindex' => 0,
				),
			)
		);

		self::assertFalse( SEO_Noindex_Detector::filter_is_noindexed( false, $this->make_post( 7 ) ) );
	}
}
