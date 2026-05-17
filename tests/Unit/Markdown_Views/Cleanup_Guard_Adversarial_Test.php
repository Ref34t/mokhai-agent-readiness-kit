<?php
/**
 * Adversarial CI gate for the no-hallucination guard per AgDR-0018 AC 5.
 *
 * Each row in the data provider is an adversarial fixture: a source
 * HTML string, an LLM-output Markdown string that contains a fabricated
 * fact/entity/URL/number, and a substring the filtered guard output
 * MUST NOT contain. The CI gate fails the whole test run if any
 * adversarial fixture passes through unstripped.
 *
 * Fixture coverage in this initial commit is intentionally modest (3 per
 * attack class). The AgDR-0018 long-term target is 5+ per class, with
 * expansion driven by production data after v0.1 launch — tracked as a
 * follow-up. The CI mechanism is the durable contract; the corpus
 * grows.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Markdown_Views;

use PHPUnit\Framework\TestCase;
use WPContext\Markdown_Views\Cleanup_Guard;

final class Cleanup_Guard_Adversarial_Test extends TestCase {

	/**
	 * @return iterable<string, array{0: string, 1: string, 2: string}>
	 *   [source_html, llm_output_md, must_not_contain]
	 */
	public function adversarial_provider(): iterable {
		// ── adversarial-fact ─────────────────────────────────────────
		yield 'fabricated-year' => array(
			'<p>The team launched the product to acclaim.</p>',
			'The team launched the product in 1972 to acclaim.',
			'1972',
		);

		yield 'fabricated-statistic' => array(
			'<p>Many users adopted the new feature quickly.</p>',
			'About 87% of users adopted the new feature quickly.',
			'87%',
		);

		yield 'fabricated-claim' => array(
			'<p>The system improved performance for batch processing.</p>',
			'The system reduced costs by half for batch processing.',
			'reduced costs',
		);

		// ── adversarial-entity ───────────────────────────────────────
		yield 'fabricated-person' => array(
			'<p>The team announced its quarterly results.</p>',
			'John Smith and Jane Doe announced quarterly results.',
			'John Smith',
		);

		yield 'fabricated-company' => array(
			'<p>The vendor signed a new partnership deal.</p>',
			'Acme Corporation signed a new partnership deal.',
			'Acme Corporation',
		);

		yield 'fabricated-product-name' => array(
			'<p>The new device hit the shelves last week.</p>',
			'The Stellar Pro Max device hit the shelves last week.',
			'Stellar Pro Max',
		);

		// ── adversarial-url ──────────────────────────────────────────
		yield 'fabricated-domain' => array(
			'<p>Read the documentation on the company website.</p>',
			'Read the documentation at example-fake-docs.com on the company website.',
			'example-fake-docs.com',
		);

		yield 'fabricated-path' => array(
			'<p>The API supports streaming responses for low-latency use cases.</p>',
			'The API at /v3/streaming/realtime supports streaming responses for low-latency use cases.',
			'/v3/streaming/realtime',
		);

		// ── adversarial-number ───────────────────────────────────────
		yield 'fabricated-revenue' => array(
			'<p>The acquisition closed earlier this year.</p>',
			'The 2.3 billion dollar acquisition closed earlier this year.',
			'2.3 billion',
		);

		yield 'fabricated-headcount' => array(
			'<p>The team expanded after the funding round.</p>',
			'The team grew to 450 engineers after the funding round.',
			'450 engineers',
		);
	}

	/**
	 * @dataProvider adversarial_provider
	 */
	public function test_adversarial_fixture_is_stripped(
		string $source_html,
		string $llm_output,
		string $must_not_contain
	): void {
		$allowlist = Cleanup_Guard::build_allowlist( $source_html );
		$entities  = Cleanup_Guard::extract_named_entities(
			\wp_strip_all_tags( $source_html )
		);

		$result = Cleanup_Guard::check( $llm_output, $allowlist, $entities );

		self::assertStringNotContainsString(
			$must_not_contain,
			$result->get_filtered_markdown(),
			'Adversarial content "' . $must_not_contain . '" must be stripped from guard output.'
		);
	}

	/**
	 * @return iterable<string, array{0: string, 1: string, 2: string}>
	 *   [source_html, llm_output_md, must_contain]
	 *
	 * Legitimate-rewording fixtures: the LLM rephrased source content
	 * using only words present in source. The guard must let these
	 * through — false-positive bias is acceptable but not unbounded.
	 */
	public function legit_rewording_provider(): iterable {
		yield 'reorder-clauses' => array(
			'<p>The system processes requests quickly and reliably.</p>',
			'Requests are processed quickly and reliably by the system.',
			'reliably',
		);

		yield 'shorten-redundancy' => array(
			'<p>The cache stores the rendered markdown output for fast retrieval on subsequent reads.</p>',
			'The cache stores rendered markdown for fast retrieval on subsequent reads.',
			'cache stores',
		);
	}

	/**
	 * @dataProvider legit_rewording_provider
	 */
	public function test_legit_rewording_survives(
		string $source_html,
		string $llm_output,
		string $must_contain
	): void {
		$allowlist = Cleanup_Guard::build_allowlist( $source_html );
		$entities  = Cleanup_Guard::extract_named_entities(
			\wp_strip_all_tags( $source_html )
		);

		$result = Cleanup_Guard::check( $llm_output, $allowlist, $entities );

		self::assertStringContainsString(
			$must_contain,
			$result->get_filtered_markdown(),
			'Legitimate rewording should not have been stripped — guard is over-strict.'
		);
		self::assertFalse(
			$result->failed_overall(),
			'Legitimate rewording should not trip the kill switch.'
		);
	}
}
