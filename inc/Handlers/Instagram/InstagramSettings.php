<?php
/**
 * Settings for Instagram publish handler.
 *
 * @package DataMachineSocials\Handlers\Instagram
 */

namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InstagramSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for Instagram handler.
	 *
	 * @return array Settings field definitions
	 */
	public static function get_fields(): array {
		$fields = array(
			'default_aspect_ratio' => array(
				'type'        => 'select',
				'label'       => __( 'Default Aspect Ratio', 'data-machine-socials' ),
				'description' => __( 'Default aspect ratio for images when posting to Instagram', 'data-machine-socials' ),
				'options'     => array(
					'1:1'    => __( 'Square (1:1)', 'data-machine-socials' ),
					'4:5'    => __( 'Portrait (4:5)', 'data-machine-socials' ),
					'3:4'    => __( 'Classic Portrait (3:4)', 'data-machine-socials' ),
					'1.91:1' => __( 'Landscape (1.91:1)', 'data-machine-socials' ),
				),
				'default'     => '4:5',
			),
			'caption_source'       => array(
				'type'        => 'select',
				'label'       => __( 'Caption Source', 'data-machine-socials' ),
				'description' => __( 'Where to get the caption from', 'data-machine-socials' ),
				'options'     => array(
					'content'      => __( 'Content field', 'data-machine-socials' ),
					'post_excerpt' => __( 'Post excerpt', 'data-machine-socials' ),
					'post_title'   => __( 'Post title', 'data-machine-socials' ),
				),
				'default'     => 'content',
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}

	public function __construct() {
		parent::__construct( 'instagram' );

		// Self-register with filters
		self::registerHandler(
			'instagram_publish',
			'publish',
			self::class,
			'Instagram',
			'Post content to Instagram with support for single images and carousels (up to 10 images)',
			true,
			InstagramAuth::class,
			self::class,
			function ( $tools, $handler_slug, $handler_config ) {
				$handler_config;
				if ( 'instagram_publish' === $handler_slug ) {
					$tools['instagram_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'instagram_publish',
						'description' => 'Post content to Instagram. Supports single images and carousels (up to 10 images). Images are processed async.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'content'      => array(
									'type'        => 'string',
									'description' => 'The caption text to post to Instagram (max 2200 characters)',
								),
								'image_urls'   => array(
									'type'        => 'array',
									'description' => 'Array of image URLs (1-10 for carousel)',
									'items'       => array(
										'type'   => 'string',
										'format' => 'uri',
									),
								),
								'aspect_ratio' => array(
									'type'        => 'string',
									'description' => 'Aspect ratio for images: 1:1, 4:5, 3:4, or 1.91:1',
									'enum'        => array( '1:1', '4:5', '3:4', '1.91:1' ),
									'default'     => '4:5',
								),
							),
							'required'   => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'instagram',
			array(
				'charLimit'           => 2200,
				'maxImages'           => 10,
				'aspectRatios'        => array( '1:1', '4:5', '3:4', '1.91:1' ),
				'defaultAspectRatio'  => '4:5',
				'supportsCarousel'    => true,
				'supportsVideo'       => true,
				'supportedMediaKinds' => array( 'image', 'carousel', 'reel', 'story' ),
				'capabilities'        => array(
					array(
						'slug'  => 'publish',
						'label' => 'Publish',
					),
					array(
						'slug'  => 'comments',
						'label' => 'Comments',
					),
					array(
						'slug'  => 'giveaway',
						'label' => 'Giveaway',
					),
				),
			)
		);
	}
}
