<?php
/**
 * FetchRedditAbility Tests
 *
 * Tests Reddit pagination against the Data Machine core fresh-candidate
 * collector integration.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Reddit
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Reddit;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use DataMachineSocials\Abilities\Reddit\FetchRedditAbility;
use WP_UnitTestCase;

class FetchRedditAbilityTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_collector_skips_processed_first_result_and_accepts_later_fresh_result(): void {
		$this->mockRedditPages(
			array(
				array(
					'after' => null,
					'posts' => array(
						$this->redditPost( 'processed-1', 'Already imported' ),
						$this->redditPost( 'fresh-1', 'Fresh post' ),
					),
				),
			)
		);

		$collector = new FreshCandidateCollector(
			$this->buildContext( array( 'processed-1' => true ) ),
			1
		);

		$result = ( new FetchRedditAbility() )->executeWithCollector(
			$this->fetchInput(),
			$collector
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'fresh-1', $result['items'][0]['item_id'] );

		$diagnostics = $collector->getDiagnostics();
		$this->assertSame( 2, $diagnostics['raw_seen'] );
		$this->assertSame( 1, $diagnostics['processed_skipped'] );
		$this->assertSame( 1, $diagnostics['accepted'] );
	}

	public function test_collector_paginates_until_fresh_candidate_is_found(): void {
		$request_count = 0;
		$this->mockRedditPages(
			array(
				array(
					'after' => 'page-2',
					'posts' => array(
						$this->redditPost( 'processed-1', 'Already imported' ),
					),
				),
				array(
					'after' => null,
					'posts' => array(
						$this->redditPost( 'fresh-1', 'Fresh post' ),
					),
				),
			),
			$request_count
		);

		$collector = new FreshCandidateCollector(
			$this->buildContext( array( 'processed-1' => true ) ),
			1
		);

		$result = ( new FetchRedditAbility() )->executeWithCollector(
			$this->fetchInput(),
			$collector
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'fresh-1', $result['items'][0]['item_id'] );
		$this->assertSame( 2, $request_count );
	}

	/**
	 * @dataProvider searchSortProvider
	 */
	public function test_global_query_uses_search_endpoint_for_supported_sort( string $sort ): void {
		$request_url = '';

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$request_url ) {
				$request_url = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'after'    => null,
								'children' => array(),
							),
						)
					),
				);
			},
			10,
			3
		);

		$result = ( new FetchRedditAbility() )->execute(
			array(
				'query'           => 'Charleston live music',
				'access_token'    => 'reddit-token',
				'sort_by'         => $sort,
				'timeframe_limit' => '90_days',
				'download_images' => false,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( '/search.json', wp_parse_url( $request_url, PHP_URL_PATH ) );

		parse_str( (string) wp_parse_url( $request_url, PHP_URL_QUERY ), $request_params );
		$this->assertSame( 'Charleston live music', $request_params['q'] );
		$this->assertSame( $sort, $request_params['sort'] );
		$this->assertSame( 'year', $request_params['t'] );
	}

	public function searchSortProvider(): array {
		return array(
			'relevance' => array( 'relevance' ),
			'hot'       => array( 'hot' ),
			'top'       => array( 'top' ),
			'new'       => array( 'new' ),
			'comments'  => array( 'comments' ),
		);
	}

	public function test_global_query_rejects_browse_only_sort(): void {
		$result = ( new FetchRedditAbility() )->execute(
			array(
				'query'        => 'Charleston live music',
				'access_token' => 'reddit-token',
				'sort_by'      => 'rising',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'Invalid sort parameter', $result->get_error_message() );
	}

	/**
	 * @param array<int,array{after:?string,posts:array<int,array<string,mixed>>}> $pages
	 */
	private function mockRedditPages( array $pages, ?int &$request_count = null ): void {
		$request_count = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $pages, &$request_count ) {
				$page_index = $request_count;
				++$request_count;

				$page = $pages[ $page_index ] ?? array(
					'after' => null,
					'posts' => array(),
				);

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'after'    => $page['after'],
								'children' => array_map(
									static function ( array $post ): array {
										return array(
											'kind' => 't3',
											'data' => $post,
										);
									},
									$page['posts']
								),
							),
						)
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * @param array<string,bool> $processed_map identifier => isItemProcessed result
	 */
	private function buildContext( array $processed_map = array() ): ExecutionContext {
		$context = $this->getMockBuilder( ExecutionContext::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'isItemProcessed', 'isItemClaimed', 'isDirect', 'isStandalone' ) )
			->getMock();

		$context->method( 'isItemProcessed' )->willReturnCallback(
			static function ( string $identifier ) use ( $processed_map ): bool {
				return $processed_map[ $identifier ] ?? false;
			}
		);
		$context->method( 'isItemClaimed' )->willReturn( false );
		$context->method( 'isDirect' )->willReturn( true );
		$context->method( 'isStandalone' )->willReturn( false );

		return $context;
	}

	private function fetchInput(): array {
		return array(
			'subreddit'        => 'WordPress',
			'access_token'     => 'reddit-token',
			'fetch_batch_size' => 100,
			'max_pages'        => 5,
			'download_images'  => false,
		);
	}

	private function redditPost( string $id, string $title ): array {
		return array(
			'id'           => $id,
			'title'        => $title,
			'selftext'     => 'Body for ' . $title,
			'created_utc'  => time(),
			'score'        => 10,
			'num_comments' => 3,
			'permalink'    => '/r/WordPress/comments/' . $id . '/',
			'subreddit'    => 'WordPress',
			'author'       => 'reddit_user',
			'is_self'      => true,
		);
	}
}
