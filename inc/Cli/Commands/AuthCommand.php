<?php
/**
 * WP-CLI Auth Health Command
 *
 * Provides a cross-platform OAuth token health dashboard showing all
 * connected platforms, their token expiry status, and manual refresh.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.10.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\OAuth\BaseOAuth2Provider;
use DataMachine\Core\OAuth\BaseOAuth1Provider;
use DataMachine\Core\OAuth\BaseAuthProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Manage OAuth authentication health across all social platforms.
 *
 * ## EXAMPLES
 *
 *     # Show auth health dashboard for all platforms
 *     wp datamachine-socials auth status
 *
 *     # Manually refresh a specific platform's token
 *     wp datamachine-socials auth refresh instagram
 *
 *     # Run the daily health check immediately
 *     wp datamachine-socials auth health-check
 */
class AuthCommand {

	/**
	 * Show authentication health status for all connected platforms.
	 *
	 * Displays a table with each platform's connection status, token expiry
	 * date, time until expiry, and health indicator.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials auth status
	 *     wp datamachine-socials auth status --format=json
	 */
	public function status( $args, $assoc_args ) {
		$auth_abilities = new AuthAbilities();
		$providers      = $auth_abilities->getAllProviders();

		if ( empty( $providers ) ) {
			WP_CLI::warning( 'No auth providers registered.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		$rows   = array();

		foreach ( $providers as $slug => $provider ) {
			$row = $this->build_provider_row( $slug, $provider );
			$rows[] = $row;
		}

		// Sort: expired first, then warning, then healthy, then disconnected.
		usort( $rows, function ( $a, $b ) {
			$order = array( 'EXPIRED' => 0, 'WARNING' => 1, 'HEALTHY' => 2, 'NO TOKEN' => 3, 'N/A' => 4 );
			$a_ord = $order[ $a['health'] ] ?? 5;
			$b_ord = $order[ $b['health'] ] ?? 5;
			return $a_ord <=> $b_ord;
		});

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Table format.
		WP_CLI::log( '' );
		WP_CLI::log( 'OAuth Token Health Dashboard' );
		WP_CLI::log( str_repeat( '─', 72 ) );

		// Header.
		WP_CLI::log( sprintf(
			'  %-12s  %-14s  %-14s  %-14s  %-10s',
			'Platform', 'Status', 'Expires', 'Time Left', 'Health'
		) );
		WP_CLI::log( str_repeat( '─', 72 ) );

		foreach ( $rows as $row ) {
			WP_CLI::log( sprintf(
				'  %-12s  %-14s  %-14s  %-14s  %-10s',
				$row['platform'],
				$row['status'],
				$row['expires_at'],
				$row['time_left'],
				$row['health']
			) );

			if ( ! empty( $row['username'] ) ) {
				WP_CLI::log( sprintf( '  %12s  @%s', '', $row['username'] ) );
			}
		}

		WP_CLI::log( str_repeat( '─', 72 ) );

		// Summary.
		$connected  = count( array_filter( $rows, fn( $r ) => 'connected' === $r['status'] ) );
		$expired    = count( array_filter( $rows, fn( $r ) => 'EXPIRED' === $r['health'] ) );
		$warnings   = count( array_filter( $rows, fn( $r ) => 'WARNING' === $r['health'] ) );
		$total      = count( $rows );

		WP_CLI::log( sprintf(
			'  %d platforms total | %d connected | %d expired | %d warning',
			$total,
			$connected,
			$expired,
			$warnings
		) );

		// Show next cron run for the health check task.
		$next_cron = wp_next_scheduled( \DataMachineSocials\Tasks\TokenHealthCheckTask::CRON_HOOK );
		if ( $next_cron ) {
			WP_CLI::log( sprintf( '  Next health check: %s', wp_date( 'Y-m-d H:i:s', $next_cron ) ) );
		}

		WP_CLI::log( '' );
	}

	/**
	 * Manually refresh a platform's OAuth token.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform slug (e.g. instagram, facebook, threads, pinterest, reddit, linkedin).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials auth refresh instagram
	 */
	public function refresh( $args, $assoc_args ) {
		$assoc_args;
		$platform = sanitize_text_field( $args[0] ?? '' );

		if ( empty( $platform ) ) {
			WP_CLI::error( 'Platform slug is required.' );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( $platform );

		if ( ! $provider ) {
			WP_CLI::error( "No auth provider found for '{$platform}'." );
		}

		if ( ! ( $provider instanceof BaseOAuth2Provider ) ) {
			WP_CLI::error( "Platform '{$platform}' does not support OAuth2 token refresh." );
		}

		$account = $provider->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			WP_CLI::error( "Platform '{$platform}' is not connected. Authenticate first." );
		}

		WP_CLI::log( "Refreshing token for {$platform}..." );

		$token = $provider->get_valid_access_token();

		if ( null === $token ) {
			WP_CLI::error( "Token refresh failed for {$platform}. Re-authorization may be required." );
		}

		// Get updated account data.
		$account    = $provider->get_account();
		$expires_at = ! empty( $account['token_expires_at'] )
			? wp_date( 'Y-m-d H:i:s', intval( $account['token_expires_at'] ) )
			: 'unknown';

		WP_CLI::success( "{$platform} token refreshed successfully!" );
		WP_CLI::log( "New expiry: {$expires_at}" );
	}

	/**
	 * Run the token health check immediately.
	 *
	 * Executes the same scan that runs on the daily cron — checks all
	 * connected platforms, attempts refresh for expiring tokens, and
	 * fires notification hooks for failures.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials auth health-check
	 */
	public function health_check( $args, $assoc_args ) {
		$args;
		$assoc_args;
		WP_CLI::log( 'Running token health check...' );
		WP_CLI::log( '' );

		\DataMachineSocials\Tasks\TokenHealthCheckTask::run();

		WP_CLI::success( 'Token health check complete. Check Data Machine logs for details.' );
	}

	/**
	 * Build a status row for a single provider.
	 *
	 * @param string $slug     Provider slug.
	 * @param object $provider Provider instance.
	 * @return array Row data.
	 */
	private function build_provider_row( string $slug, object $provider ): array {
		$is_oauth2    = $provider instanceof BaseOAuth2Provider;
		$is_oauth1    = $provider instanceof BaseOAuth1Provider;
		$is_connected = method_exists( $provider, 'is_authenticated' ) && $provider->is_authenticated();
		$username     = method_exists( $provider, 'get_username' ) ? $provider->get_username() : null;

		$row = array(
			'platform'   => $slug,
			'status'     => $is_connected ? 'connected' : 'disconnected',
			'username'   => $username ?? '',
			'expires_at' => 'N/A',
			'time_left'  => 'N/A',
			'health'     => 'N/A',
		);

		// Only OAuth2 providers have token expiry lifecycle.
		if ( ! $is_oauth2 ) {
			if ( $is_oauth1 ) {
				$row['health'] = $is_connected ? 'HEALTHY' : 'NO TOKEN';
			}
			return $row;
		}

		$account = $provider->get_account();
		if ( empty( $account ) || ! is_array( $account ) ) {
			return $row;
		}

		if ( empty( $account['token_expires_at'] ) ) {
			$row['health'] = $is_connected ? 'HEALTHY' : 'NO TOKEN';
			return $row;
		}

		$expires_at = intval( $account['token_expires_at'] );
		$remaining  = $expires_at - time();

		$row['expires_at'] = wp_date( 'Y-m-d', $expires_at );

		if ( $remaining <= 0 ) {
			$row['time_left'] = 'EXPIRED';
			$row['health']    = 'EXPIRED';
		} elseif ( $remaining < 7 * DAY_IN_SECONDS ) {
			$days = round( $remaining / DAY_IN_SECONDS, 1 );
			$row['time_left'] = "{$days} days";
			$row['health']    = 'WARNING';
		} else {
			$days = round( $remaining / DAY_IN_SECONDS );
			$row['time_left'] = "{$days} days";
			$row['health']    = 'HEALTHY';
		}

		return $row;
	}
}
