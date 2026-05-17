<?php
/**
 * No-hallucination guard for LLM-cleaned Markdown output per AgDR-0018.
 *
 * Two-stage deterministic filter applied to every cleanup result before
 * it's allowed near the public route:
 *
 * Stage 1 — Content-word allowlist. Tokenize the source HTML (lowercased,
 * unicode-normalised, punctuation-stripped, stopworded, light-stemmed)
 * into an allowed-word set. Then split the LLM output into sentences;
 * any sentence containing a content-word (length >= 3, not a stopword)
 * absent from the allowlist is dropped.
 *
 * Stage 2 — Named-entity recheck. Extract capitalised multi-word
 * sequences (the high-risk hallucination class — fabricated brand
 * names, person names) from both source and output. Any sentence whose
 * named entity isn't in the source set is dropped.
 *
 * Kill switch: if more than `FAILURE_RATIO_THRESHOLD` of input
 * sentences get dropped, the orchestrator treats the cleanup as
 * broken and serves the deterministic version + flags needs-retry.
 *
 * No WordPress dependency. Pure functions over strings. Unit-testable
 * against the committed adversarial fixture set.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Markdown_Views;

\defined( 'ABSPATH' ) || exit;

/**
 * The guard's two stages are intentionally cheap and explainable. We
 * accept a strict false-positive bias (legitimate paraphrases stripped
 * as collateral) because a single hallucination on a public `.md`
 * route is a brand-damage event for the user's site; a stripped
 * paraphrase is recovered by the deterministic-MD fallback.
 */
final class Cleanup_Guard {

	/**
	 * If sentences dropped / (kept + dropped) exceeds this ratio, the
	 * whole cleanup is treated as failed (`failed_overall()` returns
	 * true). The orchestrator discards the cleaned output and flags
	 * the post `needs-retry`.
	 *
	 * @var float
	 */
	public const FAILURE_RATIO_THRESHOLD = 0.5;

	/**
	 * Below this length, individual tokens are exempt from the allowlist
	 * check. Two-letter content words ("us", "uk", "ai") would otherwise
	 * generate noise; the named-entity stage catches the brand-name case.
	 *
	 * @var int
	 */
	private const MIN_TOKEN_LENGTH = 3;

	/**
	 * Stopwords are removed from the allowlist build and skipped when
	 * checking the output. English-only in v0.1 — multilingual sites
	 * see higher false-positive rates on non-English content per the
	 * AgDR-0018 deferred-decision list.
	 *
	 * @var array<int, string>
	 */
	private const STOPWORDS = array(
		'a',
		'an',
		'and',
		'are',
		'as',
		'at',
		'be',
		'but',
		'by',
		'for',
		'from',
		'has',
		'have',
		'he',
		'her',
		'his',
		'i',
		'if',
		'in',
		'into',
		'is',
		'it',
		'its',
		'me',
		'my',
		'no',
		'not',
		'of',
		'on',
		'or',
		'our',
		'she',
		'so',
		'than',
		'that',
		'the',
		'their',
		'them',
		'they',
		'this',
		'to',
		'too',
		'up',
		'us',
		'was',
		'we',
		'were',
		'what',
		'when',
		'where',
		'which',
		'who',
		'why',
		'will',
		'with',
		'you',
		'your',
		'yours',
	);

	/**
	 * Build the content-word allowlist from a source HTML string.
	 *
	 * Pipeline: strip tags → lowercase → NFC-normalise → strip
	 * punctuation → tokenize on whitespace → drop stopwords → light
	 * stem. The result is the set of "known" content-word stems for
	 * the source.
	 *
	 * @return array<string, true> Allowlist keyed by stemmed token (faster lookup).
	 */
	public static function build_allowlist( string $source_html ): array {
		$text = \wp_strip_all_tags( $source_html );
		$text = self::normalise( $text );

		return self::tokenize_to_set( $text );
	}

	/**
	 * Run the two-stage guard over the LLM output.
	 *
	 * @param string                  $llm_output      Markdown text the LLM produced.
	 * @param array<string, true>     $allowlist       Pre-built via build_allowlist().
	 * @param array<int, string>      $source_entities Pre-extracted via extract_named_entities() over source.
	 */
	public static function check(
		string $llm_output,
		array $allowlist,
		array $source_entities
	): Guard_Result {
		$sentences = self::split_into_sentences( $llm_output );

		if ( array() === $sentences ) {
			return new Guard_Result(
				'',
				array(
					'sentences_kept'    => 0,
					'sentences_dropped' => 0,
				),
				array(),
				false 
			);
		}

		// Deduplicated entity-set for O(1) membership tests.
		$source_entity_set = array();
		foreach ( $source_entities as $entity ) {
			$source_entity_set[ self::normalise( $entity ) ] = true;
		}

		$kept    = array();
		$dropped = array();

		foreach ( $sentences as $sentence ) {
			$stage_1 = self::stage_one_check( $sentence, $allowlist );

			if ( null !== $stage_1 ) {
				$dropped[] = array(
					'sentence' => $sentence,
					'stage'    => 'allowlist',
					'tokens'   => $stage_1,
				);
				continue;
			}

			$stage_2 = self::stage_two_check( $sentence, $source_entity_set );

			if ( null !== $stage_2 ) {
				$dropped[] = array(
					'sentence' => $sentence,
					'stage'    => 'entity',
					'entity'   => $stage_2,
				);
				continue;
			}

			$kept[] = $sentence;
		}

		$filtered = \implode( ' ', $kept );

		$total = \count( $kept ) + \count( $dropped );
		$ratio = $total > 0 ? \count( $dropped ) / $total : 0.0;

		return new Guard_Result(
			$filtered,
			array(
				'sentences_kept'    => \count( $kept ),
				'sentences_dropped' => \count( $dropped ),
			),
			$dropped,
			$ratio > self::FAILURE_RATIO_THRESHOLD
		);
	}

	/**
	 * Extract capitalised multi-word sequences from text. These are the
	 * high-risk hallucination class — fabricated proper nouns / brand
	 * names. Single-word capitalised tokens ("Apple") fall through to
	 * Stage 1's allowlist check by design.
	 *
	 * @return array<int, string>
	 */
	public static function extract_named_entities( string $text ): array {
		$matches = array();
		\preg_match_all( '/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+\b/u', $text, $matches );

		return $matches[0];
	}

	/**
	 * Stage 1: return null if every content-word in the sentence is in
	 * the allowlist; otherwise return the list of offending tokens.
	 *
	 * @param array<string, true> $allowlist
	 *
	 * @return array<int, string>|null
	 */
	private static function stage_one_check( string $sentence, array $allowlist ): ?array {
		$normalised = self::normalise( $sentence );
		$tokens     = self::tokenize( $normalised );

		$offenders = array();

		foreach ( $tokens as $token ) {
			if ( \strlen( $token ) < self::MIN_TOKEN_LENGTH ) {
				continue;
			}

			if ( \in_array( $token, self::STOPWORDS, true ) ) {
				continue;
			}

			$stem = self::light_stem( $token );

			if ( ! isset( $allowlist[ $stem ] ) ) {
				$offenders[] = $token;
			}
		}

		return array() === $offenders ? null : $offenders;
	}

	/**
	 * Stage 2: return null if every named entity in the sentence
	 * appears in the source entity set; otherwise return the first
	 * offending entity name.
	 *
	 * @param array<string, true> $source_entity_set Normalised source entities.
	 */
	private static function stage_two_check( string $sentence, array $source_entity_set ): ?string {
		$entities = self::extract_named_entities( $sentence );

		foreach ( $entities as $entity ) {
			$key = self::normalise( $entity );

			if ( ! isset( $source_entity_set[ $key ] ) ) {
				return $entity;
			}
		}

		return null;
	}

	/**
	 * Split a Markdown string into sentences for per-sentence checking.
	 *
	 * Conservative tokenisation: split on `.`, `!`, `?` followed by
	 * whitespace or end-of-string. We DO NOT remove markdown markup
	 * before splitting — the sentence we keep retains its original
	 * formatting so the filtered output remains valid MD.
	 *
	 * @return array<int, string>
	 */
	private static function split_into_sentences( string $md ): array {
		$md = \trim( $md );

		if ( '' === $md ) {
			return array();
		}

		$parts = \preg_split( '/(?<=[.!?])\s+/u', $md );

		if ( false === $parts ) {
			return array();
		}

		$sentences = array();
		foreach ( $parts as $part ) {
			$trimmed = \trim( $part );
			if ( '' !== $trimmed ) {
				$sentences[] = $trimmed;
			}
		}

		return $sentences;
	}

	/**
	 * Lowercase + NFC-normalise + strip punctuation. Result is suitable
	 * as a comparison key in allowlist / entity-set hash maps.
	 */
	private static function normalise( string $text ): string {
		$text = \mb_strtolower( $text, 'UTF-8' );

		if ( \class_exists( '\Normalizer' ) ) {
			$normalised = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			if ( false !== $normalised ) {
				$text = $normalised;
			}
		}

		$text = (string) \preg_replace( '/[\p{P}\p{S}]+/u', ' ', $text );
		$text = (string) \preg_replace( '/\s+/u', ' ', $text );

		return \trim( $text );
	}

	/**
	 * Tokenize a normalised string into a stemmed-token allowlist
	 * (string -> true), suitable for O(1) membership tests.
	 *
	 * @return array<string, true>
	 */
	private static function tokenize_to_set( string $normalised ): array {
		$set = array();

		foreach ( self::tokenize( $normalised ) as $token ) {
			if ( \strlen( $token ) < self::MIN_TOKEN_LENGTH ) {
				continue;
			}

			if ( \in_array( $token, self::STOPWORDS, true ) ) {
				continue;
			}

			$set[ self::light_stem( $token ) ] = true;
		}

		return $set;
	}

	/**
	 * Split a normalised string into whitespace-separated tokens.
	 *
	 * @return array<int, string>
	 */
	private static function tokenize( string $normalised ): array {
		if ( '' === $normalised ) {
			return array();
		}

		$tokens = \preg_split( '/\s+/u', $normalised );

		return false === $tokens ? array() : $tokens;
	}

	/**
	 * Porter-light stemming: strip a small set of trailing suffixes
	 * that account for most English inflection. Not full Porter — we
	 * want determinism and explainability, not linguistic accuracy.
	 *
	 * Examples:
	 *   running   → run
	 *   played    → play
	 *   apples    → apple
	 *   houses    → house
	 *   carries   → carrie  (over-strip; acceptable noise)
	 */
	private static function light_stem( string $token ): string {
		$suffixes = array( 'ing', 'ed', 'es', 's' );

		foreach ( $suffixes as $suffix ) {
			$suffix_length = \strlen( $suffix );
			if ( \strlen( $token ) <= $suffix_length + 2 ) {
				continue;
			}

			if ( \substr( $token, -$suffix_length ) === $suffix ) {
				return \substr( $token, 0, -$suffix_length );
			}
		}

		return $token;
	}
}
