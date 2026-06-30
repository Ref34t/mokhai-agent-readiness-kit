<?php
/**
 * Integration tests for the `ai-readiness-kit/profile-read` and
 * `ai-readiness-kit/profile-set-exposure` abilities (#21 / AgDR-0044).
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Abilities;

use WP_UnitTestCase;
use Mokhai\Abilities\Profile_Ability;
use Mokhai\Admin\Context_Profile_Settings;

final class Profile_Ability_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}

		\delete_option( Context_Profile_Settings::OPTION_KEY );
		\wp_get_abilities();
	}

	protected function tearDown(): void {
		\delete_option( Context_Profile_Settings::OPTION_KEY );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	private function as_admin(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function read() {
		return \wp_get_ability( Profile_Ability::READ_ID )->execute( array() );
	}

	private function set_exposure( array $input ) {
		return \wp_get_ability( Profile_Ability::SET_EXPOSURE_ID )->execute( $input );
	}

	public function test_read_returns_defaults_on_fresh_install(): void {
		$this->as_admin();

		$profile = $this->read();

		self::assertIsArray( $profile );
		self::assertSame( array(), $profile['exposed_cpts'] );
		self::assertSame( array( 'publish' ), $profile['exposed_statuses'] );
	}

	public function test_read_denied_for_subscriber(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->read();

		self::assertWPError( $result );
		self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_set_exposure_persists_valid_values(): void {
		$this->as_admin();

		$saved = $this->set_exposure( array( 'exposed_cpts' => array( 'post' ), 'exposed_statuses' => array( 'publish', 'draft' ) ) );

		self::assertSame( array( 'post' ), $saved['exposed_cpts'] );
		self::assertSame( array( 'publish', 'draft' ), $saved['exposed_statuses'] );

		// Persisted to the option, observable via the canonical reader.
		$reread = Context_Profile_Settings::get_profile();
		self::assertSame( array( 'post' ), $reread['exposed_cpts'] );
	}

	public function test_set_exposure_drops_invalid_cpt_via_whitelist(): void {
		$this->as_admin();

		$saved = $this->set_exposure( array( 'exposed_cpts' => array( 'post', 'totally-bogus-cpt' ) ) );

		self::assertSame( array( 'post' ), $saved['exposed_cpts'] );
	}

	public function test_set_exposure_preserves_other_keys(): void {
		$this->as_admin();

		$before = $this->read();
		$this->set_exposure( array( 'exposed_statuses' => array( 'publish', 'pending' ) ) );
		$after = Context_Profile_Settings::get_profile();

		// Exposure changed; an unrelated module flag did not.
		self::assertSame( array( 'publish', 'pending' ), $after['exposed_statuses'] );
		self::assertSame( $before['llm_descriptions_enabled'], $after['llm_descriptions_enabled'] );
	}

	public function test_set_exposure_fires_saved_cascade(): void {
		$this->as_admin();

		$fired = 0;
		\add_action(
			'agentready_context_profile_saved',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->set_exposure( array( 'exposed_cpts' => array( 'post' ) ) );

		self::assertGreaterThanOrEqual( 1, $fired );
	}

	public function test_set_exposure_denied_for_subscriber(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->set_exposure( array( 'exposed_cpts' => array( 'post' ) ) );

		self::assertWPError( $result );
		self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_set_exposure_requires_at_least_one_key(): void {
		$this->as_admin();

		$result = $this->set_exposure( array() );

		self::assertWPError( $result );
		self::assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
