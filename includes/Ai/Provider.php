<?php
/**
 * Provider interface — test seam for Client_Wrapper.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Ai;

\defined( 'ABSPATH' ) || exit;

/**
 * Single-method seam that lets tests inject a fake provider into
 * Client_Wrapper::generate without touching the real WP AI Client.
 *
 * Per AgDR-0003, v0.1 ships only the interface — no production
 * implementation. The real provider lands when #6 wires the first LLM
 * caller, at which point a concrete `Wp_Ai_Client_Provider` will join this
 * namespace.
 */
interface Provider {

	/**
	 * Generate text for the given prompt.
	 *
	 * @param string $prompt  The prompt to send.
	 * @param array  $options Provider-specific options.
	 *
	 * @throws Network_Error    On transient network failure (retryable).
	 * @throws Rate_Limit_Error On provider rate-limit (deferred retry).
	 * @throws Permanent_Error  On a non-retryable 4xx failure (parameter validation, auth, not-found, etc.).
	 *
	 * @return string The generated content.
	 */
	public function generate( string $prompt, array $options = array() ): string;
}
