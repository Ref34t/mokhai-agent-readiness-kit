<?php
/**
 * Integration tests for WPContext\LlmsTxt\Conflict_Notice — AJAX dismiss surface.
 *
 * Pins the contract of `Conflict_Notice::handle_dismiss()` (#7 Phase B /
 * AgDR-0023): capability + nonce + fingerprint-format guards plus per-user
 * dismissal storage. Uses `WP_Ajax_UnitTestCase` so we can dispatch the
 * registered `wp_ajax_*` action and capture `wp_die` via exceptions.
 *
 * Sibling test files (#62 cache invalidation, #63 HTML output) will be
 * appended to this class — keep the setUp / tearDown scope narrow so future
 * tests can join without restructure.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\LlmsTxt;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;
use WPContext\LlmsTxt\Conflict_Notice;

final class Conflict_Notice_Test extends WP_Ajax_UnitTestCase {

	/**
	 * A valid 40-char lowercase hex fingerprint. Matches the
	 * `/^[a-f0-9]{40}$/` regex in `Conflict_Notice::handle_dismiss()`.
	 */
	private const VALID_FINGERPRINT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334';

	/**
	 * Second valid fingerprint — used to verify dismissals accumulate per
	 * user without colliding with `VALID_FINGERPRINT`.
	 */
	private const VALID_FINGERPRINT_2 = '00112233445566778899aabbccddeeff00112233';

	protected function setUp(): void {
		parent::setUp();

		// Reset the dismiss-action POST surface so each test starts with a
		// known-clean `$_POST`. WP_Ajax_UnitTestCase also resets the response
		// buffer in its own setUp, so we only need to manage our payload.
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();

		// Drop any per-user dismissals written during the test so a leaked
		// row can't influence the next case. The factory user ids change
		// per test, but the meta key is fixed — clearing both factory ids'
		// rows would require tracking them; instead we delete by key for
		// every user that exists, which is cheap in a test DB.
		global $wpdb;
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => Conflict_Notice::USER_META_KEY ) );

		parent::tearDown();
	}

	/**
	 * Happy path — admin user, valid nonce, valid 40-char hex fingerprint.
	 * Expected: JSON success, user-meta updated with the fingerprint.
	 */
	public function test_handle_dismiss_admin_with_valid_nonce_and_fingerprint_returns_success(): void {
		$this->_setRole( 'administrator' );
		$user_id = get_current_user_id();

		$_POST['_wpnonce']    = wp_create_nonce( Conflict_Notice::DISMISS_ACTION );
		$_POST['fingerprint'] = self::VALID_FINGERPRINT;

		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_success.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected — wp_send_json_success calls wp_die() in test mode.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		$this->assertTrue( $response['success'] );

		$stored = get_user_meta( $user_id, Conflict_Notice::USER_META_KEY, true );
		$this->assertIsArray( $stored );
		$this->assertContains( self::VALID_FINGERPRINT, $stored );
	}

	/**
	 * Capability failure — non-admin (subscriber) user. `handle_dismiss`
	 * calls `wp_send_json_error( ..., 403 )` before nonce verification.
	 */
	public function test_handle_dismiss_non_admin_user_returns_403(): void {
		$this->_setRole( 'subscriber' );
		$user_id = get_current_user_id();

		$_POST['_wpnonce']    = wp_create_nonce( Conflict_Notice::DISMISS_ACTION );
		$_POST['fingerprint'] = self::VALID_FINGERPRINT;

		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_error(403).' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected — capability guard fires.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
		$this->assertFalse( $response['success'] );
		$this->assertArrayHasKey( 'data', $response );
		$this->assertSame( 'forbidden', $response['data']['message'] ?? null );

		// User-meta must NOT be written when capability check fails.
		$stored = get_user_meta( $user_id, Conflict_Notice::USER_META_KEY, true );
		$this->assertSame( '', $stored );
	}

	/**
	 * Nonce failure — missing `_wpnonce`. `check_ajax_referer` calls
	 * `wp_die( -1, 403 )` which throws the "Stop" variant in test mode
	 * (no JSON body, hard die).
	 */
	public function test_handle_dismiss_missing_nonce_calls_wp_die(): void {
		$this->_setRole( 'administrator' );

		// _wpnonce intentionally absent.
		$_POST['fingerprint'] = self::VALID_FINGERPRINT;

		$this->expectException( WPAjaxDieStopException::class );
		$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
	}

	/**
	 * Nonce failure — present but invalid `_wpnonce`. Same wp_die surface
	 * as the missing case.
	 */
	public function test_handle_dismiss_invalid_nonce_calls_wp_die(): void {
		$this->_setRole( 'administrator' );

		$_POST['_wpnonce']    = 'not-a-real-nonce';
		$_POST['fingerprint'] = self::VALID_FINGERPRINT;

		$this->expectException( WPAjaxDieStopException::class );
		$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
	}

	/**
	 * Fingerprint failure — missing entirely. Expected:
	 * `wp_send_json_error( 'invalid_fingerprint', 400 )`.
	 */
	public function test_handle_dismiss_missing_fingerprint_returns_400(): void {
		$this->_setRole( 'administrator' );
		$user_id = get_current_user_id();

		$_POST['_wpnonce'] = wp_create_nonce( Conflict_Notice::DISMISS_ACTION );
		// fingerprint intentionally absent.

		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_error(400).' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_fingerprint', $response['data']['message'] ?? null );

		$stored = get_user_meta( $user_id, Conflict_Notice::USER_META_KEY, true );
		$this->assertSame( '', $stored );
	}

	/**
	 * Fingerprint failure — contains a non-hex character (`z`). 40 chars
	 * in length but fails the `[a-f0-9]` character class.
	 */
	public function test_handle_dismiss_non_hex_fingerprint_returns_400(): void {
		$this->_setRole( 'administrator' );
		$user_id = get_current_user_id();

		$_POST['_wpnonce']    = wp_create_nonce( Conflict_Notice::DISMISS_ACTION );
		// 40 chars, but the trailing `z` is not hex.
		$_POST['fingerprint'] = 'a1b2c3d4e5f60718293a4b5c6d7e8f900112233z';

		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_error(400).' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_fingerprint', $response['data']['message'] ?? null );

		$stored = get_user_meta( $user_id, Conflict_Notice::USER_META_KEY, true );
		$this->assertSame( '', $stored );
	}

	/**
	 * Fingerprint failure — wrong length (39 hex chars). All-hex but shorter
	 * than the expected 40-char hash.
	 */
	public function test_handle_dismiss_wrong_length_fingerprint_returns_400(): void {
		$this->_setRole( 'administrator' );
		$user_id = get_current_user_id();

		$_POST['_wpnonce']    = wp_create_nonce( Conflict_Notice::DISMISS_ACTION );
		// 39 chars — one short.
		$_POST['fingerprint'] = 'a1b2c3d4e5f60718293a4b5c6d7e8f90011223';

		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_error(400).' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_fingerprint', $response['data']['message'] ?? null );

		$stored = get_user_meta( $user_id, Conflict_Notice::USER_META_KEY, true );
		$this->assertSame( '', $stored );
	}

	/**
	 * Idempotence — dispatching the dismiss twice for the same fingerprint
	 * must NOT duplicate the entry in user-meta. The implementation uses
	 * `array_unique()` before storing, so a second dispatch is a no-op
	 * data-wise but still returns success.
	 *
	 * Also dispatches a third call with a different fingerprint to confirm
	 * the storage is additive across distinct hashes (one entry per unique
	 * fingerprint, not a single overwriting slot).
	 */
	public function test_handle_dismiss_idempotent_for_same_fingerprint(): void {
		$this->_setRole( 'administrator' );
		$user_id = get_current_user_id();

		$nonce = wp_create_nonce( Conflict_Notice::DISMISS_ACTION );

		// First dismissal.
		$_POST['_wpnonce']    = $nonce;
		$_POST['fingerprint'] = self::VALID_FINGERPRINT;
		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// `_handleAjax` only resets the response buffer between invocations
		// if we call it explicitly — flip the internal flag so the second
		// dispatch starts clean.
		$this->_last_response = '';

		// Second dismissal — same fingerprint, same user.
		$_POST['_wpnonce']    = $nonce;
		$_POST['fingerprint'] = self::VALID_FINGERPRINT;
		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected — still a success response.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );

		$stored = get_user_meta( $user_id, Conflict_Notice::USER_META_KEY, true );
		$this->assertIsArray( $stored );
		// Exactly one entry, no duplicate.
		$this->assertCount( 1, $stored );
		$this->assertSame( array( self::VALID_FINGERPRINT ), array_values( $stored ) );

		// Third dispatch with a DIFFERENT fingerprint — should append, not
		// overwrite. Confirms the storage is additive across unique hashes.
		$this->_last_response = '';
		$_POST['_wpnonce']    = $nonce;
		$_POST['fingerprint'] = self::VALID_FINGERPRINT_2;
		try {
			$this->_handleAjax( Conflict_Notice::DISMISS_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$stored = get_user_meta( $user_id, Conflict_Notice::USER_META_KEY, true );
		$this->assertIsArray( $stored );
		$this->assertCount( 2, $stored );
		$this->assertContains( self::VALID_FINGERPRINT, $stored );
		$this->assertContains( self::VALID_FINGERPRINT_2, $stored );
	}

	/**
	 * Cache invalidation — `activated_plugin` action must delete the cache
	 * transient so the next `get_conflicts()` call re-runs `detect()` instead
	 * of returning a stale 5-minute-old result.
	 *
	 * Seeds the transient with a sentinel value, fires the action, and
	 * asserts the transient is gone (`get_transient` returns `false` for a
	 * deleted entry).
	 */
	public function test_activated_plugin_action_invalidates_conflict_cache(): void {
		$sentinel = array(
			array(
				'kind' => 'plugin',
				'slug' => 'sentinel/competitor.php',
				'name' => 'Sentinel Competitor',
			),
		);

		set_transient( Conflict_Notice::CACHE_TRANSIENT, $sentinel, Conflict_Notice::CACHE_TTL );
		$this->assertSame(
			$sentinel,
			get_transient( Conflict_Notice::CACHE_TRANSIENT ),
			'Pre-condition: sentinel must be cached before the invalidation hook fires.'
		);

		// Plugin file argument is unused by `invalidate_cache()`; passed for
		// fidelity with the real `activated_plugin` hook signature.
		do_action( 'activated_plugin', 'sentinel/competitor.php', false );

		$this->assertFalse(
			get_transient( Conflict_Notice::CACHE_TRANSIENT ),
			'Cache transient must be deleted after activated_plugin fires.'
		);
	}

	/**
	 * Cache invalidation — `deactivated_plugin` action must delete the cache
	 * transient. Mirror of the activation test: an admin deactivating a
	 * competing plugin should clear a now-resolved conflict immediately,
	 * not wait for the 5-minute TTL.
	 */
	public function test_deactivated_plugin_action_invalidates_conflict_cache(): void {
		$sentinel = array(
			array(
				'kind' => 'plugin',
				'slug' => 'sentinel/competitor.php',
				'name' => 'Sentinel Competitor',
			),
		);

		set_transient( Conflict_Notice::CACHE_TRANSIENT, $sentinel, Conflict_Notice::CACHE_TTL );
		$this->assertSame(
			$sentinel,
			get_transient( Conflict_Notice::CACHE_TRANSIENT ),
			'Pre-condition: sentinel must be cached before the invalidation hook fires.'
		);

		do_action( 'deactivated_plugin', 'sentinel/competitor.php', false );

		$this->assertFalse(
			get_transient( Conflict_Notice::CACHE_TRANSIENT ),
			'Cache transient must be deleted after deactivated_plugin fires.'
		);
	}

	/**
	 * After invalidate, `get_conflicts()` must re-run `Conflict_Detector::detect()`
	 * rather than returning a stale cached value.
	 *
	 * Strategy: seed the cache with a sentinel list that `detect()` would
	 * never produce in this test environment (no competing plugins are
	 * active, no static /llms.txt, no rewrite rule). Verify the cache is
	 * read (first call returns the sentinel), then invalidate, then verify
	 * the next call ignores the (now-deleted) cache and returns the fresh
	 * detector output (empty array in the test env).
	 */
	public function test_get_conflicts_after_invalidate_runs_fresh_detect(): void {
		$sentinel = array(
			array(
				'kind' => 'plugin',
				'slug' => 'sentinel/competitor.php',
				'name' => 'Sentinel Competitor',
			),
		);

		set_transient( Conflict_Notice::CACHE_TRANSIENT, $sentinel, Conflict_Notice::CACHE_TTL );

		// Sanity: the cache is being read (proves the sentinel isn't a no-op).
		$this->assertSame(
			$sentinel,
			Conflict_Notice::get_conflicts(),
			'Pre-condition: get_conflicts() must return the cached sentinel before invalidation.'
		);

		Conflict_Notice::invalidate_cache();

		// After invalidate, `get_conflicts()` re-runs `detect()`. In the
		// integration test environment there are no real competing plugins,
		// no static file, and no rewrite rule for /llms.txt — so a fresh
		// detect returns an empty array. The key assertion is that we no
		// longer see the sentinel.
		$fresh = Conflict_Notice::get_conflicts();
		$this->assertNotSame(
			$sentinel,
			$fresh,
			'get_conflicts() must not return the stale cached sentinel after invalidate_cache().'
		);
		$this->assertSame(
			array(),
			$fresh,
			'Fresh detect() in the test environment returns an empty array (no real conflicts).'
		);
	}
}
