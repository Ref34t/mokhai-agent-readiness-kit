<?php
/**
 * Unit tests for WPContext\Ai\Result.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use WPContext\Ai\Result;

final class Result_Test extends TestCase {

	public function test_success_result_reports_from_llm_true(): void {
		$r = new Result( true, false, 'llm-output', null );

		self::assertTrue( $r->from_llm() );
		self::assertFalse( $r->needs_retry() );
		self::assertSame( 'llm-output', $r->content() );
		self::assertNull( $r->error_code() );
	}

	public function test_unconfigured_result_carries_error_code(): void {
		$r = new Result( false, false, null, 'unconfigured' );

		self::assertFalse( $r->from_llm() );
		self::assertFalse( $r->needs_retry() );
		self::assertNull( $r->content() );
		self::assertSame( 'unconfigured', $r->error_code() );
	}

	public function test_needs_retry_result_carries_rate_limit_code(): void {
		$r = new Result( false, true, null, 'rate_limit' );

		self::assertFalse( $r->from_llm() );
		self::assertTrue( $r->needs_retry() );
		self::assertNull( $r->content() );
		self::assertSame( 'rate_limit', $r->error_code() );
	}

	public function test_network_failure_result_carries_network_code(): void {
		$r = new Result( false, true, null, 'network' );

		self::assertSame( 'network', $r->error_code() );
		self::assertTrue( $r->needs_retry() );
	}
}
