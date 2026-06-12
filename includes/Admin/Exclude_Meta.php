<?php
/**
 * Per-post "exclude from agent output" meta registration (#180).
 *
 * Registers the `_agentready_excluded` boolean meta on every public post type
 * and exposes it to the REST API so the block-editor sidebar toggle
 * (Exclude_Sidebar_Assets) can read/write it via `useEntityProp`. When set, the
 * `excluded` gate in {@see Context_Profile_Settings::get_exposure_reason()}
 * drops the post from /llms.txt, .md views, and #178 alternate advertising.
 *
 * @package WPContext
 */

declare(strict_types=1);

namespace WPContext\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers the per-post exclude meta on the block-editor REST surface.
 *
 * The meta is registered per public post type (rather than globally) so it
 * appears in each type's REST `meta` object — the shape `useEntityProp` reads
 * in the Gutenberg sidebar. Underscore-prefixed + `auth_callback` gated on
 * `edit_post`, so it never surfaces in the generic custom-fields UI and only
 * editors of the specific post can flip it.
 */
final class Exclude_Meta {

	/**
	 * Wire the registration hook. Called from Main::register_hooks().
	 *
	 * Hooked late on `init` (priority 99) so custom post types registered at
	 * the default `init` priority are present when we enumerate public types.
	 */
	public static function register_hooks(): void {
		\add_action( 'init', array( self::class, 'register' ), 99 );
	}

	/**
	 * Register the exclude meta on every public post type.
	 */
	public static function register(): void {
		$public_types = \get_post_types( array( 'public' => true ), 'names' );

		foreach ( $public_types as $post_type ) {
			\register_post_meta(
				$post_type,
				Context_Profile_Settings::EXCLUDE_META_KEY,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					'auth_callback'     => array( self::class, 'can_edit' ),
					'sanitize_callback' => array( self::class, 'sanitize' ),
					'description'       => \__( 'Exclude this content from AI Readiness Kit agent output (/llms.txt, .md views).', 'agentready-ai-readiness-kit' ),
				)
			);
		}
	}

	/**
	 * Coerce the stored meta to a strict boolean.
	 *
	 * @param mixed $value Raw meta value.
	 */
	public static function sanitize( $value ): bool {
		return (bool) $value;
	}

	/**
	 * Only editors of the specific post may write the meta.
	 *
	 * @param bool   $allowed   Unused incoming WP default.
	 * @param string $meta_key  Unused (this callback is meta-specific).
	 * @param int    $object_id Post ID being edited.
	 */
	public static function can_edit( $allowed, $meta_key, $object_id ): bool {
		unset( $allowed, $meta_key );

		return \current_user_can( 'edit_post', (int) $object_id );
	}
}
