<?php
/**
 * `ai-readiness-kit/md-view-preview` ability (#21 / AgDR-0044).
 *
 * @package Mokhai
 */

declare(strict_types=1);

namespace Mokhai\Abilities;

use Mokhai\Admin\Context_Profile_Settings;
use Mokhai\Markdown_Views\Service;

\defined( 'ABSPATH' ) || exit;

/**
 * Preview the Markdown view of a post for an agent — deterministic markdown
 * computed synchronously via the Walker.
 *
 * A non-exposable post returns `exposable: false` + a reason rather than a
 * 404 — the caller holds `manage_options` and the reason is actionable,
 * matching the admin REST preview rationale (AgDR-0014/0015). A URL that
 * resolves to no post is a genuine `not_found` error.
 */
final class Md_View_Ability {

	/**
	 * Stable ability ID.
	 *
	 * @var string
	 */
	public const ID = 'ai-readiness-kit/md-view-preview';

	/**
	 * Execute callback. Resolves a post by `post_id` or `url`, then returns
	 * its deterministic + cleaned markdown view.
	 *
	 * @param array<string, mixed> $input Validated input ({url} or {post_id}).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function preview( $input ) {
		$input = \is_array( $input ) ? $input : array();

		$post = self::resolve_post( $input );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'ai_readiness_kit_post_not_found',
				\__( 'No post resolves from the supplied url / post_id.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Context_Profile_Settings::is_module_enabled( 'markdown_views' ) ) {
			return new \WP_Error(
				'ai_readiness_kit_module_disabled',
				\__( 'The Markdown Views module is disabled in the Context Profile.', 'mokhai-agent-readiness-kit' ),
				array( 'status' => 403 )
			);
		}

		$post_id = (int) $post->ID;
		$reason  = Context_Profile_Settings::get_exposure_reason( $post );

		if ( null !== $reason ) {
			return array(
				'post_id'                => $post_id,
				'exposable'              => false,
				'reason'                 => $reason,
				'deterministic_markdown' => '',
				'quality_score'          => null,
				'signals'                => null,
			);
		}

		$conversion = Service::regenerate_conversion_for( $post );

		$deterministic = null !== $conversion ? $conversion->get_markdown() : '';
		$quality       = null !== $conversion ? $conversion->get_quality_score() : null;
		$signals       = null !== $conversion ? $conversion->get_signals() : null;

		return array(
			'post_id'                => $post_id,
			'exposable'              => true,
			'reason'                 => null,
			'deterministic_markdown' => $deterministic,
			'quality_score'          => $quality,
			'signals'                => $signals,
		);
	}

	/**
	 * Resolve the target post from a `post_id` (preferred) or a `url`.
	 *
	 * Mirrors `Cli\Markdown_Views_Command::resolve_post()` — exact post_id
	 * lookup, else `url_to_postid()`.
	 *
	 * @param array<string, mixed> $input Validated input.
	 *
	 * @return \WP_Post|null
	 */
	private static function resolve_post( array $input ): ?\WP_Post {
		if ( isset( $input['post_id'] ) && \is_numeric( $input['post_id'] ) ) {
			$post = \get_post( (int) $input['post_id'] );
			return $post instanceof \WP_Post ? $post : null;
		}

		if ( isset( $input['url'] ) && \is_string( $input['url'] ) && '' !== $input['url'] ) {
			$post_id = \url_to_postid( $input['url'] );
			if ( $post_id <= 0 ) {
				return null;
			}

			$post = \get_post( $post_id );
			return $post instanceof \WP_Post ? $post : null;
		}

		return null;
	}
}
