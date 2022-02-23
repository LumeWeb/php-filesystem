<?php

namespace Skynet\Filesystem;

use DebugBar\StandardDebugBar;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Profiling\Debugbar\Profiler;
use GuzzleHttp\Profiling\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Utils;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Skynet\MySky;
use Skynet\Registry;

/**
 *
 */
class Filesystem {
	/**
	 *
	 */
	public const MODE_PRIVATE = 'private';
	/**
	 *
	 */
	public const MODE_PUBLIC = 'public';
	/**
	 * @var array
	 */
	private array $cache = [];
	/**
	 * @var array
	 */
	private array $options = [];
	/**
	 * @var \Skynet\MySky|null
	 */
	private MySky $client;

	private QueuedRequestHandler $requestHandler;

	/**
	 * @param array $options
	 */
	public function __construct( array $options = [], MySky $client = null ) {
		if ( null === $client ) {
			$client = new MySky( $this->getOption( 'seed' ) ?? null );
		}

		$this->client = $client;
		$this->updateOptions( $options );
		$this->setupHttpClient();
	}

	public function getOption( string $name ) {
		if ( isset( $this->options[ $name ] ) ) {
			return $this->options[ $name ];
		}

		if ( null !== ( $value = getContextOption( $name ) ) ) {
			return $value;
		}

		return null;
	}

	public function updateOptions( array $options ): void {
		foreach ( $options as $option => $value ) {
			$this->updateOption( $option, $value );
		}
	}

	private function updateOption( string $name, $value ): void {
		$method = 'sync' . ucfirst( $name );

		if ( method_exists( $this, $method ) ) {
			$this->{$method}( $value );
		}
	}

	private function setupHttpClient(): void {
		$this->requestHandler = new QueuedRequestHandler( [ 'queueSize' => $this->getOption( 'ioLimit' ) ] );
		$handler              = Proxy::wrapStreaming( $this->requestHandler, new StreamHandler() );
		$stack                = HandlerStack::create( $handler );
		/*		$log                  = new Logger( 'Logger' );
				$log->pushHandler( new ErrorLogHandler() );
				$stack->push(
					\GuzzleHttp\Middleware::log(
						$log,
						new MessageFormatter( '{url} {res_body} {code}' )
					)
				);*/
		$stack->push(
			RetryMiddleware::factory( [
				'retry_on_status'               => [ 400, 412, 429, 502, 503 ],
				'maybe_retry_function_override' => function ( RequestInterface $request, Response $response ) {
					/** @var \GuzzleHttp\Psr7\Request $request */
					if ( 'POST' === $request->getMethod() ) {
						if ( Registry::DEFAULT_SET_ENTRY_OPTIONS['endpointSetEntry'] === $request->getUri()->getPath() && 400 === $response->getStatusCode() ) {
							return false;
						}
					}

					return true;
				},
			] )
		);
		$this->client->setHttpClient( new Client(
			[
				'handler'     => $stack,
				'http_errors' => true,
				 'proxy' => 'http://localhost:8080',
				'curl'        => [
					CURLOPT_SSL_CIPHER_LIST => 'ECDHE-ECDSA-AES128-GCM-SHA256',
				],
				/* 'verify' => Utils::defaultCaBundle() */
			] ) );
	}

	public static function getDefaultOptions() {
		return [
			'mode'                => self::MODE_PRIVATE,
			'seed'                => null,
			'portal'              => [
				'url'      => null,
				'email'    => null,
				'password' => null,
			],
			'encryptionChunkSize' => 1 * 1024 * 1024,
			'ioLimit'             => 0,
			'debug'               => false,
			'streaming'           => false,
		];
	}

	/**
	 * @param                    $path
	 * @param array              $options
	 * @param \Skynet\MySky|null $client
	 *
	 * @return \Skynet\Filesystem\Node
	 */
	public function node( $path, array $options = [], MySky $client = null ): Node {
		return $this->maybeGetCachedNode( $path, Node::class, $client, $options, );
	}

	/**
	 * @param string             $path
	 * @param string             $class
	 * @param \Skynet\MySky|null $client
	 * @param array              $options
	 *
	 * @return mixed|\Skynet\Filesystem\Node
	 */
	private function maybeGetCachedNode( string $path, string $class, MySky $client = null, array $options = [] ) {
		$options = array_merge( self::getDefaultOptions(), $this->options, array_filter( $options ) );
		ksort( $options );
		$path = normalizePath( $path );

		if ( ! ( $node = $this->getCachedNode( $path, $class ) ) ) {
			return $this->createAndCacheNode( $path, $class, $client, $options );
		}

		$node->setOptions( $options );

		return $node;
	}

	/**
	 * @param string $path
	 * @param string $class
	 * @param array  $options
	 *
	 * @return mixed|null
	 */
	private function getCachedNode( string $path, string $class ) {
		$key = md5( serialize( [ $path, $class ] ) );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		return null;
	}

	/**
	 * @param string             $path
	 * @param string             $class
	 * @param \Skynet\MySky|null $client
	 * @param array              $options
	 *
	 * @return \Skynet\Filesystem\Node
	 */
	private function createAndCacheNode( string $path, string $class, ?MySky $client = null, array $options ): Node {
		$key                 = md5( serialize( [ $path, $class ] ) );
		$this->cache[ $key ] = new $class( $path, $options, $this, $client ?? $this->client );

		return $this->cache[ $key ];
	}

	/**
	 * @param string             $path
	 * @param array              $options
	 * @param \Skynet\MySky|null $client
	 *
	 * @return \Skynet\Filesystem\File
	 */
	public function file( string $path, array $options = [], MySky $client = null ): File {
		return $this->maybeGetCachedNode( $path, File::class, $client, $options );
	}

	/**
	 * @param string $path
	 * @param array  $options
	 *
	 * @return $this
	 */
	public function resetDirectory( string $path ): self {
		$this->reset( $path, Directory::class );

		return $this;
	}

	/**
	 * @param string      $path
	 * @param string|null $class
	 * @param array       $options
	 *
	 * @return $this
	 */
	public function reset( string $path, string $class = null ): self {
		$path = normalizePath( $path );

		foreach ( $class ? [ $class ] : [ Directory::class, File::class, Node::class ] as $item ) {
			$key = md5( serialize( [ $path, $item ] ) );

			if ( isset( $this->cache[ $key ] ) ) {
				unset( $this->cache[ $key ] );
			}
		}

		return $this;
	}

	/**
	 * @param \Skynet\Filesystem\Node $node
	 * @param string|null             $key
	 *
	 * @return $this
	 */
	public function resetObject( Node $node, string $key = null ): self {
		if ( null === $key ) {
			$key = md5( serialize( [ $node->getPath(), get_class( $node ) ] ) );
		}

		if ( isset( $this->cache[ $key ] ) ) {
			unset( $this->cache[ $key ] );
		}

		return $this;
	}

	public function cacheObject( Node $node ): self {
		$key                 = md5( serialize( [ $node->getPath(), get_class( $node ) ] ) );
		$this->cache[ $key ] = $key;

		return $this;
	}

	/**
	 * @param string $path
	 * @param array  $options
	 *
	 * @return $this
	 */
	public function resetFile( string $path ): self {
		$this->reset( $path, File::class );

		return $this;
	}

	/**
	 * @param string $path
	 * @param array  $options
	 *
	 * @return $this
	 */
	public function resetNode( string $path ): self {
		$this->reset( $path, Node::class );

		return $this;
	}

	/**
	 * @param string $seed
	 *
	 * @return $this
	 */
	public function setSeed( string $seed ): self {
		$this->setOption( 'seed', $seed );

		return $this;
	}

	public function setOption( string $name, $value ): self {
		$this->options[ $name ] = $value;

		$this->updateOption( $name, $value );

		return $this;
	}

	/**
	 * @return $this
	 */
	public function setPublic(): self {
		$this->setOption( 'mode', self::MODE_PUBLIC );
		$this->resetAll();

		return $this;
	}

	/**
	 * @return $this
	 */
	public function resetAll(): self {
		$this->cache = [];

		return $this;
	}

	public function setIoLimit( int $limit ) {
		$this->requestHandler->setQueueSize( $limit );
	}

	/**
	 * @return $this
	 */
	public function setPrivate(): self {
		$this->setOption( 'mode', self::MODE_PRIVATE );
		$this->resetAll();

		return $this;
	}

	/**
	 * @return array
	 */
	public function getOptions(): array {
		return $this->options ?? self::getDefaultOptions();
	}

	/**
	 * @return \Skynet\MySky
	 */
	public function getClient(): MySky {
		return $this->client;
	}

	/**
	 * @param \Skynet\MySky $client
	 */
	public function setClient( MySky $client ): void {
		$this->client = $client;
	}

	public function root(): Directory {
		return $this->directory( '' );
	}

	/**
	 * @param string             $path
	 * @param array              $options
	 * @param \Skynet\MySky|null $client
	 *
	 * @return \Skynet\Filesystem\Directory
	 */
	public function directory( string $path, array $options = [], MySky $client = null ): Directory {
		return $this->maybeGetCachedNode( $path, Directory::class, $client, $options );
	}

	public function emptyTrash( bool $force = false ): bool {
		$trash = $this->trash();

		/** @var \Skynet\Filesystem\Directory|\Skynet\Filesystem\File $child */
		foreach ( $trash->getItems() as $child ) {
			$child->delete( $force );
		}

		return true;
	}

	public function trash(): Directory {
		$trash = $this->directory( Directory::TRASH_FOLDER );
		$trash->create();

		return $trash;
	}

	public function dir( string $path ): Directory {
		return $this->directory( $path );
	}

	public function enableProfiling() {
		$debugBar = new StandardDebugBar();
// Get data collector.
		$timeline         = $debugBar->getCollector( 'time' );
		$debugbarRenderer = $debugBar->getJavascriptRenderer()
		                             ->setBaseUrl( '/apps/skynet/vendor/maximebf/debugbar/src/DebugBar/Resources' )
		                             ->setEnableJqueryNoConflict( false );
// Wrap the timeline.
		$profiler = new Profiler( $timeline );

// Add the middleware to the stack
		$stack = HandlerStack::create();
		$stack->unshift( new Middleware( $profiler ) );

		$this->client->setHttpClient( new Client( [ 'stack' => $stack,/* 'proxy' => 'localhost:8083'*/ ] ) );
	}

	/**
	 * @param $options
	 *
	 * @return void
	 * @throws \Exception
	 */
	private function syncPortal( $options ): void {
		if ( ! empty( $options['url'] ) ) {
			$this->client->setPortal( $options['url'] );
		}

		if ( ! empty( $options['email'] ) && ! empty( $options['password'] ) ) {
			$this->client->setPortalLogin( $options['email'], $options['password'] );
		}
	}

	/**
	 * @param $seed
	 *
	 * @return void
	 * @throws \Exception
	 */
	private function syncSeed( $seed ): void {
		$this->client->setSeed( $seed );

		$this->resetAll();
	}
}
