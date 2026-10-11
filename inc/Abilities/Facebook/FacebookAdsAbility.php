<?php
/**
 * Facebook Ads Ability
 *
 * Paid Meta promotion with a mandatory human approval gate (#281).
 *
 * - datamachine/facebook-ad-accounts    Read: ad accounts the user token can see.
 * - datamachine/facebook-ads-interests  Read: resolve interest names to Meta IDs.
 * - datamachine/facebook-ads-report     Read: campaign spend/reach/clicks.
 * - datamachine/facebook-ads-promote    Creates a PAUSED campaign/ad set/ad and
 *                                       stages a pending action. Never spends.
 *
 * Spend only starts when a human with manage_options accepts the pending
 * action (`wp datamachine pending-actions accept <id>`); the apply handler
 * re-checks the live ad set against the hard limits before activating.
 * Rejected or expired promotions are deleted from the ad account.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;

defined( 'ABSPATH' ) || exit;

class FacebookAdsAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	/** Pending-action kind dispatched to apply(). */
	public const KIND = 'socials_facebook_promotion';

	/** Approval window; unapproved promotions are deleted when it lapses. */
	private const APPROVAL_TTL = 2 * DAY_IN_SECONDS;

	private static bool $hooks_added = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );

		if ( ! self::$hooks_added ) {
			add_filter( 'datamachine_pending_action_handlers', array( $this, 'pendingActionHandlers' ) );
			add_action( 'datamachine_pending_action_resolved', array( $this, 'onResolved' ), 10, 2 );
			add_action( 'datamachine_pending_action_expired', array( $this, 'onExpired' ) );
			self::$hooks_added = true;
		}
	}

	private function registerCallback(): callable {
		return function () {
			$object = array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);

			wp_register_ability(
				'datamachine/facebook-ad-accounts',
				array(
					'label'               => __( 'List Facebook Ad Accounts', 'data-machine-socials' ),
					'description'         => __( 'List Meta ad accounts available to the connected Facebook user.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => $object,
					'execute_callback'    => array( $this, 'listAdAccounts' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'readonly' => true ),
					),
				)
			);

			wp_register_ability(
				'datamachine/facebook-ads-interests',
				array(
					'label'               => __( 'Search Facebook Ad Interests', 'data-machine-socials' ),
					'description'         => __( 'Resolve an interest keyword to Meta ad targeting interests.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array( 'type' => 'string' ),
							'limit' => array( 'type' => 'integer' ),
						),
						'required'   => array( 'query' ),
					),
					'output_schema'       => $object,
					'execute_callback'    => array( $this, 'searchInterests' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'readonly' => true ),
					),
				)
			);

			wp_register_ability(
				'datamachine/facebook-ads-report',
				array(
					'label'               => __( 'Facebook Ads Report', 'data-machine-socials' ),
					'description'         => __( 'Spend, reach, impressions and link clicks for promotions in the configured ad account.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'campaign_id' => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => $object,
					'execute_callback'    => array( $this, 'report' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array( 'readonly' => true ),
					),
				)
			);

			wp_register_ability(
				'datamachine/facebook-ads-promote',
				array(
					'label'               => __( 'Stage Facebook Promotion', 'data-machine-socials' ),
					'description'         => __( 'Create a PAUSED, budget-capped Meta link-click promotion and stage it for human approval. Never activates spend.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'         => array( 'type' => 'string' ),
							'budget'       => array(
								'type'        => 'number',
								'description' => 'Lifetime budget in the ad account currency (major units). Capped by max_lifetime_budget.',
							),
							'start_time'   => array( 'type' => 'string' ),
							'end_time'     => array( 'type' => 'string' ),
							'latitude'     => array( 'type' => 'number' ),
							'longitude'    => array( 'type' => 'number' ),
							'radius_miles' => array( 'type' => 'integer' ),
							'age_min'      => array( 'type' => 'integer' ),
							'age_max'      => array( 'type' => 'integer' ),
							'interests'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'link'         => array(
								'type'        => 'string',
								'description' => 'Destination URL (e.g. the event RSVP page).',
							),
							'post_id'      => array(
								'type'        => 'string',
								'description' => 'Existing Page post to promote. Omit to build a link ad from message + image.',
							),
							'message'      => array( 'type' => 'string' ),
							'headline'     => array( 'type' => 'string' ),
							'image_path'   => array( 'type' => 'string' ),
							'image_url'    => array( 'type' => 'string' ),
						),
						'required'   => array( 'budget', 'end_time', 'latitude', 'longitude', 'radius_miles', 'link' ),
					),
					'output_schema'       => $object,
					'execute_callback'    => array( $this, 'promote' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						// Staging-only, but it spends real money once approved: keep it
						// off the generic REST runner; CLI and in-process agents use it.
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
						),
					),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	// ---------------------------------------------------------------------
	// Read abilities
	// ---------------------------------------------------------------------

	public function listAdAccounts( array $input = array() ) {
		unset( $input );
		$token = $this->adsToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = $this->graph( 'GET', '/me/adaccounts', array( 'fields' => 'id,name,account_status,currency' ), $token );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'success'  => true,
			'accounts' => $res['data'] ?? array(),
			'selected' => FacebookAdsPolicy::settings()['ad_account_id'],
		);
	}

	public function searchInterests( array $input ) {
		$token = $this->adsToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$query = trim( (string) ( $input['query'] ?? '' ) );
		if ( '' === $query ) {
			return new \WP_Error( 'missing_param', 'query is required', array( 'status' => 400 ) );
		}
		$res = $this->graph(
			'GET',
			'/search',
			array(
				'type'  => 'adinterest',
				'q'     => $query,
				'limit' => max( 1, min( 25, (int) ( $input['limit'] ?? 10 ) ) ),
			),
			$token
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$interests = array_map(
			static fn( $i ) => array(
				'id'            => (string) ( $i['id'] ?? '' ),
				'name'          => (string) ( $i['name'] ?? '' ),
				'audience_size' => $i['audience_size_upper_bound'] ?? null,
			),
			(array) ( $res['data'] ?? array() )
		);
		return array(
			'success'   => true,
			'interests' => $interests,
		);
	}

	public function report( array $input = array() ) {
		$token = $this->adsToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$fields   = 'spend,reach,impressions,inline_link_clicks,cpc';
		$campaign = trim( (string) ( $input['campaign_id'] ?? '' ) );
		if ( '' !== $campaign ) {
			$res = $this->graph( 'GET', '/' . rawurlencode( $campaign ), array( 'fields' => 'id,name,status,effective_status,insights.date_preset(maximum){' . $fields . '}' ), $token );
			return is_wp_error( $res ) ? $res : array(
				'success'   => true,
				'campaigns' => array( $res ),
			);
		}
		$account = FacebookAdsPolicy::settings()['ad_account_id'];
		if ( '' === $account ) {
			return new \WP_Error( 'ads_not_configured', 'No ad account selected.' );
		}
		$res = $this->graph(
			'GET',
			'/' . $account . '/campaigns',
			array(
				'fields' => 'id,name,status,effective_status,insights.date_preset(maximum){' . $fields . '}',
				'limit'  => 25,
			),
			$token
		);
		return is_wp_error( $res ) ? $res : array(
			'success'   => true,
			'campaigns' => $res['data'] ?? array(),
		);
	}

	// ---------------------------------------------------------------------
	// Promote (stage only)
	// ---------------------------------------------------------------------

	public function promote( array $input ) {
		$limits    = FacebookAdsPolicy::settings();
		$promotion = FacebookAdsPolicy::validate( $input, $limits, time() );
		if ( is_wp_error( $promotion ) ) {
			return $promotion;
		}

		$token = $this->adsToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$auth    = $this->auth();
		$page_id = $auth ? (string) $auth->get_page_id() : '';
		if ( '' === $page_id ) {
			return new \WP_Error( 'missing_page', 'No Facebook Page connected.' );
		}

		$account = $this->graph( 'GET', '/' . $promotion['ad_account_id'], array( 'fields' => 'currency,account_status' ), $token );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		if ( 1 !== (int) ( $account['account_status'] ?? 0 ) ) {
			return new \WP_Error( 'ad_account_inactive', 'The selected ad account is not active.' );
		}
		$currency = (string) ( $account['currency'] ?? 'USD' );
		$offset   = FacebookAdsPolicy::currency_offset( $currency );

		$interests = array();
		foreach ( $promotion['interests'] as $query ) {
			$found = $this->searchInterests(
				array(
					'query' => $query,
					'limit' => 1,
				)
			);
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( ! empty( $found['interests'][0]['id'] ) ) {
				$interests[] = array(
					'id'   => $found['interests'][0]['id'],
					'name' => $found['interests'][0]['name'],
				);
			}
		}

		$instagram_id = $this->instagramUserId();
		$act          = '/' . $promotion['ad_account_id'];
		$ids          = array();

		$campaign = $this->graph(
			'POST',
			$act . '/campaigns',
			array(
				'name'                            => $promotion['name'],
				'objective'                       => 'OUTCOME_TRAFFIC',
				'status'                          => 'PAUSED',
				'special_ad_categories'           => array(),
				'buying_type'                     => 'AUCTION',
				'is_adset_budget_sharing_enabled' => false,
			),
			$token
		);
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		$ids['campaign_id'] = (string) $campaign['id'];

		$adset = $this->graph(
			'POST',
			$act . '/adsets',
			array(
				'name'              => $promotion['name'] . ' - audience',
				'campaign_id'       => $ids['campaign_id'],
				'lifetime_budget'   => (int) round( $promotion['budget'] * $offset ),
				'start_time'        => gmdate( 'c', $promotion['start_time'] ),
				'end_time'          => gmdate( 'c', $promotion['end_time'] ),
				'billing_event'     => 'IMPRESSIONS',
				'optimization_goal' => 'LINK_CLICKS',
				'bid_strategy'      => 'LOWEST_COST_WITHOUT_CAP',
				'destination_type'  => 'WEBSITE',
				'targeting'         => FacebookAdsPolicy::targeting( $promotion, $interests, '' !== $instagram_id ),
				'status'            => 'PAUSED',
			),
			$token
		);
		if ( is_wp_error( $adset ) ) {
			return $this->abort( $ids, $token, $adset );
		}
		$ids['adset_id'] = (string) $adset['id'];

		$creative_spec = $this->creativeSpec( $promotion, $page_id, $instagram_id, $token );
		if ( is_wp_error( $creative_spec ) ) {
			return $this->abort( $ids, $token, $creative_spec );
		}
		$creative = $this->graph( 'POST', $act . '/adcreatives', $creative_spec, $token );
		if ( is_wp_error( $creative ) ) {
			return $this->abort( $ids, $token, $creative );
		}
		$ids['creative_id'] = (string) $creative['id'];

		$ad = $this->graph(
			'POST',
			$act . '/ads',
			array(
				'name'     => $promotion['name'] . ' - ad',
				'adset_id' => $ids['adset_id'],
				'creative' => array( 'creative_id' => $ids['creative_id'] ),
				'status'   => 'PAUSED',
			),
			$token
		);
		if ( is_wp_error( $ad ) ) {
			return $this->abort( $ids, $token, $ad );
		}
		$ids['ad_id'] = (string) $ad['id'];

		$preview = FacebookAdsPolicy::preview( $promotion, $interests, $currency );
		$staged  = \DataMachine\Engine\AI\Actions\PendingActionHelper::stage(
			array(
				'kind'            => self::KIND,
				'summary'         => sprintf( 'Spend up to %s %s promoting "%s" to %s', number_format( $promotion['budget'], 2 ), strtoupper( $currency ), $promotion['name'], $preview['audience'] ),
				'apply_input'     => array_merge(
					$ids,
					array(
						'ad_account_id'   => $promotion['ad_account_id'],
						'currency_offset' => $offset,
					)
				),
				'preview_data'    => $preview,
				'ttl'             => self::APPROVAL_TTL,
				// A site operator may resolve from the CLI. No agent grant:
				// agents (e.g. the chat resolve_pending_action tool) are refused.
				'resolver_grants' => array(
					array(
						'type' => 'operator',
						'kind' => self::KIND,
					),
				),
			)
		);

		if ( isset( $staged['staged'] ) && false === $staged['staged'] ) {
			return $this->abort( $ids, $token, new \WP_Error( 'stage_failed', (string) ( $staged['error'] ?? 'Failed to stage approval.' ) ) );
		}

		$action_id = (string) ( $staged['payload']['action_id'] ?? ( $staged['action_id'] ?? '' ) );
		$links     = array();
		if ( '' !== $action_id && class_exists( '\DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility' ) ) {
			$signed = \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::execute(
				array(
					'action_id' => $action_id,
					'lifetime'  => self::APPROVAL_TTL,
				)
			);
			if ( ! empty( $signed['success'] ) ) {
				$links = array(
					'approve_url' => (string) $signed['approve_url'],
					'reject_url'  => (string) $signed['reject_url'],
					'expires_at'  => (string) $signed['expires_at'],
				);
			}
		}

		return array(
			'success'   => true,
			'status'    => 'paused_pending_approval',
			'action_id' => $action_id,
			'ids'       => $ids,
			'preview'   => $preview,
			'links'     => $links,
			'approval'  => $staged,
		);
	}

	// ---------------------------------------------------------------------
	// Approval gate
	// ---------------------------------------------------------------------

	public function pendingActionHandlers( $handlers ) {
		$handlers               = is_array( $handlers ) ? $handlers : array();
		$handlers[ self::KIND ] = array(
			'apply'       => array( $this, 'apply' ),
			'can_resolve' => array( self::class, 'canResolve' ),
		);
		return $handlers;
	}

	/**
	 * Who may approve or reject spend.
	 *
	 * - A signed approve/reject link (minted at staging, delivered to a human).
	 * - A logged-in user or CLI operator acting as a site administrator.
	 *
	 * Agent resolvers never reach this check: no agent grant is staged, so
	 * the resolver denies them first.
	 *
	 * @param array  $payload  Pending action payload.
	 * @param string $decision accepted|rejected.
	 * @param int    $user_id  Current user during resolution.
	 * @param array  $context  Resolver context.
	 * @return true|\WP_Error
	 */
	public static function canResolve( $payload, $decision, $user_id, $context = array() ) {
		unset( $payload, $decision );
		$context = is_array( $context ) ? $context : array();
		if ( 'signed_url' === ( $context['resolution_transport'] ?? '' ) ) {
			return true;
		}
		$user_id = (int) $user_id;
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'spend_requires_admin', 'Only a site administrator can approve paid promotion spend.', array( 'status' => 403 ) );
	}

	/**
	 * Activate an approved promotion after re-checking hard limits.
	 *
	 * Signature matches the resolver's apply contract (input, payload,
	 * one-time receipt); the resolver rejects handlers with fewer params.
	 *
	 * @param array $input   Stored apply_input.
	 * @param array $payload Stored pending action payload.
	 * @param array $receipt One-time authorization receipt.
	 * @return array|\WP_Error
	 */
	public function apply( array $input, array $payload = array(), array $receipt = array() ) {
		// Consume the one-time approval receipt before touching Meta, so an
		// approval can never be replayed into a second activation.
		$subject    = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
		$workspace  = isset( $payload['workspace'] ) && is_array( $payload['workspace'] ) ? $payload['workspace'] : array();
		$authorized = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::consume( $receipt, self::KIND, self::KIND, $input, $input, $subject, $workspace );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$token = $this->adsToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$adset = $this->graph( 'GET', '/' . rawurlencode( (string) ( $input['adset_id'] ?? '' ) ), array( 'fields' => 'lifetime_budget,start_time,end_time' ), $token );
		if ( is_wp_error( $adset ) ) {
			return $adset;
		}
		$ok = FacebookAdsPolicy::recheck(
			(int) ( $adset['lifetime_budget'] ?? PHP_INT_MAX ),
			(int) ( $input['currency_offset'] ?? 100 ),
			(int) strtotime( (string) ( $adset['start_time'] ?? '' ) ),
			(int) strtotime( (string) ( $adset['end_time'] ?? '' ) ),
			FacebookAdsPolicy::settings()
		);
		if ( is_wp_error( $ok ) ) {
			// Out-of-policy at approval time: remove it rather than leave it parked.
			$this->deleteObject( (string) ( $input['campaign_id'] ?? '' ), $token );
			return $ok;
		}

		// Campaign last: nothing can deliver until every layer is active.
		foreach ( array( 'ad_id', 'adset_id', 'campaign_id' ) as $key ) {
			$res = $this->graph( 'POST', '/' . rawurlencode( (string) ( $input[ $key ] ?? '' ) ), array( 'status' => 'ACTIVE' ), $token );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		return array(
			'success'     => true,
			'status'      => 'active',
			'campaign_id' => (string) ( $input['campaign_id'] ?? '' ),
		);
	}

	/**
	 * Delete the paused promotion when a human rejects it.
	 *
	 * @param mixed $action   WP_Agent_Pending_Action.
	 * @param mixed $decision WP_Agent_Approval_Decision.
	 */
	public function onResolved( $action, $decision ): void {
		if ( is_object( $decision ) && method_exists( $decision, 'is_rejected' ) && $decision->is_rejected() ) {
			$this->cleanupAction( $action );
		}
	}

	/**
	 * Delete the paused promotion when its approval window lapses.
	 *
	 * @param mixed $action WP_Agent_Pending_Action.
	 */
	public function onExpired( $action ): void {
		$this->cleanupAction( $action );
	}

	private function cleanupAction( $action ): void {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_kind' ) || self::KIND !== $action->get_kind() ) {
			return;
		}
		$input = (array) $action->get_apply_input();
		$token = $this->adsToken();
		if ( ! is_wp_error( $token ) && ! empty( $input['campaign_id'] ) ) {
			$this->deleteObject( (string) $input['campaign_id'], $token );
		}
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Build the ad creative spec.
	 *
	 * @return array|\WP_Error
	 */
	private function creativeSpec( array $promotion, string $page_id, string $instagram_id, string $token ) {
		if ( '' !== $promotion['post_id'] ) {
			$story = str_contains( $promotion['post_id'], '_' ) ? $promotion['post_id'] : $page_id . '_' . $promotion['post_id'];
			$spec  = array(
				'name'            => $promotion['name'] . ' - creative',
				'object_story_id' => $story,
			);
			if ( '' !== $instagram_id ) {
				$spec['instagram_user_id'] = $instagram_id;
			}
			return $spec;
		}

		$link_data = array(
			'link'           => $promotion['link'],
			'message'        => $promotion['message'],
			'call_to_action' => array(
				'type'  => 'LEARN_MORE',
				'value' => array( 'link' => $promotion['link'] ),
			),
		);
		if ( '' !== $promotion['headline'] ) {
			$link_data['name'] = $promotion['headline'];
		}

		if ( '' !== $promotion['image_path'] ) {
			if ( ! is_readable( $promotion['image_path'] ) ) {
				return new \WP_Error( 'invalid_creative', 'image_path is not readable.' );
			}
			$upload = $this->graph(
				'POST',
				'/' . $promotion['ad_account_id'] . '/adimages',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Graph adimages accepts base64 bytes of a local file.
				array( 'bytes' => base64_encode( (string) file_get_contents( $promotion['image_path'] ) ) ),
				$token
			);
			if ( is_wp_error( $upload ) ) {
				return $upload;
			}
			$image = is_array( $upload['images'] ?? null ) ? reset( $upload['images'] ) : null;
			if ( empty( $image['hash'] ) ) {
				return new \WP_Error( 'image_upload_failed', 'Meta did not return an image hash.' );
			}
			$link_data['image_hash'] = $image['hash'];
		} else {
			$link_data['picture'] = $promotion['image_url'];
		}

		$story_spec = array(
			'page_id'   => $page_id,
			'link_data' => $link_data,
		);
		if ( '' !== $instagram_id ) {
			$story_spec['instagram_user_id'] = $instagram_id;
		}

		return array(
			'name'              => $promotion['name'] . ' - creative',
			'object_story_spec' => $story_spec,
		);
	}

	/**
	 * Delete whatever was created before a failure, then return the error.
	 */
	private function abort( array $ids, string $token, \WP_Error $error ): \WP_Error {
		if ( ! empty( $ids['campaign_id'] ) ) {
			$this->deleteObject( $ids['campaign_id'], $token );
		}
		return $error;
	}

	private function deleteObject( string $id, string $token ): void {
		$this->graph( 'DELETE', '/' . rawurlencode( $id ), array(), $token );
	}

	/**
	 * User token that carries ads_management, or an error explaining how to get one.
	 *
	 * @return string|\WP_Error
	 */
	private function adsToken() {
		$auth = $this->auth();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Facebook auth provider not available', array( 'status' => 401 ) );
		}
		$token = (string) $auth->get_valid_user_access_token();
		if ( '' === $token ) {
			return new \WP_Error( 'missing_auth', 'Facebook is not connected.', array( 'status' => 401 ) );
		}
		if ( ! $auth->has_ads_permission() ) {
			return new \WP_Error( 'ads_permission_missing', 'The Facebook connection has no ads permission. Run `wp datamachine-socials facebook ads-connect` and approve ads access.', array( 'status' => 403 ) );
		}
		return $token;
	}

	private function auth(): ?FacebookAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}
		$provider = ( new \DataMachine\Abilities\AuthAbilities() )->getProvider( 'facebook' );
		return $provider instanceof FacebookAuth ? $provider : null;
	}

	private function instagramUserId(): string {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return '';
		}
		$ig = ( new \DataMachine\Abilities\AuthAbilities() )->getProvider( 'instagram' );
		return ( $ig && method_exists( $ig, 'get_user_id' ) ) ? (string) $ig->get_user_id() : '';
	}

	/**
	 * Call the Graph API.
	 *
	 * Array/bool parameter values are JSON-encoded, as Graph expects.
	 *
	 * @param string $method GET|POST|DELETE.
	 * @param string $path   Path beginning with "/".
	 * @param array  $params Parameters.
	 * @param string $token  Access token.
	 * @return array|\WP_Error Decoded body.
	 */
	private function graph( string $method, string $path, array $params, string $token ) {
		$params['access_token'] = $token;
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) || is_bool( $value ) ) {
				$params[ $key ] = wp_json_encode( $value );
			}
		}

		$url = FacebookAuth::GRAPH_API_URL . $path;
		if ( 'GET' === $method || 'DELETE' === $method ) {
			$result = HttpClient::request(
				$method,
				add_query_arg( array_map( 'rawurlencode', $params ), $url ),
				array(
					'context' => 'Facebook Ads',
					'timeout' => 30,
				)
			);
		} else {
			$result = HttpClient::post(
				$url,
				array(
					'context' => 'Facebook Ads',
					'timeout' => 60,
					'body'    => $params,
				)
			);
		}

		$body = json_decode( (string) ( $result['data'] ?? '' ), true );
		if ( empty( $result['success'] ) || ! is_array( $body ) || isset( $body['error'] ) ) {
			$message = is_array( $body ) && isset( $body['error']['error_user_msg'] ) ? $body['error']['error_user_msg']
				: ( is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : (string) ( $result['error'] ?? 'Graph request failed' ) );
			return new \WP_Error( 'facebook_ads_api_error', $message, array( 'status' => (int) ( $result['status_code'] ?? 502 ) ) );
		}
		return $body;
	}
}
