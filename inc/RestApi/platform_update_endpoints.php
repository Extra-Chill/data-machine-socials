//! platform_update_endpoints — extracted from RestApi.php.


	/**
	 * Standard args for read endpoints with cursor-based pagination.
	 */
	private static function read_endpoint_args( array $actions, string $id_param ): array {
		return array(
			'action'  => array(
				'type'              => 'string',
				'default'           => $actions[0],
				'enum'              => $actions,
				'sanitize_callback' => 'sanitize_text_field',
			),
			$id_param => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'   => array(
				'type'              => 'integer',
				'default'           => 25,
				'sanitize_callback' => 'absint',
			),
			'after'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Generic platform read handler — routes to the correct ability by URL.
	 */
	public static function platform_read( \WP_REST_Request $request ) {
		$route  = $request->get_route();
		$params = $request->get_params();

		$slug_map = array(
			'instagram' => 'datamachine/instagram-read',
			'threads'   => 'datamachine/threads-read',
			'facebook'  => 'datamachine/facebook-read',
			'twitter'   => 'datamachine/twitter-read',
			'bluesky'   => 'datamachine/bluesky-read',
			'pinterest' => 'datamachine/pinterest-read',
		);

		$platform = null;
		foreach ( $slug_map as $key => $slug ) {
			if ( strpos( $route, "/{$key}/" ) !== false ) {
				$platform = $key;
				break;
			}
		}

		if ( ! $platform || ! isset( $slug_map[ $platform ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'Unknown platform',
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug_map[ $platform ] ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => $slug_map[ $platform ] . ' ability not registered',
			), 500 );
		}
		$input   = array_filter( $params, function ( $v ) { return '' !== $v && null !== $v;
		} );
		$result  = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Generic platform update handler — routes to the correct ability by URL.
	 */
	public static function platform_update( \WP_REST_Request $request ) {
		$route  = $request->get_route();
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		$slug_map = array(
			'instagram' => 'datamachine/instagram-update',
			'twitter'   => 'datamachine/twitter-update',
			'facebook'  => 'datamachine/facebook-update',
			'threads'   => 'datamachine/threads-update',
			'bluesky'   => 'datamachine/bluesky-update',
			'pinterest' => 'datamachine/pinterest-update',
		);

		$platform = null;
		foreach ( $slug_map as $key => $slug ) {
			if ( strpos( $route, "/{$key}/" ) !== false ) {
				$platform = $key;
				break;
			}
		}

		if ( ! $platform || ! isset( $slug_map[ $platform ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'Unknown platform or update not supported',
			), 400 );
		}

		// Validate required params.
		if ( empty( $params['action'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'action is required',
			), 400 );
		}

		// Build input based on platform - different platforms use different ID fields.
		$id_field = 'media_id'; // Default for Instagram.
		if ( 'twitter' === $platform ) {
			$id_field = 'tweet_id';
		} elseif ( 'facebook' === $platform ) {
			$id_field = 'post_id';
		} elseif ( 'threads' === $platform ) {
			$id_field = 'thread_id';
		} elseif ( 'bluesky' === $platform ) {
			$id_field = 'post_uri';
		} elseif ( 'pinterest' === $platform ) {
			$id_field = 'pin_id';
		}

		if ( empty( $params[ $id_field ] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => "{$id_field} is required",
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug_map[ $platform ] ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => $slug_map[ $platform ] . ' ability not registered',
			), 500 );
		}
		$input = array(
			'action'  => sanitize_text_field( $params['action'] ),
			$id_field => sanitize_text_field( $params[ $id_field ] ),
		);

		if ( ! empty( $params['caption'] ) ) {
			$input['caption'] = sanitize_text_field( $params['caption'] );
		}

		// Handle message field for Facebook.
		if ( ! empty( $params['message'] ) && 'facebook' === $platform ) {
			$input['message'] = sanitize_text_field( $params['message'] );
		}

		$result = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Reply to an Instagram comment.
	 */
	public static function instagram_comment_reply( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		if ( empty( $params['comment_id'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'comment_id is required',
			), 400 );
		}

		if ( empty( $params['message'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'message is required',
			), 400 );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/instagram-comment-reply' ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Ability not registered' ), 500 );
		}
		$result = $ability->execute(
			array(
				'comment_id' => sanitize_text_field( $params['comment_id'] ),
				'message'    => sanitize_textarea_field( $params['message'] ),
			)
		);

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Publish an Instagram Reel.
	 */
	public static function instagram_publish_reel( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		if ( empty( $params['caption'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'caption is required',
			), 400 );
		}

		if ( empty( $params['video_url'] ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'video_url is required',
			), 400 );
		}

		$input = array(
			'content'       => sanitize_textarea_field( $params['caption'] ),
			'media_kind'    => 'reel',
			'video_url'     => sanitize_url( $params['video_url'] ),
			'share_to_feed' => $params['share_to_feed'] ?? true,
		);

		if ( ! empty( $params['cover_url'] ) ) {
			$input['cover_url'] = sanitize_url( $params['cover_url'] );
		}

		if ( ! empty( $params['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $params['source_url'] );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/instagram-publish' ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Ability not registered' ), 500 );
		}
		$result = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Publish an Instagram Story.
	 */
	public static function instagram_publish_story( \WP_REST_Request $request ) {
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_body_params();

		$image_url = $params['image_url'] ?? '';
		$video_url = $params['video_url'] ?? '';

		if ( empty( $image_url ) && empty( $video_url ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'error'   => 'image_url or video_url is required',
			), 400 );
		}

		$input = array(
			'content'    => 'Story',
			'media_kind' => 'story',
		);

		if ( ! empty( $video_url ) ) {
			$input['video_url'] = sanitize_url( $video_url );
		} else {
			$input['story_image_url'] = sanitize_url( $image_url );
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/instagram-publish' ) : null;
		if ( ! $ability ) {
			return new \WP_REST_Response( array( 'success' => false, 'error' => 'Ability not registered' ), 500 );
		}
		$result = $ability->execute( $input );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Get authentication status for all social platforms.
	 *
	 * Kept for backward compatibility. Prefer GET /platforms which
	 * returns auth status alongside platform config in one response.
	 */
	public static function get_auth_status() {
		$auth_abilities = new AuthAbilities();
		$providers      = $auth_abilities->getAllProviders();

		$statuses = array();

		foreach ( $providers as $key => $provider ) {
			// Only include social auth providers (from this plugin's namespace).
			if ( strpos( get_class( $provider ), 'DataMachineSocials\\' ) === false ) {
				continue;
			}

			$statuses[] = array(
				'platform'      => $key,
				'authenticated' => $provider->is_authenticated(),
				'username'      => $provider->get_username(),
			);
		}

		return new \WP_REST_Response( $statuses );
	}

	/**
	 * Get platform configurations with auth status.
	 *
	 * Assembles from DM core's handler registry — each social handler
	 * self-declares its constraints via the $meta parameter in registerHandler().
	 * Auth status is folded in from the auth providers filter.
	 */
	public static function get_platforms() {
		$handler_abilities = new \DataMachine\Abilities\HandlerAbilities();
		$auth_abilities    = new AuthAbilities();
		$providers         = $auth_abilities->getAllProviders();

		// Get all handlers that registered via HandlerRegistrationTrait.
		$all_handlers = $handler_abilities->getAllHandlers();

		$platforms = array();

		foreach ( $all_handlers as $slug => $handler ) {
			$auth_key = $handler['auth_provider_key'] ?? $slug;
			$provider = $providers[ $auth_key ] ?? null;

			// Skip handlers without an auth provider.
			if ( ! $provider ) {
				continue;
			}

			// Only include handlers whose auth provider is from this plugin.
			$provider_class = get_class( $provider );
			if ( strpos( $provider_class, 'DataMachineSocials\\' ) === false ) {
				continue;
			}

			$meta = $handler['meta'] ?? array();

			$platforms[ $auth_key ] = array_merge(
				array(
					'label' => $handler['label'] ?? $auth_key,
					'type'  => $handler['type'] ?? 'publish',
				),
				$meta,
				array(
					'authenticated' => $provider->is_authenticated(),
					'username'      => $provider->get_username(),
				)
			);
		}

		return new \WP_REST_Response( $platforms );
	}

	/**
	 * Check if user can publish posts
	 */
	public static function check_publish_permission() {
		return current_user_can( 'publish_posts' );
	}

	/**
	 * Check if user can edit posts (used for read/update endpoints)
	 */
	public static function check_edit_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check if user can upload files
	 */
	public static function check_upload_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Cross-platform post
	 */
	public static function cross_post( \WP_REST_Request $request ) {
		$params        = $request->get_json_params();
		$platforms     = $params['platforms'] ?? array();
		$images        = $params['images'] ?? array();
		$caption       = sanitize_textarea_field( $params['caption'] ?? '' );
		$post_id       = intval( $params['post_id'] ?? 0 );
		$aspect_ratio  = sanitize_text_field( $params['aspect_ratio'] ?? '4:5' );
		$media_kind    = sanitize_text_field( $params['media_kind'] ?? 'image' );
		$video_url     = sanitize_url( $params['video_url'] ?? '' );
		$cover_url     = sanitize_url( $params['cover_url'] ?? '' );
		$share_to_feed = $params['share_to_feed'] ?? true;

		if ( empty( $platforms ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'No platforms selected',
				),
				400
			);
		}

		// Validate media: reels need video_url, stories need image or video, others need images.
		if ( 'reel' === $media_kind ) {
			if ( empty( $video_url ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'video_url is required for Reel publishing',
					),
					400
				);
			}
		} elseif ( 'story' === $media_kind ) {
			if ( empty( $video_url ) && empty( $images ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'error'   => 'image or video_url is required for Story publishing',
					),
					400
				);
			}
		} elseif ( empty( $images ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'No images provided',
				),
				400
			);
		}

		$results = array();
		$errors  = array();

		// Get source URL.
		$source_url = $post_id ? get_permalink( $post_id ) : '';

		// Build extra params for reel publishing.
		$extra = array(
			'media_kind'    => $media_kind,
			'video_url'     => $video_url,
			'cover_url'     => $cover_url,
			'share_to_feed' => $share_to_feed,
		);

		// Post to each platform.
		foreach ( $platforms as $platform ) {
			$result    = self::post_to_platform( $platform, $images, $caption, $source_url, $extra );
			$results[] = $result;

			if ( ! $result['success'] ) {
				$errors[] = $platform . ': ' . $result['error'];
			}

			// Track successful shares via SocialShareTracker.
			if ( $post_id && ! empty( $result['success'] ) ) {
				\DataMachineSocials\Tracking\SocialShareTracker::record_from_result(
					$post_id,
					$platform,
					$result,
					array( 'media_kind' => $media_kind )
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'success' => empty( $errors ),
				'results' => $results,
				'errors'  => $errors ? $errors : null,
			)
		);
	}

	/**
	 * Upload cropped image
	 */
	public static function upload_cropped_image( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'Upload functionality not available' ),
				500
			);
		}

		// Get uploaded file
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'No file uploaded' ),
				400
			);
		}

		$file = $files['file'];

		// Create temp directory if needed
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/dms-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
			Cleanup::secure_temp_dir();
		}

		// Move file to temp location
		$filename  = sanitize_file_name( $file['name'] );
		$temp_path = $temp_dir . '/' . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $temp_path ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'Failed to save uploaded file' ),
				500
			);
		}

		// Return public URL
		$url = $upload_dir['baseurl'] . '/dms-temp/' . $filename;

		return new \WP_REST_Response( array( 'url' => $url ) );
	}

	/**
	 * Get post sharing status and history.
	 */
	public static function get_post_status( \WP_REST_Request $request ) {
		$post_id  = intval( $request->get_param( 'post_id' ) );
		$platform = $request->get_param( 'platform' );

		$tracker = \DataMachineSocials\Tracking\SocialShareTracker::class;

		$shares    = $tracker::get_shares( $post_id, $platform ?: null );
		$platforms = $tracker::get_shared_platforms( $post_id );

		return new \WP_REST_Response( array(
			'post_id'   => $post_id,
			'platforms' => $platforms,
			'shares'    => $shares,
			'count'     => count( $shares ),
		) );
	}
