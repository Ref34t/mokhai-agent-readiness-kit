<?php
/**
 * Unit tests for `WPContext\Ai_Preview\Summary_Generator`.
 *
 * Covers the synchronous Sample AI Summary paths (#45 / AgDR-0046):
 *   - LLM success → caches text in post-meta, source='llm'.
 *   - Empty markdown input → 'empty_input' degrade, no client call.
 *   - Empty model output → 'empty_output' degrade.
 *   - Provider raises Permanent_Error → 'permanent' degrade.
 *   - Provider raises Network_Error (twice) → 'needs_retry' degrade.
 *   - Output sanitised + capped at MAX_OUTPUT_CHARS.
 *   - build_prompt caps the input at MAX_INPUT_CHARS.
 *   - get_cached round-trips the stored value, returns null when absent.
 *
 * Uses the same Provider-injection seam as Narrative_Generator_Test so the
 * real WP AI Client is never reached. WP functions (post-meta, strip-tags)
 * come from tests/Unit/wp-stubs.php.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Ai_Preview;

use PHPUnit\Framework\TestCase;
use WPContext\Ai\Network_Error;
use WPContext\Ai\Permanent_Error;
use WPContext\Ai\Provider;
use WPContext\Ai_Preview\Summary_Generator;

final class Summary_Generator_Test extends TestCase {

	private const POST_ID = 42;

	protected function setUp(): void {
		// Isolate cron + post-meta side effects between tests.
		$GLOBALS['wpctx_test_cron_queue'] = array();
		$GLOBALS['wpctx_test_post_meta']  = array();
	}

	public function test_llm_success_returns_text_and_caches_it(): void {
		$result = Summary_Generator::generate(
			self::POST_ID,
			'This is the markdown view of a page about widgets.',
			$this->text_provider( 'This page explains how widgets work and where to buy them.' )
		);

		self::assertSame( 'llm', $result['source'] );
		self::assertSame( 'This page explains how widgets work and where to buy them.', $result['text'] );
		self::assertIsString( $result['generated_at'] );
		self::assertNotSame( '', $result['generated_at'] );

		// Cached in post-meta and readable via get_cached.
		$cached = Summary_Generator::get_cached( self::POST_ID );
		self::assertNotNull( $cached );
		self::assertSame( $result['text'], $cached['text'] );
		self::assertSame( $result['generated_at'], $cached['generated_at'] );
	}

	public function test_empty_markdown_degrades_to_empty_input_without_calling_provider(): void {
		$provider = $this->text_provider( 'should not be used' );

		$result = Summary_Generator::generate( self::POST_ID, '   ', $provider );

		self::assertNull( $result['text'] );
		self::assertSame( 'empty_input', $result['state'] );
		self::assertSame( 0, $provider->calls, 'provider must not be called on empty input' );
		self::assertNull( Summary_Generator::get_cached( self::POST_ID ) );
	}

	public function test_empty_model_output_degrades_to_empty_output(): void {
		$result = Summary_Generator::generate(
			self::POST_ID,
			'Non-empty markdown.',
			$this->text_provider( '   ' )
		);

		self::assertNull( $result['text'] );
		self::assertSame( 'empty_output', $result['state'] );
		self::assertNull( Summary_Generator::get_cached( self::POST_ID ) );
	}

	public function test_permanent_error_degrades_to_permanent(): void {
		$result = Summary_Generator::generate(
			self::POST_ID,
			'Non-empty markdown.',
			$this->permanent_provider()
		);

		self::assertNull( $result['text'] );
		self::assertSame( 'permanent', $result['state'] );
		self::assertNotSame( '', $result['message'] );
	}

	public function test_network_error_degrades_to_needs_retry(): void {
		$result = Summary_Generator::generate(
			self::POST_ID,
			'Non-empty markdown.',
			$this->network_provider()
		);

		self::assertNull( $result['text'] );
		self::assertSame( 'needs_retry', $result['state'] );
	}

	public function test_output_is_sanitised_and_capped(): void {
		$long = str_repeat( 'A', Summary_Generator::MAX_OUTPUT_CHARS + 200 );

		$result = Summary_Generator::generate(
			self::POST_ID,
			'Non-empty markdown.',
			$this->text_provider( "<p>{$long}</p>" )
		);

		self::assertSame( 'llm', $result['source'] );
		self::assertLessThanOrEqual(
			Summary_Generator::MAX_OUTPUT_CHARS,
			mb_strlen( $result['text'] )
		);
		// HTML stripped.
		self::assertStringNotContainsString( '<p>', $result['text'] );
	}

	public function test_build_prompt_caps_input(): void {
		$markdown = str_repeat( 'x', Summary_Generator::MAX_INPUT_CHARS + 500 );

		$prompt = Summary_Generator::build_prompt( $markdown );

		// Prompt = prefix + capped markdown; the markdown portion must not
		// exceed the cap.
		self::assertStringContainsString( 'Page content:', $prompt );
		self::assertLessThanOrEqual(
			Summary_Generator::MAX_INPUT_CHARS + 32, // + short prefix
			mb_strlen( $prompt )
		);
	}

	public function test_get_cached_returns_null_when_absent(): void {
		self::assertNull( Summary_Generator::get_cached( 999 ) );
	}

	// ------------------------------------------------------------------
	// Provider doubles
	// ------------------------------------------------------------------

	/**
	 * @return Provider&object{calls: int}
	 */
	private function text_provider( string $response ): Provider {
		return new class( $response ) implements Provider {
			public int $calls = 0;
			private string $response;

			public function __construct( string $response ) {
				$this->response = $response;
			}

			public function generate( string $prompt, array $options = array() ): string {
				++$this->calls;
				return $this->response;
			}
		};
	}

	private function permanent_provider(): Provider {
		return new class implements Provider {
			public function generate( string $prompt, array $options = array() ): string {
				throw new Permanent_Error( 'bad request 400' );
			}
		};
	}

	private function network_provider(): Provider {
		return new class implements Provider {
			public function generate( string $prompt, array $options = array() ): string {
				throw new Network_Error( 'connection reset' );
			}
		};
	}
}
