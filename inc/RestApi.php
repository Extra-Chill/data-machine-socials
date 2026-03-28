<?php
/**
 * REST API endpoints for social media posting
 *
 * @package DataMachineSocials
 * @since 0.2.0
 */

namespace DataMachineSocials;

use DataMachine\Abilities\AuthAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestApi {

	const NAMESPACE = 'datamachine-socials/v1';

	/**
	 * Post to individual platform.
	 *
	 * @param string $platform   Platform slug.
	 * @param array  $images     Array of image objects with 'url' key.
	 * @param string $caption    Post caption.
	 * @param string $source_url Source URL to attribute.
	 * @param array  $extra      Extra params (media_kind, video_url, cover_url, share_to_feed).
	 * @return array Result.
	 */
	private static function post_to_platform( string $platform, array $images, string $caption, string $source_url, array $extra = array() ): array {
		$ability_slug = "datamachine/{$platform}-publish";

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => 'Abilities API not available',
			);
		}

		$ability = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => "Ability {$ability_slug} not registered",
			);
		}

		$image_urls = array_map(
			function ( $img ) {
				return $img['url'] ?? '';
			},
			$images
		);

		$input = array(
			'content'    => $caption,
			'image_urls' => $image_urls,
			'source_url' => $source_url,
		);

		// Pass through media-kind-specific params when applicable.
		$media_kind = $extra['media_kind'] ?? 'image';
		if ( 'reel' === $media_kind ) {
			$input['media_kind']    = 'reel';
			$input['video_url']     = $extra['video_url'] ?? '';
			$input['cover_url']     = $extra['cover_url'] ?? '';
			$input['share_to_feed'] = $extra['share_to_feed'] ?? true;
		} elseif ( 'story' === $media_kind ) {
			$input['media_kind'] = 'story';
			$input['video_url']  = $extra['video_url'] ?? '';
			// For stories, image comes from story_image_url or first image_url.
			if ( ! empty( $image_urls[0] ) && empty( $extra['video_url'] ) ) {
				$input['story_image_url'] = $image_urls[0];
			}
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return array(
				'platform' => $platform,
				'success'  => false,
				'error'    => $result->get_error_message(),
			);
		}

		if ( ! empty( $result['success'] ) ) {
			return array(
				'platform'         => $platform,
				'success'          => true,
				'platform_post_id' => \DataMachineSocials\Tracking\SocialShareTracker::extract_platform_post_id( $platform, $result ),
				'platform_url'     => \DataMachineSocials\Tracking\SocialShareTracker::extract_platform_url( $platform, $result ),
				'media_kind'       => $result['media_kind'] ?? null,
			);
		}

		return array(
			'platform' => $platform,
			'success'  => false,
			'error'    => $result['error'] ?? 'Unknown error',
		);
	}
}
