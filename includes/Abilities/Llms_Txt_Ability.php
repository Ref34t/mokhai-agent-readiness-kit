<?php
/**
 * `ai-readiness-kit/llms-txt-regenerate` ability (#21 / AgDR-0044).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Abilities;

use Mokhai\LlmsTxt\Service;

\defined( 'ABSPATH' ) || exit;

/**
 * Regenerate the cached /llms.txt body synchronously and return it.
 *
 * Wraps `LlmsTxt\Service::regen_sync()` — composes the document, writes the
 * cache, and returns the body. Returns the content plus its byte length so an
 * agent can confirm a non-empty regeneration without a second fetch.
 */
final class Llms_Txt_Ability {

	/**
	 * Stable ability ID.
	 *
	 * @var string
	 */
	public const ID        = 'mokhai/llms-txt-regenerate';
	public const LEGACY_ID = 'ai-readiness-kit/llms-txt-regenerate';

	/**
	 * Execute callback. Takes no input; returns the regenerated body.
	 *
	 * @param mixed $input Validated ability input (unused — no parameters).
	 *
	 * @return array{content: string, bytes: int}
	 */
	public static function regenerate( $input = null ): array {
		unset( $input );

		$body = Service::regen_sync();

		return array(
			'content' => $body,
			'bytes'   => \strlen( $body ),
		);
	}
}
