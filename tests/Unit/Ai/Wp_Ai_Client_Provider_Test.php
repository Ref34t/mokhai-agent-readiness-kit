<?php
/**
 * Unit tests for the real WP AI Client provider.
 *
 * Drives the provider against the `wp_ai_client_prompt()` stub in
 * tests/Unit/wp-stubs.php — a fluent builder that records every chain
 * call into `$GLOBALS['wpctx_test_ai_calls']` and returns whatever the
 * test placed in `$GLOBALS['wpctx_test_ai_response']`. Covers:
 *
 *  - Happy path: string response is returned verbatim
 *  - WP_Error with rate-limit marker → Rate_Limit_Error thrown
 *  - WP_Error without rate-limit marker → Network_Error thrown
 *  - Missing `wp_ai_client_prompt()` → Network_Error
 *  - Option propagation: temperature / max_tokens / system instruction
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use WPContext\Ai\Network_Error;
use WPContext\Ai\Rate_Limit_Error;
use WPContext\Ai\Wp_Ai_Client_Provider;
use WP_Error;

final class Wp_Ai_Client_Provider_Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpctx_test_ai_calls']    = array();
		$GLOBALS['wpctx_test_ai_response'] = '';
	}

	public function test_returns_string_on_success(): void {
		$GLOBALS['wpctx_test_ai_response'] = 'clean markdown output';

		$provider = new Wp_Ai_Client_Provider();
		$out      = $provider->generate( 'prompt body', array() );

		self::assertSame( 'clean markdown output', $out );
	}

	public function test_wp_error_with_rate_limit_message_throws_rate_limit_error(): void {
		$GLOBALS['wpctx_test_ai_response'] = new WP_Error(
			'prompt_builder_error',
			'Provider rate limit exceeded — retry after 60s.'
		);

		$this->expectException( Rate_Limit_Error::class );
		( new Wp_Ai_Client_Provider() )->generate( 'prompt', array() );
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public function rate_limit_marker_provider(): iterable {
		yield 'plain phrase'  => array( 'Anthropic rate limit hit; retry later.' );
		yield 'hyphenated'    => array( 'Got rate-limit response.' );
		yield 'underscored'   => array( 'rate_limit error code.' );
		yield 'http 429'      => array( 'HTTP 429 Too Many Requests.' );
		yield 'too-many'      => array( 'Too Many Requests from provider.' );
		yield 'quota'         => array( 'Monthly quota exceeded for project.' );
		yield 'uppercase'     => array( 'RATE LIMIT.' );
	}

	/**
	 * @dataProvider rate_limit_marker_provider
	 */
	public function test_all_rate_limit_markers_classify_as_rate_limit_error( string $message ): void {
		$GLOBALS['wpctx_test_ai_response'] = new WP_Error( 'prompt_builder_error', $message );

		$this->expectException( Rate_Limit_Error::class );
		( new Wp_Ai_Client_Provider() )->generate( 'prompt', array() );
	}

	public function test_wp_error_without_rate_limit_message_throws_network_error(): void {
		$GLOBALS['wpctx_test_ai_response'] = new WP_Error(
			'prompt_builder_error',
			'Connection refused; provider unreachable.'
		);

		$this->expectException( Network_Error::class );
		( new Wp_Ai_Client_Provider() )->generate( 'prompt', array() );
	}

	public function test_wp_error_with_empty_message_falls_back_to_network_error(): void {
		$GLOBALS['wpctx_test_ai_response'] = new WP_Error( 'prompt_builder_error', '' );

		$this->expectException( Network_Error::class );
		( new Wp_Ai_Client_Provider() )->generate( 'prompt', array() );
	}

	public function test_option_propagation_temperature_and_max_tokens(): void {
		$GLOBALS['wpctx_test_ai_response'] = 'ok';

		( new Wp_Ai_Client_Provider() )->generate(
			'prompt',
			array(
				'temperature' => 0.3,
				'max_tokens'  => 512,
			)
		);

		$calls   = $GLOBALS['wpctx_test_ai_calls'];
		$methods = array_column( $calls, 'method' );

		self::assertContains( 'wp_ai_client_prompt', $methods );
		self::assertContains( 'using_temperature', $methods );
		self::assertContains( 'using_max_tokens', $methods );
		self::assertContains( 'generate_text', $methods );
	}

	public function test_option_propagation_system_instruction(): void {
		$GLOBALS['wpctx_test_ai_response'] = 'ok';

		( new Wp_Ai_Client_Provider() )->generate(
			'prompt',
			array( 'system' => 'You are a careful editor.' )
		);

		$methods = array_column( $GLOBALS['wpctx_test_ai_calls'], 'method' );
		self::assertContains( 'using_system_instruction', $methods );
	}

	public function test_unknown_options_are_ignored(): void {
		$GLOBALS['wpctx_test_ai_response'] = 'ok';

		( new Wp_Ai_Client_Provider() )->generate(
			'prompt',
			array(
				'temperature' => 0.5,
				'invented_knob' => 'ignored',
				'another'       => 42,
			)
		);

		$methods = array_column( $GLOBALS['wpctx_test_ai_calls'], 'method' );

		self::assertContains( 'using_temperature', $methods );
		// Builder should NOT have been called for unknown options.
		self::assertNotContains( 'using_invented_knob', $methods );
		self::assertNotContains( 'using_another', $methods );
	}

	public function test_non_numeric_temperature_is_silently_skipped(): void {
		$GLOBALS['wpctx_test_ai_response'] = 'ok';

		( new Wp_Ai_Client_Provider() )->generate(
			'prompt',
			array( 'temperature' => 'hot' )
		);

		$methods = array_column( $GLOBALS['wpctx_test_ai_calls'], 'method' );
		self::assertNotContains( 'using_temperature', $methods );
	}
}
