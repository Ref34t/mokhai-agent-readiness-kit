<?php
/**
 * Integration tests for the Abilities API registrar (#21 / AgDR-0044).
 *
 * Runs inside wp-phpunit so the real Abilities API registry + init hooks
 * are exercised. Asserts the category and all five abilities resolve.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Abilities;

use WP_UnitTestCase;
use Mokhai\Abilities\Audit_Ability;
use Mokhai\Abilities\Llms_Txt_Ability;
use Mokhai\Abilities\Md_View_Ability;
use Mokhai\Abilities\Profile_Ability;
use Mokhai\Abilities\Registrar;

final class Registrar_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}

		// First access lazily fires wp_abilities_api_init / categories_init,
		// running the registrar's hooked callbacks.
		\wp_get_abilities();
	}

	public function test_category_is_registered(): void {
		self::assertNotNull( \wp_get_ability_category( Registrar::CATEGORY ) );
	}

	/**
	 * @dataProvider ability_id_provider
	 */
	public function test_ability_is_registered( string $id ): void {
		$ability = \wp_get_ability( $id );

		self::assertNotNull( $ability, "Ability {$id} should be registered." );
		self::assertSame( $id, $ability->get_name() );
		self::assertSame( Registrar::CATEGORY, $ability->get_category() );
	}

	public function test_all_five_abilities_present(): void {
		$mine = array_filter(
			array_keys( \wp_get_abilities() ),
			static function ( string $id ): bool {
				return 0 === strpos( $id, Registrar::CATEGORY . '/' );
			}
		);

		self::assertCount( 5, $mine );
	}

	public function test_readonly_meta_on_read_abilities(): void {
		self::assertTrue( (bool) \wp_get_ability( Profile_Ability::READ_ID )->get_meta_item( 'readonly', false ) );
		self::assertTrue( (bool) \wp_get_ability( Md_View_Ability::ID )->get_meta_item( 'readonly', false ) );
	}

	/**
	 * @return array<int, array{0: string}>
	 */
	public function ability_id_provider(): array {
		return array(
			array( Audit_Ability::ID ),
			array( Profile_Ability::READ_ID ),
			array( Profile_Ability::SET_EXPOSURE_ID ),
			array( Llms_Txt_Ability::ID ),
			array( Md_View_Ability::ID ),
		);
	}
}
