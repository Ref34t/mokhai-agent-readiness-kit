<?php
/**
 * Unit tests for WPContext\Ai\Client_Wrapper.
 *
 * Ports the inline smoke test from #2's PR description into PHPUnit form.
 * Covers the 5 paths defined in AgDR-0003:
 *   - success-first-try
 *   - retry-recovers (network → retry → success)
 *   - always-network (exhausted attempts → deferred retry)
 *   - rate-limit (no in-request retry → deferred retry)
 *   - unconfigured (no provider, no WP AI Client)
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use WPContext\Ai\Client_Wrapper;
use WPContext\Ai\Network_Error;
use WPContext\Ai\Provider;
use WPContext\Ai\Rate_Limit_Error;
use WPContext\Ai\Result;

final class Client_Wrapper_Test extends TestCase {

	protected function setUp(): void {
		// Reset the cron-queue stub between tests.
		$GLOBALS['wpctx_test_cron_queue'] = array();
	}

	public function test_success_on_first_try(): void {
		$provider = $this->success_provider( 'llm-output' );

		$result = Client_Wrapper::generate( 'prompt', array(), $provider );

		self::assertInstanceOf( Result::class, $result );
		self::assertTrue( $result->from_llm() );
		self::assertFalse( $result->needs_retry() );
		self::assertSame( 'llm-output', $result->content() );
		self::assertNull( $result->error_code() );
		self::assertSame( 1, $provider->calls );
		self::assertCount( 0, $GLOBALS['wpctx_test_cron_queue'] );
	}

	public function test_network_error_then_retry_succeeds(): void {
		$provider = $this->network_then_success_provider( 'recovered' );

		$result = Client_Wrapper::generate( 'prompt', array(), $provider );

		self::assertTrue( $result->from_llm() );
		self::assertSame( 'recovered', $result->content() );
		self::assertSame( 2, $provider->calls, 'provider should have been called twice (initial + retry)' );
		self::assertCount( 0, $GLOBALS['wpctx_test_cron_queue'], 'no deferred retry should be queued on success' );
	}

	public function test_always_network_exhausts_attempts_and_queues_deferred_retry(): void {
		$provider = $this->always_network_provider();

		$result = Client_Wrapper::generate( 'prompt', array(), $provider );

		self::assertFalse( $result->from_llm() );
		self::assertTrue( $result->needs_retry() );
		self::assertNull( $result->content() );
		self::assertSame( 'network', $result->error_code() );
		self::assertSame( 2, $provider->calls );
		self::assertCount( 1, $GLOBALS['wpctx_test_cron_queue'] );
		self::assertSame( Client_Wrapper::RETRY_ACTION, $GLOBALS['wpctx_test_cron_queue'][0]['hook'] );
	}

	public function test_rate_limit_skips_in_request_retry_and_queues_deferred(): void {
		$provider = $this->always_rate_limit_provider();

		$result = Client_Wrapper::generate( 'prompt', array(), $provider );

		self::assertFalse( $result->from_llm() );
		self::assertTrue( $result->needs_retry() );
		self::assertSame( 'rate_limit', $result->error_code() );
		self::assertSame( 1, $provider->calls, 'rate_limit must not trigger in-request retry' );
		self::assertCount( 1, $GLOBALS['wpctx_test_cron_queue'] );
	}

	public function test_unconfigured_returns_fallback_without_calling_provider(): void {
		// No provider injection AND no WP AI Client function/class defined =
		// returns the 'unconfigured' Result immediately.
		$result = Client_Wrapper::generate( 'prompt' );

		self::assertFalse( $result->from_llm() );
		self::assertFalse( $result->needs_retry() );
		self::assertNull( $result->content() );
		self::assertSame( 'unconfigured', $result->error_code() );
		self::assertCount( 0, $GLOBALS['wpctx_test_cron_queue'] );
	}

	public function test_has_ai_client_returns_false_outside_wp(): void {
		// Sanity check the detector — neither WP_AI_Client class nor
		// wp_ai_client() function exists in the unit-test environment.
		self::assertFalse( Client_Wrapper::has_ai_client() );
	}

	// ----------------------------------------------------------------------
	// Provider doubles
	// ----------------------------------------------------------------------

	private function success_provider( string $content ): Provider {
		return new class( $content ) implements Provider {
			public int $calls = 0;
			private string $content;

			public function __construct( string $content ) {
				$this->content = $content;
			}

			public function generate( string $prompt, array $options = array() ): string {
				++$this->calls;
				return $this->content;
			}
		};
	}

	private function network_then_success_provider( string $second_content ): Provider {
		return new class( $second_content ) implements Provider {
			public int $calls = 0;
			private string $content;

			public function __construct( string $content ) {
				$this->content = $content;
			}

			public function generate( string $prompt, array $options = array() ): string {
				++$this->calls;
				if ( 1 === $this->calls ) {
					throw new Network_Error( 'transient' );
				}
				return $this->content;
			}
		};
	}

	private function always_network_provider(): Provider {
		return new class implements Provider {
			public int $calls = 0;

			public function generate( string $prompt, array $options = array() ): string {
				++$this->calls;
				throw new Network_Error( 'hard' );
			}
		};
	}

	private function always_rate_limit_provider(): Provider {
		return new class implements Provider {
			public int $calls = 0;

			public function generate( string $prompt, array $options = array() ): string {
				++$this->calls;
				throw new Rate_Limit_Error( 'throttled' );
			}
		};
	}
}
