//! register — extracted from RestApi.php.


	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public static function register_routes() {
		// Get auth status for all platforms
		register_rest_route(
			self::NAMESPACE,
			'/auth/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_auth_status' ),
				'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			)
		);

		// Get platform configurations
		register_rest_route(
			self::NAMESPACE,
			'/platforms',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_platforms' ),
				'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			)
		);

		// Cross-platform post
		register_rest_route(
			self::NAMESPACE,
			'/post',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cross_post' ),
				'permission_callback' => array( __CLASS__, 'check_publish_permission' ),
				'args'                => array(
				'platforms'    => array(
					'required' => true,
					'type'     => 'array',
				),
				'images'       => array(
					'type' => 'array',
				),
				'caption'      => array(
					'required' => true,
					'type'     => 'string',
				),
				'aspect_ratio' => array(
					'type'    => 'string',
					'default' => '4:5',
				),
				'media_kind'   => array(
					'type'    => 'string',
					'default' => 'image',
					'enum'    => array( 'image', 'carousel', 'reel', 'story' ),
				),
				'video_url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'cover_url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'share_to_feed' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			)
		);

		// Upload cropped image
		register_rest_route(
			self::NAMESPACE,
			'/media/crop',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_cropped_image' ),
				'permission_callback' => array( __CLASS__, 'check_upload_permission' ),
			)
		);

		// Get post status
		register_rest_route(
			self::NAMESPACE,
			'/status/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_post_status' ),
				'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			)
		);

		// =====================================================================
		// Platform Read Endpoints
		// =====================================================================

		register_rest_route( self::NAMESPACE, '/instagram/media', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => self::read_endpoint_args( array( 'list', 'get', 'comments' ), 'media_id' ),
		) );

		register_rest_route( self::NAMESPACE, '/threads/posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => self::read_endpoint_args( array( 'list', 'get', 'replies' ), 'thread_id' ),
		) );

		register_rest_route( self::NAMESPACE, '/facebook/posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => self::read_endpoint_args( array( 'list', 'get', 'comments' ), 'post_id' ),
		) );

		register_rest_route( self::NAMESPACE, '/facebook/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'  => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'edit', 'hide', 'unhide', 'delete' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/twitter/tweets', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'           => array(
					'type'              => 'string',
					'default'           => 'list',
					'enum'              => array( 'list', 'get', 'mentions' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tweet_id'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'            => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'pagination_token' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/twitter/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete', 'retweet', 'unretweet', 'like', 'unlike' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tweet_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/bluesky/posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'default'           => 'list',
					'enum'              => array( 'list', 'get', 'profile' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_uri' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'    => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'cursor'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/pinterest/read', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'platform_read' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'default'           => 'pins',
					'enum'              => array( 'pins', 'pin', 'boards', 'board_pins' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'pin_id'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'board_id' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit'    => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'bookmark' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/threads/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'    => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'thread_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/bluesky/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete', 'like', 'unlike' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_uri' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/pinterest/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action' => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'delete' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'pin_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// =====================================================================
		// Platform Update Endpoints
		// =====================================================================

		register_rest_route( self::NAMESPACE, '/instagram/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'platform_update' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'action'   => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'edit', 'delete', 'archive' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'media_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'caption'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/instagram/comments/reply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'instagram_comment_reply' ),
			'permission_callback' => array( __CLASS__, 'check_edit_permission' ),
			'args'                => array(
				'comment_id' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		) );

		// Instagram Reel publish.
		register_rest_route( self::NAMESPACE, '/instagram/reel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'instagram_publish_reel' ),
			'permission_callback' => array( __CLASS__, 'check_publish_permission' ),
			'args'                => array(
				'caption'       => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'video_url'     => array(
					'type'              => 'string',
					'required'          => true,
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
				'cover_url'     => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
				'share_to_feed' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'source_url'    => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
			),
		) );

		// Instagram Story publish.
		register_rest_route( self::NAMESPACE, '/instagram/story', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'instagram_publish_story' ),
			'permission_callback' => array( __CLASS__, 'check_publish_permission' ),
			'args'                => array(
				'image_url' => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
				'video_url' => array(
					'type'              => 'string',
					'format'            => 'uri',
					'sanitize_callback' => 'sanitize_url',
				),
			),
		) );
	}
