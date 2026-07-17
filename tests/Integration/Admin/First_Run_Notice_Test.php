<?php
/**
 * Integration tests for Mokhai\Admin\First_Run_Notice — AJAX surface.
 *
 * Pins the contract of the two `wp_ajax_*` endpoints (#251 / AgDR-0071):
 * the one-click expose (capability + nonce guards, writes through
 * `Context_Profile_Settings::set_exposure()` so the saved cascade fires)
 * and the per-user dismiss. Uses `WP_Ajax_UnitTestCase` so we can
 * dispatch the registered actions and capture `wp_die` via exceptions.
 *
 * The render matrix (show/hide) lives in the sibling
 * `First_Run_Notice_Render_Test`.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Admin;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;
use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Admin\First_Run_Notice;

final class First_Run_Notice_Test extends WP_Ajax_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		$_POST    = array();
		$_REQUEST = array();

		// Every test starts from the fresh-install state: no profile stored,
		// so exposure defaults to empty.
		delete_option( Context_Profile_Settings::OPTION_KEY );
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();

		delete_option( Context_Profile_Settings::OPTION_KEY );

		global $wpdb;
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => First_Run_Notice::USER_META_KEY ) );

		parent::tearDown();
	}

	/**
	 * Happy path — admin user + valid nonce. Expected: JSON success, and the
	 * profile now exposes post + page at publish (whitelisted through
	 * `set_exposure()`).
	 */
	public function test_handle_expose_admin_with_valid_nonce_exposes_posts_and_pages(): void {
		$this->_setRole( 'administrator' );

		$_POST['_wpnonce'] = wp_create_nonce( First_Run_Notice::EXPOSE_ACTION );

		try {
			$this->_handleAjax( First_Run_Notice::EXPOSE_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_success.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected — wp_send_json_success calls wp_die() in test mode.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'post', 'page' ), $response['data']['exposed_cpts'] ?? null );
		$this->assertSame( array( 'publish' ), $response['data']['exposed_statuses'] ?? null );

		// The persisted profile matches — the write went through set_exposure().
		$profile = Context_Profile_Settings::get_profile();
		$this->assertSame( array( 'post', 'page' ), $profile['exposed_cpts'] );
		$this->assertSame( array( 'publish' ), $profile['exposed_statuses'] );
		$this->assertFalse( First_Run_Notice::is_exposure_empty() );
	}

	/**
	 * The expose write must dispatch the saved-event cascade exactly like an
	 * admin form save — Context Score recompute and /llms.txt regen subscribe
	 * to `mokhai_context_profile_saved`.
	 */
	public function test_handle_expose_fires_profile_saved_cascade(): void {
		$this->_setRole( 'administrator' );

		$fired = 0;
		add_action(
			'mokhai_context_profile_saved',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$_POST['_wpnonce'] = wp_create_nonce( First_Run_Notice::EXPOSE_ACTION );

		try {
			$this->_handleAjax( First_Run_Notice::EXPOSE_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$this->assertGreaterThan( 0, $fired, 'set_exposure() must fire mokhai_context_profile_saved.' );
	}

	/**
	 * Capability failure — subscriber. Expected: 403 error, profile untouched
	 * (nothing exposed without an authorised click).
	 */
	public function test_handle_expose_non_admin_returns_403_and_exposes_nothing(): void {
		$this->_setRole( 'subscriber' );

		$_POST['_wpnonce'] = wp_create_nonce( First_Run_Notice::EXPOSE_ACTION );

		try {
			$this->_handleAjax( First_Run_Notice::EXPOSE_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_error(403).' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected — capability guard fires.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'forbidden', $response['data']['message'] ?? null );

		$this->assertTrue( First_Run_Notice::is_exposure_empty(), 'Profile must stay empty after a forbidden request.' );
	}

	/**
	 * Nonce failure — admin but stale/foreign nonce. `check_ajax_referer`
	 * dies with -1 (WPAjaxDieStopException). Profile untouched.
	 */
	public function test_handle_expose_invalid_nonce_dies_and_exposes_nothing(): void {
		$this->_setRole( 'administrator' );

		$_POST['_wpnonce'] = 'not-a-valid-nonce';

		try {
			$this->_handleAjax( First_Run_Notice::EXPOSE_ACTION );
			$this->fail( 'Expected WPAjaxDieStopException from check_ajax_referer.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected — nonce guard fires.
		}

		$this->assertTrue( First_Run_Notice::is_exposure_empty(), 'Profile must stay empty after a bad-nonce request.' );
	}

	/**
	 * Dismiss happy path — records the per-user flag.
	 */
	public function test_handle_dismiss_admin_records_per_user_flag(): void {
		$this->_setRole( 'administrator' );
		$user_id = get_current_user_id();

		$_POST['_wpnonce'] = wp_create_nonce( First_Run_Notice::DISMISS_ACTION );

		try {
			$this->_handleAjax( First_Run_Notice::DISMISS_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_success.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );

		$this->assertSame( '1', get_user_meta( $user_id, First_Run_Notice::USER_META_KEY, true ) );
	}

	/**
	 * Dismiss capability failure — subscriber gets 403, no meta written.
	 */
	public function test_handle_dismiss_non_admin_returns_403(): void {
		$this->_setRole( 'subscriber' );
		$user_id = get_current_user_id();

		$_POST['_wpnonce'] = wp_create_nonce( First_Run_Notice::DISMISS_ACTION );

		try {
			$this->_handleAjax( First_Run_Notice::DISMISS_ACTION );
			$this->fail( 'Expected WPAjaxDieContinueException from wp_send_json_error(403).' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );

		$this->assertSame( '', get_user_meta( $user_id, First_Run_Notice::USER_META_KEY, true ) );
	}
}
