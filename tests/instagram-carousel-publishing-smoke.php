<?php
/**
 * Instagram CLI carousel and JPEG validation regression coverage (#299).
 *
 * Run with: php tests/instagram-carousel-publishing-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = array() ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	class WP_CLI {
		public static function error( string $message ): void {
			throw new \RuntimeException( $message );
		}
		public static function log( string $message ): void {}
		public static function success( string $message ): void {}
	}

	function wp_get_ability( string $name ): object {
		if ( 'datamachine/instagram-publish' !== $name ) {
			throw new \RuntimeException( 'Unexpected ability: ' . $name );
		}
		return new class {
			public function execute( array $input ): array {
				$GLOBALS['instagram_publish_input'] = $input;
				return array( 'success' => true );
			}
		};
	}
}

namespace DataMachineSocials\Abilities {
	abstract class AbstractSocialAbility {}
}

namespace DataMachine\Abilities {
	class AuthAbilities {
		public function getProvider( string $platform ): mixed {
			++$GLOBALS['instagram_auth_calls'];
			return null;
		}
	}
}

namespace DataMachine\Core {
	class HttpClient {
		public static function get( ...$args ): array {
			throw new \RuntimeException( 'Unexpected HTTP GET' );
		}
		public static function post( ...$args ): array {
			throw new \RuntimeException( 'Unexpected HTTP POST' );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/InstagramCommand.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Instagram/InstagramPublishAbility.php';

	function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	// Guard the synopsis too: WP-CLI discards earlier flags without the ellipsis.
	$method = new \ReflectionMethod( \DataMachineSocials\Cli\Commands\InstagramCommand::class, 'publish' );
	check( str_contains( $method->getDocComment(), '[--image=<url>...]' ), 'Image synopsis must be repeatable.' );

	// Audit every platform publish docblock without loading its dependencies.
	foreach ( glob( dirname( __DIR__ ) . '/inc/Cli/Commands/*Command.php' ) as $file ) {
		$doc = '';
		$is_function = false;
		foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_DOC_COMMENT === $token[0] ) {
				$doc = $token[1];
			}
			if ( T_FUNCTION === $token[0] ) {
				$is_function = true;
			} elseif ( $is_function && T_STRING === $token[0] ) {
				$is_function = false;
				if ( ! str_starts_with( $token[1], 'publish' ) ) {
					continue;
				}
				preg_match_all( '/\[--(?:image|video|media)[\w-]*=<[^>]+>([^\]]*)\]\s*\*\s*:\s*([^\n]+)/', $doc, $flags, PREG_SET_ORDER );
				foreach ( $flags as $flag ) {
					if ( preg_match( '/repeat|multiple times/i', $flag[2] ) ) {
						check( '...' === $flag[1], basename( $file ) . '::' . $token[1] . ' repeatable media flag needs an ellipsis.' );
					}
				}
			}
		}
	}

	$command = new \DataMachineSocials\Cli\Commands\InstagramCommand();
	$urls = array( 'https://example.test/1.jpg', 'https://example.test/2.jpeg', 'https://example.test/3.jpg', 'https://example.test/4.jpg' );
	foreach ( array( array_slice( $urls, 0, 2 ), $urls ) as $images ) {
		// Repeatable assoc arguments arrive from WP-CLI as an ordered array.
		$command->publish( array( 'Carousel' ), array( 'image' => $images ) );
		check( $images === $GLOBALS['instagram_publish_input']['image_urls'], 'All repeated images must reach the ability in order.' );
	}
	$command->publish( array( 'Single' ), array( 'image' => $urls[0] ) );
	check( array( $urls[0] ) === $GLOBALS['instagram_publish_input']['image_urls'], 'Single image must still normalize to an array.' );

	$GLOBALS['instagram_auth_calls'] = 0;
	foreach ( array( 'photo.webp', 'photo.PNG', 'photo.gif', 'photo', 'photo.webp?name=photo.jpg' ) as $file ) {
		$result = \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility::execute_publish(
			array( 'content' => 'Carousel', 'image_urls' => array( $urls[0], 'https://example.test/' . $file ) )
		);
		check( is_wp_error( $result ) && 'unsupported_image_format' === $result->get_error_code(), 'Non-JPEG image must return a format error.' );
		check( str_contains( $result->get_error_message(), 'JPEG (.jpg or .jpeg)' ), 'Error must explain supported formats.' );
		check( str_contains( $result->get_error_message(), $file ), 'Error must identify the rejected URL.' );
	}
	check( 0 === $GLOBALS['instagram_auth_calls'], 'Reject every invalid image before auth/token refresh or publishing calls.' );

	foreach ( array( array( 'not-a-url.jpg' ), array_fill( 0, 11, $urls[0] ) ) as $images ) {
		$result = \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility::execute_publish(
			array( 'content' => 'Invalid carousel', 'image_urls' => $images )
		);
		check( is_wp_error( $result ) && 'missing_param' === $result->get_error_code(), 'Malformed URLs and oversized carousels must still be rejected.' );
	}
	check( 0 === $GLOBALS['instagram_auth_calls'], 'URL and carousel size validation must precede auth.' );

	foreach ( array( 'photo.jpg', 'photo.JPEG?token=abc#fragment', 'photo.JPG?format=webp' ) as $file ) {
		$result = \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility::execute_publish(
			array( 'content' => 'JPEG', 'image_urls' => array( 'https://example.test/' . $file ) )
		);
		check( is_wp_error( $result ) && 'missing_auth' === $result->get_error_code(), 'JPEG URL path must pass format validation, regardless of case or query.' );
	}
	check( 3 === $GLOBALS['instagram_auth_calls'], 'Valid JPEG URLs must reach auth.' );

	echo "Instagram carousel CLI and JPEG validation assertions passed.\n";
}
