//! helpers — extracted from RedditCommand.php.


	/**
	 * Fetch posts from Reddit.
	 *
	 * Fetches from a specific subreddit, or searches across all of Reddit
	 * when --query is provided without a subreddit. Uses the
	 * datamachine/fetch-reddit ability with all configured filters.
	 *
	 * ## OPTIONS
	 *
	 * [<subreddit>]
	 * : The subreddit name (without "r/"). Optional when --query is used.
	 *
	 * [--query=<query>]
	 * : Search query. Without a subreddit, searches all of Reddit.
	 *   With a subreddit, searches within that subreddit.
	 *
	 * [--sort=<sort>]
	 * : Sort order for posts.
	 * ---
	 * default: hot
	 * options:
	 *   - hot
	 *   - new
	 *   - top
	 *   - rising
	 *   - controversial
	 *   - relevance
	 * ---
	 *
	 * [--timeframe=<timeframe>]
	 * : Timeframe filter.
	 * ---
	 * default: all_time
	 * options:
	 *   - all_time
	 *   - 24_hours
	 *   - 72_hours
	 *   - 7_days
	 *   - 30_days
	 *   - 90_days
	 *   - 6_months
	 *   - 1_year
	 * ---
	 *
	 * [--min-upvotes=<min_upvotes>]
	 * : Minimum upvotes required.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--min-comments=<min_comments>]
	 * : Minimum comment count required.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--comments=<comments>]
	 * : Number of top comments to fetch per post.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--search=<search>]
	 * : Comma-separated search terms to filter posts locally (client-side).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Fetch from a subreddit
	 *     wp datamachine-socials reddit fetch jambands
	 *     wp datamachine-socials reddit fetch festivals --sort=top --min-upvotes=50
	 *     wp datamachine-socials reddit fetch bonnaroo --timeframe=7_days --comments=5
	 *
	 *     # Global search across all of Reddit
	 *     wp datamachine-socials reddit fetch --query="best live music calendar" --sort=relevance --timeframe=30_days
	 *     wp datamachine-socials reddit fetch --query="concert events near me" --min-upvotes=5 --comments=3
	 *
	 *     # Search within a specific subreddit
	 *     wp datamachine-socials reddit fetch Austin --query="live music tonight"
	 */
	public function fetch( $args, $assoc_args ) {
		$subreddit    = $args[0] ?? '';
		$query        = $assoc_args['query'] ?? '';
		$access_token = $this->get_access_token();

		// Validate: must have at least one of subreddit or query.
		if ( empty( $subreddit ) && empty( $query ) ) {
			WP_CLI::error( 'Provide a subreddit name or --query (or both).' );
		}

		// Default sort to 'relevance' for search queries.
		$default_sort = ! empty( $query ) ? 'relevance' : 'hot';

		// Build ability input.
		$input = array(
			'subreddit'         => $subreddit,
			'query'             => $query,
			'access_token'      => $access_token,
			'sort_by'           => $assoc_args['sort'] ?? $default_sort,
			'timeframe_limit'   => $assoc_args['timeframe'] ?? 'all_time',
			'min_upvotes'       => absint( $assoc_args['min-upvotes'] ?? 0 ),
			'min_comment_count' => absint( $assoc_args['min-comments'] ?? 0 ),
			'comment_count'     => absint( $assoc_args['comments'] ?? 0 ),
			'search'            => $assoc_args['search'] ?? '',
			'processed_items'   => array(),
			'fetch_batch_size'  => 100,
			'max_pages'         => 5,
			'download_images'   => false, // CLI doesn't download images by default.
		);

		if ( ! empty( $subreddit ) && ! empty( $query ) ) {
			WP_CLI::log( "Searching r/{$subreddit} for \"{$query}\" (sort: {$input['sort_by']})..." );
		} elseif ( ! empty( $query ) ) {
			WP_CLI::log( "Searching all of Reddit for \"{$query}\" (sort: {$input['sort_by']})..." );
		} else {
			WP_CLI::log( "Fetching from r/{$subreddit} (sort: {$input['sort_by']})..." );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/fetch-reddit' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/fetch-reddit ability not registered.' );
		}

		$result = $ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Reddit fetch failed.' );
		}

		// The ability returns 'items' array for multiple results.
		$items = $result['items'] ?? array();

		// Backward compat: single-result 'data' key.
		if ( empty( $items ) && ! empty( $result['data'] ) ) {
			$items = is_array( $result['data'] ) ? array( array( 'data' => $result['data'], 'source_url' => $result['source_url'] ?? '', 'item_id' => $result['item_id'] ?? '' ) ) : array();
		}

		if ( empty( $items ) ) {
			WP_CLI::warning( 'No eligible posts found with the given filters.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$this->output_yaml( $items );
			return;
		}

		// Table format: show all results.
		$rows = array();
		foreach ( $items as $item ) {
			$data     = $item['data'] ?? array();
			$metadata = $data['metadata'] ?? array();
			$rows[]   = array(
				'score'     => $metadata['upvotes'] ?? 0,
				'comments'  => $metadata['comment_count'] ?? 0,
				'subreddit' => 'r/' . ( $metadata['subreddit'] ?? '' ),
				'author'    => $metadata['author'] ?? '[deleted]',
				'title'     => mb_substr( $data['title'] ?? '', 0, 60 ),
				'url'       => $item['source_url'] ?? '',
			);
		}

		WP_CLI::success( count( $rows ) . ' posts found' );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'score', 'comments', 'subreddit', 'author', 'title' ) );
	}

	/**
	 * Show Reddit authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials reddit status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'reddit' );

		WP_CLI::log( 'Reddit Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			WP_CLI::log( 'Authenticated: No' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		$details       = $provider->get_account_details();

		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		if ( $details ) {
			WP_CLI::log( 'Username:      ' . ( $details['username'] ?? 'unknown' ) );
			WP_CLI::log( 'Scope:         ' . ( $details['scope'] ?? 'unknown' ) );

			if ( ! empty( $details['token_expires_at'] ) ) {
				$expires_at = intval( $details['token_expires_at'] );
				$remaining  = $expires_at - time();

				if ( $remaining > 0 ) {
					$minutes = round( $remaining / 60 );
					WP_CLI::log( "Token expires: in {$minutes} minutes" );
				} else {
					WP_CLI::log( 'Token expires: EXPIRED (will auto-refresh)' );
				}
			}

			if ( ! empty( $details['last_refreshed_at'] ) ) {
				WP_CLI::log( 'Last refresh:  ' . wp_date( 'Y-m-d H:i:s', intval( $details['last_refreshed_at'] ) ) );
			}

			$next_cron = wp_next_scheduled( 'datamachine_refresh_token_reddit' );
			if ( $next_cron ) {
				WP_CLI::log( 'Next cron:     ' . wp_date( 'Y-m-d H:i:s', $next_cron ) );
			}
		}
	}

	/**
	 * Reply to a Reddit post or comment.
	 *
	 * Posts a comment in reply to a post or another comment. The thing_id
	 * must be a Reddit fullname: t3_xxx for posts, t1_xxx for comments.
	 * You can find these IDs in the URL or via the `fetch` and `posts` commands.
	 *
	 * ## OPTIONS
	 *
	 * <thing_id>
	 * : Reddit fullname of the post (t3_xxx) or comment (t1_xxx) to reply to.
	 *
	 * <text>
	 * : Reply text. Supports Reddit markdown.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials reddit reply t3_abc123 "Great post! Check out events.extrachill.com for more."
	 *     wp datamachine-socials reddit reply t1_def456 "Totally agree with this."
	 *     wp datamachine-socials reddit reply t3_abc123 "Here's a [link](https://example.com)" --format=json
	 */
	public function reply( $args, $assoc_args ) {
		$thing_id     = $args[0];
		$text         = $args[1];
		$format       = $assoc_args['format'] ?? 'table';
		$access_token = $this->get_access_token();

		$type_label = str_starts_with( $thing_id, 't3_' ) ? 'post' : 'comment';
		WP_CLI::log( "Replying to {$type_label} {$thing_id}..." );

		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/reply-reddit' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/reply-reddit ability not registered.' );
		}

		$result = $ability->execute( array(
			'thing_id'     => $thing_id,
			'text'         => $text,
			'access_token' => $access_token,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Reddit reply failed.' );
		}

		$data = $result['data'] ?? array();

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$this->output_yaml( $data );
			return;
		}

		WP_CLI::success( "Reply posted to {$type_label} {$thing_id}" );
		if ( ! empty( $data['comment_url'] ) ) {
			WP_CLI::log( 'URL:    ' . $data['comment_url'] );
		}
		if ( ! empty( $data['comment_id'] ) ) {
			WP_CLI::log( 'ID:     ' . $data['comment_id'] );
		}
		WP_CLI::log( 'Author: ' . ( $data['author'] ?? 'unknown' ) );
	}
