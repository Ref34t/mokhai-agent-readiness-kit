<?php
/**
 * WordPress Abilities API registrar (#21 / AgDR-0044).
 *
 * Registers the `ai-readiness-kit` ability category and the five core
 * abilities that expose the plugin's operations to agent stacks. The IDs are
 * a stable, agent-facing contract (REST-exposed at `wp-abilities/v1`); treat
 * renames as breaking changes.
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Abilities;

\defined( 'ABSPATH' ) || exit;

/**
 * Pure manifest: wires the Abilities API init hooks and declares each ability's
 * schema + callbacks. All execution logic lives in the per-domain ability
 * classes; this class only describes the surface.
 */
final class Registrar {

	/**
	 * Ability category ID — the wp.org slug, consistent with the REST
	 * namespace and WP-CLI base (slug-internal split, AgDR-0036/0039).
	 *
	 * @var string
	 */
	public const CATEGORY        = 'mokhai';
	public const LEGACY_CATEGORY = 'ai-readiness-kit';

	/**
	 * Wire the Abilities API registration hooks. Called from
	 * `Main::register_hooks()`.
	 *
	 * Guards on `wp_register_ability` so the plugin degrades cleanly if the
	 * Abilities API is absent (it ships in WP core 6.9+, our floor — the guard
	 * is belt-and-braces against the API being unbundled as a feature plugin).
	 */
	public static function register_hooks(): void {
		if ( ! \function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Categories MUST register before abilities (core fires the category
		// init hook first and `_doing_it_wrong()`s an ability whose category
		// doesn't yet exist).
		\add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		\add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Register the `mokhai` ability category, plus a legacy `ai-readiness-kit` alias.
	 */
	public static function register_category(): void {
		$category_args = array(
			'label'       => \__( 'Mokhai', 'mokhai-agent-readiness-kit' ),
			'description' => \__( 'Audit, profile, exposure, /llms.txt, and Markdown-view operations exposed to AI agents.', 'mokhai-agent-readiness-kit' ),
		);

		\wp_register_ability_category( self::CATEGORY, $category_args );

		// Back-compat alias: register the legacy category so existing MCP clients
		// resolving 'ai-readiness-kit' continue to find a valid category.
		\wp_register_ability_category( self::LEGACY_CATEGORY, $category_args );
	}

	/**
	 * Register the five abilities.
	 *
	 * Output schemas are deliberately permissive (loose types, additional
	 * properties allowed): `WP_Ability::execute()` validates the return value
	 * against `output_schema`, and the underlying service payloads are richly
	 * nested. Input schemas are strict where it helps agents call correctly.
	 *
	 * Each ability also carries `meta.mcp.public = true` (#131 / AgDR-0045):
	 * the optional WordPress/mcp-adapter exposes it on its default MCP server.
	 * The flag is inert metadata when the adapter is absent.
	 */
	public static function register_abilities(): void {
		$manage_options = array( Permissions::class, 'require_manage_options' );
		$empty_input    = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);

		\wp_register_ability(
			Audit_Ability::ID,
			array(
				'label'               => \__( 'Run Context Score audit', 'mokhai-agent-readiness-kit' ),
				'description'         => \__( 'Recompute the Context Score synchronously and return the full breakdown.', 'mokhai-agent-readiness-kit' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $empty_input,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'overall'        => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
						'sub_scores'     => array( 'type' => 'object' ),
						'narrative'      => array( 'type' => array( 'object', 'null' ) ),
						'schema_version' => array( 'type' => 'integer' ),
						'computed_at'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( Audit_Ability::class, 'run' ),
				'permission_callback' => $manage_options,
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		\wp_register_ability(
			Profile_Ability::READ_ID,
			array(
				'label'               => \__( 'Read Context Profile', 'mokhai-agent-readiness-kit' ),
				'description'         => \__( 'Return the current Context Profile (exposure config + module flags).', 'mokhai-agent-readiness-kit' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $empty_input,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( Profile_Ability::class, 'read' ),
				'permission_callback' => $manage_options,
				'meta'                => array(
					'show_in_rest' => true,
					'readonly'     => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		\wp_register_ability(
			Profile_Ability::SET_EXPOSURE_ID,
			array(
				'label'               => \__( 'Set Context Profile exposure', 'mokhai-agent-readiness-kit' ),
				'description'         => \__( 'Update which custom post types and post statuses are exposed to agents. Invalid values are dropped by the whitelist.', 'mokhai-agent-readiness-kit' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'exposed_cpts'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'exposed_statuses' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => array( 'publish', 'private', 'password', 'draft', 'pending' ),
							),
						),
					),
					'additionalProperties' => false,
					'anyOf'                => array(
						array( 'required' => array( 'exposed_cpts' ) ),
						array( 'required' => array( 'exposed_statuses' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( Profile_Ability::class, 'set_exposure' ),
				'permission_callback' => $manage_options,
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		\wp_register_ability(
			Llms_Txt_Ability::ID,
			array(
				'label'               => \__( 'Regenerate /llms.txt', 'mokhai-agent-readiness-kit' ),
				'description'         => \__( 'Recompose and cache the /llms.txt document, returning the new body.', 'mokhai-agent-readiness-kit' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $empty_input,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'content' => array( 'type' => 'string' ),
						'bytes'   => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
				'execute_callback'    => array( Llms_Txt_Ability::class, 'regenerate' ),
				'permission_callback' => $manage_options,
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		\wp_register_ability(
			Md_View_Ability::ID,
			array(
				'label'               => \__( 'Preview Markdown view', 'mokhai-agent-readiness-kit' ),
				'description'         => \__( 'Return the deterministic Markdown view of a post (by url or post_id), plus any cached LLM-cleaned output. Never blocks on the LLM.', 'mokhai-agent-readiness-kit' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'     => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'additionalProperties' => false,
					'oneOf'                => array(
						array( 'required' => array( 'url' ) ),
						array( 'required' => array( 'post_id' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'                => array( 'type' => 'integer' ),
						'exposable'              => array( 'type' => 'boolean' ),
						'reason'                 => array( 'type' => array( 'string', 'null' ) ),
						'deterministic_markdown' => array( 'type' => 'string' ),
						'quality_score'          => array( 'type' => array( 'integer', 'null' ) ),
						'signals'                => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'execute_callback'    => array( Md_View_Ability::class, 'preview' ),
				'permission_callback' => $manage_options,
				'meta'                => array(
					'show_in_rest' => true,
					'readonly'     => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		// Back-compat: register legacy ai-readiness-kit/* IDs pointing at the same
		// callbacks so existing MCP clients continue to resolve the old ability IDs.
		$legacy_abilities = array(
			array(
				'id'       => Audit_Ability::LEGACY_ID,
				'new_id'   => Audit_Ability::ID,
				'callback' => array( Audit_Ability::class, 'run' ),
				'readonly' => false,
			),
			array(
				'id'       => Profile_Ability::LEGACY_READ_ID,
				'new_id'   => Profile_Ability::READ_ID,
				'callback' => array( Profile_Ability::class, 'read' ),
				'readonly' => true,
			),
			array(
				'id'       => Profile_Ability::LEGACY_SET_EXPOSURE_ID,
				'new_id'   => Profile_Ability::SET_EXPOSURE_ID,
				'callback' => array( Profile_Ability::class, 'set_exposure' ),
				'readonly' => false,
			),
			array(
				'id'       => Llms_Txt_Ability::LEGACY_ID,
				'new_id'   => Llms_Txt_Ability::ID,
				'callback' => array( Llms_Txt_Ability::class, 'regenerate' ),
				'readonly' => false,
			),
			array(
				'id'       => Md_View_Ability::LEGACY_ID,
				'new_id'   => Md_View_Ability::ID,
				'callback' => array( Md_View_Ability::class, 'preview' ),
				'readonly' => true,
			),
		);

		foreach ( $legacy_abilities as $legacy ) {
			$meta = array(
				'show_in_rest' => true,
				'mcp'          => array( 'public' => true ),
				'deprecated'   => array(
					'since'       => '0.5.0',
					'replacement' => $legacy['new_id'],
				),
			);

			if ( $legacy['readonly'] ) {
				$meta['readonly'] = true;
			}

			\wp_register_ability(
				$legacy['id'],
				array(
					'label'               => \sprintf(
						/* translators: %s: new ability ID */
						\__( 'Deprecated — use %s', 'mokhai-agent-readiness-kit' ),
						$legacy['new_id']
					),
					'description'         => \sprintf(
						/* translators: %s: new ability ID */
						\__( 'Back-compat alias for %s (deprecated since 0.5.0).', 'mokhai-agent-readiness-kit' ),
						$legacy['new_id']
					),
					'category'            => self::LEGACY_CATEGORY,
					'input_schema'        => $empty_input,
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => $legacy['callback'],
					'permission_callback' => $manage_options,
					'meta'                => $meta,
				)
			);
		}
	}
}
