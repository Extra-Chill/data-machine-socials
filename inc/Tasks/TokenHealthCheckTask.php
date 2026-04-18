<?php
/**
 * OAuth Token Health Check Task
 *
 * Scheduled daily task that scans all connected OAuth providers,
 * checks token expiry, attempts proactive refresh for tokens nearing
 * expiration, and fires notification hooks for expired or failing tokens.
 *
 * Complements the per-provider WP-Cron proactive refresh chain by providing
 * a cross-platform health scan — catching edge cases where individual cron
 * events were missed or never scheduled.
 *
 * @package DataMachineSocials\Tasks
 * @since 0.10.0
 */

namespace DataMachineSocials\Tasks;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\OAuth\BaseOAuth2Provider;

defined( 'ABSPATH' ) || exit;

class TokenHealthCheckTask {

	/**
	 * WP-Cron hook name for the daily health check.
	 */
	const CRON_HOOK = 'datamachine_socials_token_health_check';

	/**
	 * How many seconds before expiry to attempt a proactive refresh.
	 */
	const REFRESH_WINDOW = 7 * DAY_IN_SECONDS;

	/**
	 * Register the cron hook and schedule the daily event.
	 */
	public static function register(): void {
		add_action( static::CRON_HOOK, array( static::class, 'run' ) );

		// Schedule daily event if not already scheduled.
		if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', static::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily health check (called on deactivation).
	 */
	public static function unregister(): void {
		$timestamp = wp_next_scheduled( static::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, static::CRON_HOOK );
		}
	}

	/**
	 * Run the health check.
	 *
	 * Scans all connected OAuth2 providers. For each:
	 * 1. If token expires within 7 days → attempt refresh
	 * 2. If token is already expired → fire expiry hook
	 * 3. On refresh failure → fire expiry hook
	 *
	 * All activity is logged via datamachine_log.
	 */
	public static function run(): void {
		$auth_abilities = new AuthAbilities();
		$providers      = $auth_abilities->getAllProviders();

		if ( empty( $providers ) ) {
			do_action( 'datamachine_log', 'debug', 'Token Health Check: No providers registered' );
			return;
		}

		$results = array(
			'checked'  => 0,
			'refreshed' => 0,
			'warnings'  => 0,
			'expired'   => 0,
		);

		foreach ( $providers as $slug => $provider ) {
			// Only check OAuth2 providers with token lifecycle management.
			if ( ! ( $provider instanceof BaseOAuth2Provider ) ) {
				continue;
			}

			$account = $provider->get_account();
			if ( empty( $account ) || ! is_array( $account ) ) {
				continue;
			}

			// Skip providers without an expiry timestamp (no token lifecycle).
			if ( empty( $account['token_expires_at'] ) ) {
				continue;
			}

			$results['checked']++;

			$expires_at = intval( $account['token_expires_at'] );
			$remaining  = $expires_at - time();
			$platform   = $slug;

			// Already expired.
			if ( $remaining <= 0 ) {
				$results['expired']++;

				do_action(
					'datamachine_log',
					'error',
					"Token Health Check: {$platform} token EXPIRED",
					array(
						'platform'   => $platform,
						'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_at ),
						'action'     => 'attempting_refresh',
					)
				);

				// Attempt recovery refresh.
				$token = $provider->get_valid_access_token();

				if ( null !== $token ) {
					$results['refreshed']++;
					$results['expired']--;

					do_action(
						'datamachine_log',
						'info',
						"Token Health Check: {$platform} token recovered via refresh",
						array( 'platform' => $platform )
					);
				} else {
					/**
					 * Fires when an OAuth token is expired and refresh fails.
					 *
					 * External systems (Discord alerts, email, admin notices) hook
					 * into this to notify the team that re-authorization is needed.
					 *
					 * @since 0.10.0
					 * @param string $platform   Provider slug (e.g. 'instagram', 'facebook').
					 * @param int    $expires_at Unix timestamp when the token expired.
					 */
					do_action( 'datamachine_oauth_token_expired', $platform, $expires_at );
				}
				continue;
			}

			// Within refresh window — proactive refresh.
			if ( $remaining < static::REFRESH_WINDOW ) {
				$days = round( $remaining / DAY_IN_SECONDS, 1 );

				do_action(
					'datamachine_log',
					'info',
					"Token Health Check: {$platform} token expires in {$days} days — attempting proactive refresh",
					array(
						'platform'   => $platform,
						'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_at ),
						'remaining'  => $remaining,
					)
				);

				$token = $provider->get_valid_access_token();

				if ( null !== $token ) {
					$results['refreshed']++;

					do_action(
						'datamachine_log',
						'info',
						"Token Health Check: {$platform} token refreshed successfully",
						array( 'platform' => $platform )
					);
				} else {
					$results['warnings']++;

					do_action(
						'datamachine_log',
						'warning',
						"Token Health Check: {$platform} refresh failed — token expires in {$days} days",
						array(
							'platform'  => $platform,
							'remaining' => $remaining,
						)
					);

					// Fire warning hook even though not expired yet.
					/** This action is documented above. */
					do_action( 'datamachine_oauth_token_expired', $platform, $expires_at );
				}
				continue;
			}

			// Token is healthy.
			$days = round( $remaining / DAY_IN_SECONDS, 1 );
			do_action(
				'datamachine_log',
				'debug',
				"Token Health Check: {$platform} token healthy (expires in {$days} days)",
				array(
					'platform'   => $platform,
					'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_at ),
					'remaining'  => $remaining,
				)
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Token Health Check: Daily scan complete',
			$results
		);
	}
}
