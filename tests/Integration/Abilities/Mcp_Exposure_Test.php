<?php
/**
 * Integration tests for MCP exposure of the abilities (#131 / AgDR-0045).
 *
 * Verifies the contract with WordPress/mcp-adapter: every ai-readiness-kit
 * ability carries `meta.mcp.public = true`, which exposes it on the adapter's
 * default MCP server. Needs no adapter installed — this is the registration
 * contract, asserted against the live Abilities API.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration\Abilities;

use WP_UnitTestCase;
use WPContext\Abilities\Audit_Ability;
use WPContext\Abilities\Llms_Txt_Ability;
use WPContext\Abilities\Md_View_Ability;
use WPContext\Abilities\Profile_Ability;

final class Mcp_Exposure_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}

		\wp_get_abilities();
	}

	/**
	 * @dataProvider ability_id_provider
	 */
	public function test_ability_is_mcp_public( string $id ): void {
		$ability = \wp_get_ability( $id );
		self::assertNotNull( $ability, "Ability {$id} should be registered." );

		$meta = $ability->get_meta();

		self::assertIsArray( $meta );
		self::assertArrayHasKey( 'mcp', $meta, "{$id} should carry mcp meta." );
		self::assertIsArray( $meta['mcp'] );
		self::assertTrue(
			isset( $meta['mcp']['public'] ) && true === $meta['mcp']['public'],
			"{$id} should set meta.mcp.public = true so the mcp-adapter exposes it."
		);
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
