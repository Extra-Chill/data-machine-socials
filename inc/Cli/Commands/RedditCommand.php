<?php
/**
 * WP-CLI Reddit Command
 *
 * Provides CLI access to Reddit fetch operations, subreddit discovery,
 * and account management. Wraps the FetchRedditAbility and RedditAuth
 * providers, plus direct Reddit API calls for discovery commands.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.3.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Reddit integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     # Fetch a post from a subreddit
 *     wp datamachine-socials reddit fetch jambands
 *
 *     # Get subreddit info
 *     wp datamachine-socials reddit info bonnaroo
 *
 *     # Search for subreddits
 *     wp datamachine-socials reddit search "music festivals"
 *
 *     # List posts with scores
 *     wp datamachine-socials reddit posts sxsw --sort=top --limit=20
 *
 *     # Browse trending subreddits
 *     wp datamachine-socials reddit trending --limit=10
 *
 *     # Check Reddit auth status
 *     wp datamachine-socials reddit status
 *
 *     # Reply to a post or comment
 *     wp datamachine-socials reddit reply t3_abc123 "Great post!"
 *
 *     # Submit a new text post
 *     wp datamachine-socials reddit submit test --title="Hello World" --text="This is a test post"
 *
 *     # Submit a link post
 *     wp datamachine-socials reddit submit test --title="Check this out" --url="https://example.com"
 *
 *     # Upvote a post
 *     wp datamachine-socials reddit vote t3_abc123 --up
 */
class RedditCommand {

	/**
	 * Reddit OAuth API base URL.
	 */
	private const API_BASE = 'https://oauth.reddit.com';

	/**
	 * Make an authenticated GET request to the Reddit OAuth API.
	 *
	 * @param string $endpoint API endpoint path (e.g. "/r/bonnaroo/about").
	 * @param array  $params   Optional query parameters.
	 * @return array Decoded JSON response data.
	 */
	private function reddit_api_get( string $endpoint, array $params = array() ): array {
		$access_token = $this->get_access_token();

		$url = self::API_BASE . $endpoint . '.json';
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		$result = HttpClient::get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by Data Machine)',
			),
			'context' => 'Reddit CLI',
		) );

		if ( ! $result['success'] || 200 !== ( $result['status_code'] ?? 0 ) ) {
			$status = $result['status_code'] ?? 'unknown';
			WP_CLI::error( "Reddit API request failed (HTTP {$status}): {$endpoint}" );
		}

		$decoded = json_decode( $result['data'] ?? '', true );
		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( "Reddit API returned invalid JSON for {$endpoint}" );
		}

		return $decoded;
	}
}
