<?php

namespace Skynet\Filesystem;

use ArrayIterator;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Is;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 *
 */
class QueueMiddleware {
	/**
	 * @var callable(RequestInterface, array): PromiseInterface
	 */
	private $nextHandler;

	/**
	 * @var \Skynet\Filesystem\QueuePromise
	 */
	private $queue;

	private ArrayIterator $requests;

	private bool $debug;

	private int $concurrency;

	/**
	 * @param callable(RequestInterface, array): PromiseInterface $nextHandler Next handler to invoke.
	 */
	public function __construct( callable $nextHandler, int $concurrency, bool $debug ) {
		$this->nextHandler = $nextHandler;
		$this->requests    = new ArrayIterator();
		$this->debug       = $debug;
		$this->concurrency = $concurrency;
	}

	/**
	 * @param int $concurrency
	 *
	 * @return callable
	 */
	public static function init( int $concurrency, bool $debug ): callable {
		return static function ( callable $handler ) use ( $debug, $concurrency ): self {
			return new self( $handler, $concurrency, $debug );
		};
	}

	/**
	 * @param \Psr\Http\Message\RequestInterface $request
	 * @param array                              $options
	 *
	 * @return \GuzzleHttp\Promise\PromiseInterface
	 */
	public function __invoke( RequestInterface $request, array $options ): PromiseInterface {
		if ( $this->debug ) {
			$fn      = $this->nextHandler;
			$promise = $fn( $request, $options );
			$promise->wait();

			return $promise;
		}

		$this->requests->append( [ $this->nextHandler, $request, $options ] );
		$this->refreshQueue();


		return $this->queue->promise()->then( function () use ( &$request ) {
			return $this->queue->getPromiseByRequest( $request );
		} );
	}

	private function refreshQueue() {
		if ( $this->queue ) {
/*			return;
			$requests = $this->requests->getArrayCopy();

			$this->requests = new ArrayIterator( array_filter( $this->requests->getArrayCopy(), fn( $request ) => ! Is::settled( $this->queue->getPromiseByRequest( $request[1] ) ) ) );*/

			if ( ! Is::settled( $this->queue->promise() ) ) {
				return;
			}
		}
		$this->queue = new QueuePromise( $this->requests, [
			'concurrency' => $this->concurrency,
			'fulfilled'   => function ( $e ) {
				$a = $e;
			},
			'rejected'    => function ( $e ) {
				throw $e;
			},
		] );
	}
}
