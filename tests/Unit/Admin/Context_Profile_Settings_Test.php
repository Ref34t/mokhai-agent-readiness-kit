<?php
/**
 * Unit tests for WPContext\Admin\Context_Profile_Settings.
 *
 * Covers AgDR-0002's contract:
 *   - Defaults match the safe-by-default rule (FR-9)
 *   - Sanitiser drops unknown / non-public CPTs
 *   - Sanitiser whitelists statuses + falls back to ['publish'] when empty
 *   - Migration fills defaults for legacy stored profiles
 *   - Unknown keys in input are dropped
 *   - LLM toggles are coerced to bool
 *   - sanitize() dispatches agentready_context_profile_saved
 *   - sanitize() refuses for users without manage_options
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WPContext\Admin\Context_Profile_Settings;

final class Context_Profile_Settings_Test extends TestCase {

	protected function setUp(): void {
		// Reset all stubbed WP globals between tests so cross-test leakage
		// can't fake a passing assertion.
		$GLOBALS['wpctx_test_post_types']    = array( 'post', 'page' );
		$GLOBALS['wpctx_test_options']       = array();
		$GLOBALS['wpctx_test_capabilities']  = array( 'manage_options' => true );
		$GLOBALS['wpctx_test_did_action']    = array();
		$GLOBALS['wpctx_test_active_plugins'] = array();
	}

	// ----------------------------------------------------------------------
	// Defaults (FR-9 safe-by-default)
	// ----------------------------------------------------------------------

	public function test_defaults_expose_no_cpts(): void {
		$defaults = Context_Profile_Settings::get_defaults();
		self::assertSame( array(), $defaults['exposed_cpts'], 'FR-9: fresh install must expose no CPTs.' );
	}

	public function test_defaults_only_expose_publish_status(): void {
		$defaults = Context_Profile_Settings::get_defaults();
		self::assertSame( array( 'publish' ), $defaults['exposed_statuses'] );
	}

	public function test_defaults_enable_llm_toggles(): void {
		$defaults = Context_Profile_Settings::get_defaults();
		self::assertTrue( $defaults['llm_cleanup_enabled'] );
		self::assertTrue( $defaults['llm_descriptions_enabled'] );
	}

	public function test_defaults_carry_current_schema_version(): void {
		$defaults = Context_Profile_Settings::get_defaults();
		self::assertSame(
			Context_Profile_Settings::CURRENT_SCHEMA_VERSION,
			$defaults['schema_version']
		);
	}

	// ----------------------------------------------------------------------
	// get_profile() / migrate()
	// ----------------------------------------------------------------------

	public function test_get_profile_returns_defaults_on_fresh_install(): void {
		$profile = Context_Profile_Settings::get_profile();
		self::assertSame( Context_Profile_Settings::get_defaults(), $profile );
	}

	public function test_get_profile_merges_partial_stored_with_defaults(): void {
		$GLOBALS['wpctx_test_options'][ Context_Profile_Settings::OPTION_KEY ] = array(
			'exposed_cpts'        => array( 'post' ),
			'llm_cleanup_enabled' => false,
			// Note: exposed_statuses and llm_descriptions_enabled omitted —
			// should be filled by migrate() from defaults.
		);

		$profile = Context_Profile_Settings::get_profile();

		self::assertSame( array( 'post' ), $profile['exposed_cpts'] );
		self::assertFalse( $profile['llm_cleanup_enabled'] );
		self::assertTrue( $profile['llm_descriptions_enabled'], 'Missing LLM toggle should default to on.' );
		self::assertSame( array( 'publish' ), $profile['exposed_statuses'], 'Missing statuses should default to [publish].' );
	}

	public function test_get_profile_drops_cpts_no_longer_registered(): void {
		$GLOBALS['wpctx_test_post_types'] = array( 'post' ); // 'product' was registered before but is now gone.
		$GLOBALS['wpctx_test_options'][ Context_Profile_Settings::OPTION_KEY ] = array(
			'exposed_cpts' => array( 'post', 'product' ),
		);

		$profile = Context_Profile_Settings::get_profile();

		self::assertSame( array( 'post' ), $profile['exposed_cpts'], 'CPT not currently public must be dropped on read.' );
	}

	public function test_get_profile_handles_non_array_stored_value(): void {
		// Corrupt option (somehow stored a string) — must not fatal.
		$GLOBALS['wpctx_test_options'][ Context_Profile_Settings::OPTION_KEY ] = 'not-an-array';

		$profile = Context_Profile_Settings::get_profile();

		self::assertSame( Context_Profile_Settings::get_defaults(), $profile );
	}

	// ----------------------------------------------------------------------
	// Sanitiser — drops unknown / malicious input
	// ----------------------------------------------------------------------

	public function test_sanitize_drops_unknown_keys(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'exposed_cpts'  => array( 'post' ),
				'malicious_key' => 'gotcha',
				'__proto__'     => 'pollution',
			)
		);

		self::assertArrayNotHasKey( 'malicious_key', $result );
		self::assertArrayNotHasKey( '__proto__', $result );
	}

	public function test_sanitize_filters_non_public_cpts(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'exposed_cpts' => array( 'post', 'revision', 'attachment', 'definitely-not-real' ),
			)
		);

		self::assertSame( array( 'post' ), $result['exposed_cpts'] );
	}

	public function test_sanitize_rejects_non_string_cpt_entries(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'exposed_cpts' => array( 'post', 42, null, array( 'nested' ), true ),
			)
		);

		self::assertSame( array( 'post' ), $result['exposed_cpts'] );
	}

	public function test_sanitize_strips_special_chars_from_cpt_slugs(): void {
		// sanitize_key lowercases and strips non-[a-z0-9_-]. 'POST<script>'
		// becomes 'postscript' — not a registered public type, so it drops.
		$result = Context_Profile_Settings::sanitize(
			array(
				'exposed_cpts' => array( 'POST<script>alert(1)</script>', 'post' ),
			)
		);

		self::assertSame( array( 'post' ), $result['exposed_cpts'] );
	}

	public function test_sanitize_deduplicates_cpts(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'exposed_cpts' => array( 'post', 'post', 'page', 'post' ),
			)
		);

		self::assertSame( array( 'post', 'page' ), $result['exposed_cpts'] );
	}

	public function test_sanitize_whitelists_statuses(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'exposed_statuses' => array( 'publish', 'auto-draft', 'trash', 'private' ),
			)
		);

		self::assertSame( array( 'publish', 'private' ), $result['exposed_statuses'] );
	}

	public function test_sanitize_falls_back_to_publish_when_no_statuses_pass_whitelist(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'exposed_statuses' => array( 'auto-draft', 'trash' ),
			)
		);

		self::assertSame( array( 'publish' ), $result['exposed_statuses'] );
	}

	public function test_sanitize_coerces_llm_toggles_to_bool(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'llm_cleanup_enabled'      => '1',
				'llm_descriptions_enabled' => '',
			)
		);

		self::assertTrue( $result['llm_cleanup_enabled'] );
		self::assertFalse( $result['llm_descriptions_enabled'] );
	}

	public function test_sanitize_safe_default_reset(): void {
		// Empty input → safe defaults.
		$result = Context_Profile_Settings::sanitize( array() );

		self::assertSame( array(), $result['exposed_cpts'] );
		self::assertSame( array( 'publish' ), $result['exposed_statuses'] );
	}

	public function test_sanitize_clamps_invalid_schema_version(): void {
		// Forged future version should be clamped to CURRENT_SCHEMA_VERSION so
		// migrate() can't be bypassed by an attacker.
		$result = Context_Profile_Settings::sanitize(
			array(
				'schema_version' => 99,
			)
		);

		self::assertSame( Context_Profile_Settings::CURRENT_SCHEMA_VERSION, $result['schema_version'] );
	}

	public function test_sanitize_handles_negative_schema_version(): void {
		$result = Context_Profile_Settings::sanitize(
			array(
				'schema_version' => -5,
			)
		);

		self::assertSame( Context_Profile_Settings::CURRENT_SCHEMA_VERSION, $result['schema_version'] );
	}

	// ----------------------------------------------------------------------
	// Action dispatch + capability gate
	// ----------------------------------------------------------------------

	public function test_sanitize_dispatches_saved_action_with_new_and_old(): void {
		$GLOBALS['wpctx_test_options'][ Context_Profile_Settings::OPTION_KEY ] = array(
			'exposed_cpts' => array( 'page' ),
		);

		Context_Profile_Settings::sanitize(
			array(
				'exposed_cpts' => array( 'post' ),
			)
		);

		$dispatched = $GLOBALS['wpctx_test_did_action'];
		self::assertCount( 1, $dispatched );
		self::assertSame( 'agentready_context_profile_saved', $dispatched[0]['hook'] );
		self::assertSame( array( 'post' ), $dispatched[0]['args'][0]['exposed_cpts'] );
		self::assertSame( array( 'page' ), $dispatched[0]['args'][1]['exposed_cpts'] );
	}

	public function test_sanitize_refuses_user_without_manage_options(): void {
		$GLOBALS['wpctx_test_capabilities'] = array( 'manage_options' => false );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission/' );

		Context_Profile_Settings::sanitize( array( 'exposed_cpts' => array( 'post' ) ) );
	}

	public function test_sanitize_accepts_non_array_input(): void {
		// Edge case: someone POSTed a string value for the option.
		$result = Context_Profile_Settings::sanitize( 'not-an-array' );

		// Falls through to defaults via sanitize_internal.
		self::assertSame( array(), $result['exposed_cpts'] );
		self::assertSame( array( 'publish' ), $result['exposed_statuses'] );
	}
}
