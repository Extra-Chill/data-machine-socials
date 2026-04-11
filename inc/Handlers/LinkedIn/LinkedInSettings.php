<?php
/**
 * LinkedIn Publish Handler Settings
 *
 * Defines settings fields and sanitization for LinkedIn publish handler.
 * Extends base publish handler settings with LinkedIn-specific options.
 *
 * @package DataMachineSocials
 * @since 0.5.0
 */

namespace DataMachineSocials\Handlers\LinkedIn;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

class LinkedInSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for LinkedIn publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'visibility' => array(
					'type'        => 'select',
					'label'       => __( 'Post Visibility', 'data-machine-socials' ),
					'description' => __( 'Who can see posts published to LinkedIn.', 'data-machine-socials' ),
					'options'     => array(
						'PUBLIC'      => __( 'Public - Anyone on or off LinkedIn', 'data-machine-socials' ),
						'CONNECTIONS' => __( 'Connections only - Only your connections', 'data-machine-socials' ),
					),
					'default'     => 'PUBLIC',
				),
			)
		);
	}

    public function __construct() {
		parent::__construct( 'linkedin' );

		// Self-register with filters.
		self::registerHandler(
			'linkedin_publish',
			'publish',
			self::class,
			'LinkedIn',
			'Post content to LinkedIn with media and article support',
			true,
			LinkedInAuth::class,
			LinkedInSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				$handler_config;
				if ( 'linkedin_publish' === $handler_slug ) {
					$tools['linkedin_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'linkedin_publish',
						'description' => 'Post content to LinkedIn. Supports text (up to 3000 chars), images, and article sharing.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'content' => array(
									'type'        => 'string',
									'description' => 'The text content to post to LinkedIn',
								),
							),
							'required'   => array( 'content' ),
						),
					);
				}
				return $tools;
			},
			'linkedin',
			array(
			'charLimit'          => 3000,
			'maxImages'          => 9,
			'aspectRatios'       => array( 'any' ),
			'defaultAspectRatio' => 'any',
			'supportsCarousel'   => false,
			'capabilities'       => array(
				array( 'slug' => 'publish', 'label' => 'Publish' ),
			),
			)
		);
    }
}
