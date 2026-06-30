<?php
/**
 * Integration test for the Context Score body-quality sampling (#255).
 *
 * Runs inside the wp-phpunit WP test instance so the Signal_Collector reads
 * a real Markdown Views cache table via real `$wpdb`. Seeds rows with empty,
 * noisy, and clean bodies and asserts the sampled signals (`empty_ratio`,
 * `noisy_ratio`, `worst_urls`) the Engine deductions and narrative consume.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Context_Score;

use WP_UnitTestCase;
use Mokhai\Context_Score\Signal_Collector;
use Mokhai\Markdown_Views\Schema as Md_Schema;

final class Signal_Collector_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Md_Schema::drop();
		Md_Schema::create();
	}

	protected function tearDown(): void {
		Md_Schema::drop();
		parent::tearDown();
	}

	/**
	 * Insert a cache row directly so we control the stored body exactly.
	 */
	private function seed_row( int $post_id, string $markdown, ?int $quality_score ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			Md_Schema::table_name(),
			array(
				'post_id'        => $post_id,
				'content_hash'   => str_pad( (string) $post_id, 40, '0', STR_PAD_LEFT ),
				'markdown'       => $markdown,
				'generated_at'   => \current_time( 'mysql', true ),
				'walker_version' => '5',
				'quality_score'  => $quality_score,
				'signals'        => '{}',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	public function test_empty_body_is_detected_and_named(): void {
		$empty_post = self::factory()->post->create( array( 'post_title' => 'Empty Product' ) );
		$good_post  = self::factory()->post->create( array( 'post_title' => 'Good Page' ) );

		$this->seed_row( $empty_post, "\n   \n", 100 );
		$this->seed_row(
			$good_post,
			str_repeat( 'This is a real paragraph of usable prose for an agent. ', 5 ),
			95
		);

		$md = $this->invoke_md_signals();

		self::assertSame( 2, $md['sampled'] );
		self::assertEqualsWithDelta( 0.5, $md['empty_ratio'], 0.001 );
		self::assertSame( 0.0, $md['noisy_ratio'] );

		$titles = array_column( $md['worst_urls'], 'title' );
		self::assertContains( 'Empty Product', $titles );
	}

	public function test_noise_dominated_body_is_detected(): void {
		$noisy_post = self::factory()->post->create( array( 'post_title' => 'Builder Page' ) );

		// A body that is mostly a long base64 builder blob with a little prose.
		$blob = str_repeat( 'JTNDZGl2JTIwY2xhc3M', 12 ); // 228 chars, base64 charset.
		$this->seed_row( $noisy_post, 'Hi. ' . $blob, 100 );

		$md = $this->invoke_md_signals();

		self::assertSame( 1, $md['sampled'] );
		self::assertSame( 1.0, $md['noisy_ratio'] );
	}

	public function test_clean_bodies_yield_zero_rates(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		$this->seed_row( $a, str_repeat( 'Genuine readable content for agents. ', 4 ), 90 );
		$this->seed_row( $b, str_repeat( 'More genuine readable content here. ', 4 ), 88 );

		$md = $this->invoke_md_signals();

		self::assertSame( 0.0, $md['empty_ratio'] );
		self::assertSame( 0.0, $md['noisy_ratio'] );
	}

	/**
	 * Run the full collector and return just the md_cache slice.
	 *
	 * @return array<string, mixed>
	 */
	private function invoke_md_signals(): array {
		$signals = Signal_Collector::collect();
		return is_array( $signals['md_cache'] ?? null ) ? $signals['md_cache'] : array();
	}
}
