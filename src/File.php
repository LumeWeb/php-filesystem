<?php


namespace Skynet\Filesystem;


use Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\MimeType;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use JsonException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Skynet\Db;
use Skynet\Filesystem\Types\FileData;
use Skynet\Filesystem\Types\FileItem;
use Skynet\MySky;
use Skynet\Types\File as SkynetFile;
use Skynet\Types\UploadRequestResponse;
use function Skynet\functions\encoding\encodeSkylinkBase64;
use function Skynet\functions\options\makeRequest;
use function Skynet\functions\skylinks\parseSkylink;

class File extends Node implements StreamInterface {
	use StreamDecoratorTrait;

	protected ?FileItem $meta;
	private ?StreamInterface $stream;
	private string $encryptionKey;
	private bool $streamModified = false;

	public function __construct( $path, array $options, Filesystem $filesystem, MySky $client = null ) {
		parent::__construct( $path, $options, $filesystem, $client );

		if ( Directory::INDEX_FILE === $this->getName() ) {
			throw new Exception( sprintf( 'File "%s" is a reserved filename.', Directory::INDEX_FILE ) );
		}
		if ( '' === $this->getName() ) {
			throw new Exception( 'Cannot make a file from the root path' );
		}
	}

	public function touch(): bool {
		return  $this->touchAsync()->wait();
	}

	public function touchAsync(): PromiseInterface {
		return $this->existsAsync()->then( function ( bool $exists ) {
			if ( ! $exists ) {

				return $this->openAsync()->then( function () {
					$data = '';

					if ( $this->isJson() ) {
						$data = '{}';
					}
					$this->write( $data );

					return $this->closeAsync()->then(fn() => true);
				} );
			}

			return true;
		} );
	}

	public function openAsync( ?bool $streaming = null, int $offset = 0 ): PromiseInterface {
		if ( $this->isStreamCreated() ) {
			return Create::promiseFor( false );
		}

		if ( null !== $streaming ) {
			$this->setStreaming( $streaming );
			$this->setOption( 'streamOffset', $offset );
		}

		return \GuzzleHttp\Promise\Utils::all( [
			$this->existsAsync(),
			$this->getMetaAsync(),
		] )->then( function ( $result ) {
			[ $exists, $meta ] = $result;

			if ( $exists && $meta ) {
				$offset = 0;
				if ( $this->isStreaming() ) {
					$offset = $this->getOption( 'streamOffset' ) ?? 0;
				}

				return $this->openRemoteStream( $offset )->then( fn() => true );
			}

			$this->createStream();

			return true;
		} );

	}

	protected function isStreamCreated(): bool {
		return isset( $this->stream ) && null !== $this->stream;
	}

	/**
	 * @param bool $streaming
	 */
	public function setStreaming( bool $streaming ): void {
		$this->setOption( 'streaming', $streaming );

		if ( $this->isStreamCreated() ) {
			$this->openRemoteStream()->wait();
		}
	}

	private function openRemoteStream( $offset = 0 ): PromiseInterface {
		if ( $this->isJson() ) {
			$getMethod = $this->isPrivate() ? 'getJSONEncrypted' : 'getJSON';

			/** @var \Skynet\Types\JSONResponse|\Skynet\Types\EncryptedJSONResponse $response */
			return $this->getClient()->{$getMethod}( $this->getFullPath() )->then( function ( $response ) {
				$this->createStream( json_encode( $response->getData() ) );
			} );

		}

		$promise = Create::promiseFor( null );

		if ( null === $this->getSkylinkMeta() ) {
			$promise = $promise->then( fn() => $this->fetchMetadata() );
		}

		if ( $this->isPrivate() ) {
			$promise = $promise->then( fn() => $this->createDecyptionStream() );
		}

		return $promise
			->then( function ( ?EncryptedFileStream $stream ) use ( $offset ) {
				$this->stream         = $stream;
				$this->streamModified = false;

				$headers       = [];
				$primaryOffset = null;
				if ( $offset && 0 < $offset ) {
					if ( $this->isPrivate() ) {
						$primaryOffset = $offset;
						$chunkSize     = $this->meta->getFile()->getChunkSize();

						$encChunkSize = $chunkSize + 16;
						$encBlock     = floor( $offset / $encChunkSize );
						$seenBlocks   = (int) floor( $offset / $chunkSize );

						$offset       = $encBlock * $encChunkSize;
						$streamOffset = $seenBlocks * $chunkSize;

						$this->stream->setStreamOffset( $streamOffset );
					}
					$headers['Range'] = "bytes={$offset}-";
				}

				return $this->getClient()->getSkynet()->downloadFileAsync( $this->getSkylink(), null, makeRequest( [
					'options' => [
						'stream' => true,
						'debug'  => $this->getOption( 'debug' ),
					],
					'headers' => $headers,
				] ) )->then( function ( Response $response ) use ( $primaryOffset ) {
					if ( $this->isPrivate() ) {
						$this->stream->setStream( $response->getBody() );
						if ( $primaryOffset ) {
							$this->stream->seek( $primaryOffset );
						}

						return;
					}

					$this->stream = new CachingStream( $response->getBody() );
				} );
			} );
	}

	public function isJson(): bool {
		$mime = $this->getMime();

		return ( 'application/json' === $mime );
	}

	public function getMime(): ?string {
		return MimeType::fromFilename( $this->getName() );
	}

	protected function createStream( string $data = '' ): void {
		/** @noinspection PhpFieldAssignmentTypeMismatchInspection */
		$this->stream = Utils::streamFor( $data );
	}

	private function createDecyptionStream( StreamInterface $stream = null ): PromiseInterface {
		return $this->createCyptionStream( $stream )->then( function ( EncryptedFileStream $stream ): EncryptedFileStream {
			$stream->setMode( EncryptedFileStream::MODE_DECRYPT );

			return $stream;
		} );
	}

	private function createCyptionStream( StreamInterface $stream = null ): PromiseInterface {
		if ( null === $stream && ! $this->isStreamCreated() ) {
			$this->createStream();
		}


		$chunkSize = (int) $this->getOption( 'encryptionChunkSize' );

		return $this->getMetaAsync()->then( function ( ?FileItem $meta ) use ( &$chunkSize ): PromiseInterface {
			if ( $meta ) {
				$chunkSize = $meta->getFile()->getChunkSize();
			}

			return $this->getEncryptionKey( true );
		} )->then( function ( $key ) use ( $chunkSize, $stream ): EncryptedFileStream {
			$stream = $stream ?? $this->stream;
			$stream = new EncryptedFileStream( $stream, $key, $chunkSize, 0, $this->meta ?? null );

			return $stream;
		} );


	}

	/**
	 * @return \Skynet\FileSystem\Types\FileItem|null
	 */
	public function getMetaAsync(): PromiseInterface {
		$meta = $this->meta ?? null;

		if ( null === $meta ) {
			$parent = $this->getParentDirectory();
			if ( $parent ) {
				return $parent->existsAsync()->then( function ( bool $exists ) use ( $parent ) {
					if ( $exists ) {
						return $parent->getFileMetaAsync( $this->getName() );
					}

					return null;
				} )->then( function ( $item ) {
					if ( $item ) {
						$this->meta = $item;

						return $this->meta;
					}

					return null;
				} );

			}
		}

		return Create::promiseFor( $this->meta ?? null );
	}

	private function getEncryptionKey( bool $raw = false ): PromiseInterface {
		return $this->getMetaAsync()->then( function ( $meta ) use ( $raw ) {
			if ( $meta ) {
				return Create::promiseFor( $meta->getFile()->getKey( $raw ) );
			}

			$key = $this->encryptionKey ?? null;

			if ( ! $raw ) {
				$key = str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $key ) );
			}

			if ( ! $key ) {
				return $this->generateEncryptionKey()->then( fn() => $this->getEncryptionKey( $raw ) );
			}

			return $key;
		} );


	}

	private function generateEncryptionKey(): PromiseInterface {
		if ( ! $this->isPrivate() ) {
			return Create::promiseFor( null );
		}

		$key = sodium_crypto_secretbox_keygen();

		return $this->getMetaAsync()->then( function ( ?FileItem $meta ) use ( $key ) {
			if ( $meta ) {
				$this->getMeta()->getFile()->setKey( $key, false );

				return;
			}

			$this->encryptionKey = $key;
		} );
	}

	/**
	 * @return \Skynet\FileSystem\Types\FileItem|null
	 */
	public function getMeta(): ?FileItem {
		return $this->getMetaAsync()->wait();
	}

	/**
	 * @param \Skynet\Filesystem\Types\FileItem $meta
	 */
	public function setMeta( FileItem $meta ): void {
		$this->meta = $meta;
	}

	public function seek( $offset, $whence = SEEK_SET ): bool {
		if ( ! $this->isStreamCreated() ) {
			return false;
		}

		if ( $this->isStreaming() ) {
			$this->closeStream();
			$this->openRemoteStream( $offset )->wait();

			return true;
		}

		$this->stream->seek( $offset, $whence );

		return true;
	}

	/**
	 * @return bool
	 */
	public function isStreaming(): bool {
		return $this->getOption( 'streaming' ) ?? false;
	}

	private function closeStream() {
		if ( $this->isStreamCreated() ) {
			$this->stream->close();
			$this->stream->detach();
		}

		$this->stream = null;
	}

	public function close(): void {
		$this->closeAsync()->wait();
	}

	public function closeAsync(): PromiseInterface {
		if ( ! $this->isStreamCreated() || ! $this->streamModified ) {
			$this->closeStream();

			return Create::promiseFor( null );
		}

		$this->validate();

		return $this->upload()->otherwise( function ( $e ) {
			if ( $e instanceof Exception ) {
				throw $e;
			}

			return $e;
		} )->then( function () {
			$this->closeStream();
		} );
	}

	public function validate(): void {
		if ( ! $this->isStreamCreated() ) {
			return;
		}

		$this->rewind();

		if ( $this->isJson() ) {
			try {
				json_decode( $this->getContents(), true, 512, JSON_THROW_ON_ERROR );
			} catch ( JsonException $e ) {
				throw new Exception( sprintf( 'Could not validate file contents as valid JSON: %s', $e->getMessage() ) );
			}
		}

		$this->rewind();
	}

	public function rewind(): void {
		if ( $this->isStreamCreated() ) {
			$this->stream->rewind();
		}
	}

	private function upload(): PromiseInterface {
		if ( $this->isJson() ) {
			$promise = $this->uploadJson();
		} else {
			$promise = $this->uploadRaw();
		}

		return $promise
			->then( fn() => $this->fetchMetadata() )
			->then( fn() => $this->generateFileMeta() )
			->then( fn() => $this->getParentDirectory()->addFileAsync( $this ) )
			->then( function () {
				$this->setExists( true );
				$this->getFilesystem()->resetNode( $this->getFullPath() );
				$this->streamModified = false;
			} );
	}

	private function uploadJson(): PromiseInterface {
		$data       = json_decode( $this->stream->getContents(), true, 512, JSON_THROW_ON_ERROR );
		$jsonMethod = $this->isPrivate() ? 'setJSONEncryptedAsync' : 'setJSONAsync';

		return $this->getClient()->{$jsonMethod}( $this->getFullPath(), $data )->then( function ( $response ) {
			[ 'dataLink' => $dataLink ] = $response;

			$this->setSkylink( $dataLink );
		} );
	}

	private function uploadRaw(): PromiseInterface {
		$promise = Create::promiseFor( null );

		if ( $this->isPrivate() ) {
			$promise = $this->createEncyptionStream();
		}

		return $promise->then( function ( $data ) {
			$client = $this->getClient();
			$data   = $data ?? $this->stream;

			return $client->getSkynet()->uploadFileAsync( SkynetFile::fromResource( $data, $this->getName() ) )
			              ->then( function ( UploadRequestResponse $file ) {
				              return $this->updateDataLink( $file->getSkylink() );
			              } );
		} );
	}

	private function createEncyptionStream( StreamInterface $stream = null ): PromiseInterface {
		return $this->createCyptionStream( $stream )->then( function ( EncryptedFileStream $stream ): EncryptedFileStream {
			$stream->setMode( EncryptedFileStream::MODE_ENCRYPT );

			return $stream;
		} );
	}

	private function updateDataLink( string $skylink = null ): PromiseInterface {
		$skylink        = $skylink ?? $this->getSkylink();
		$dataLinkMethod = $this->isPrivate() ? 'setEncryptedDataLinkAsync' : 'setDataLinkAsync';
		$path           = $this->getFullPath();

		return Create::promiseFor( $skylink )->then( function ( $skylink ) use ( $dataLinkMethod, $path ) {
			return $this->getClient()->{$dataLinkMethod}( $path, $skylink );
		} );
	}

	private function generateFileMeta(): PromiseInterface {
		return $this->getMetaAsync()
		            ->then( function ( $meta ) {
			            return \GuzzleHttp\Promise\Utils::all( [
				            Create::promiseFor( $meta ),
				            $this->getSkylinkAsync(),
				            $this->isPrivate() ? $this->getEncryptionKey() : Create::promiseFor( null ),
			            ] );
		            } )
		            ->then( function ( $results ) {
			            [ $meta, $skylink, $encryptionKey ] = $results;

			            $metaExists = null !== $meta;
			            $history    = [];

			            if ( $this->stream instanceof EncryptedFileStream ) {
				            $stream = $this->stream->getPlaintextStream();
			            } else {
				            $stream = $this->stream;
			            }

			            $hash    = Utils::hash( $stream, 'sha256' );
			            $hash    = '12' . dechex( strlen( $hash ) ) . $hash;
			            $created = time();
			            $version = 0;

			            if ( $metaExists ) {
				            $created = $this->meta->getCreated();
				            $version = $this->meta->getVersion() + 1;
				            $history = $this->meta->getHistory();

				            if ( $this->meta->getFile()->getHash() === $hash ) {
					            $this->meta->setModified( $created );

					            return;
				            }

				            $history [] = $this->meta->getFile();
			            }

			            $this->meta = new FileItem( [
				            'name'     => $this->getName(),
				            'created'  => $created,
				            'modified' => time(),
				            'mimeType' => $this->getMime(),
				            'version'  => $version,
				            'history'  => $history,
				            'file'     => new FileData( [
					            'url'            => $skylink,
					            'key'            => $this->isPrivate() ? $encryptionKey : '',
					            'encryptionType' => $this->isPrivate() ? 'LIBSODIUM_SECRETBOX' : '',
					            'size'           => $stream->getSize(),
					            'chunkSize'      => $this->isPrivate() ? $this->getOption( 'encryptionChunkSize' ) : 0,
					            'hash'           => $hash,
					            'ts'             => $created,
				            ] ),
			            ] );
		            } );
	}

	public function write( $data ): int {
		if ( ! $this->isStreamCreated() ) {
			return 0;
		}

		if ( $this->isStreaming() ) {
			throw new Exception( 'Cannot write data while in streaming mode' );
		}

		if ( $this->stream instanceof EncryptedFileStream && $this->stream->getMode() === EncryptedFileStream::MODE_DECRYPT ) {
			$pos = $this->stream->tell();

			$stream = Utils::streamFor();
			$this->stream->rewind();
			Utils::copyToStream( $this->stream, $stream );
			$this->closeStream();
			$this->stream = $stream;

			$this->stream->seek( $pos );
		}

		$this->streamModified = true;

		return $this->stream->write( $data );
	}

	public function end(): void {
		if ( $this->isStreamCreated() ) {
			$this->stream->seek( $this->stream->getSize() );
		}
	}

	public function read( $count ) {
		if ( ! $this->isStreamCreated() ) {
			return false;
		}

		try {
			$data = $this->stream->read( $count );
		} catch ( RuntimeException $e ) {
			return false;
		}

		return $data;
	}

	public function tell(): int {
		if ( ! $this->isStreamCreated() ) {
			return 0;
		}

		return $this->stream->tell();
	}

	public function eof(): bool {
		if ( ! $this->isStreamCreated() ) {
			return true;
		}

		return $this->stream->eof();
	}

	public function getMetadata( $key = null ) {
		$meta                 = $this->stream->getMetadata( $key );
		$meta['stream_type']  = 'skynet';
		$meta['wrapper_type'] = 'https';
		$meta['uri']          = $this->getNodeLink();

		return $meta;
	}

	public function move( string $newPath ): bool {
		return $this->rename( $newPath );
	}

	public function rename( string $newPath ): bool {
		return $this->renameAsync( $newPath )->wait();
	}

	public function renameAsync( string $newPath ): PromiseInterface {
		return \GuzzleHttp\Promise\Utils::all( [
			$this->existsAsync(),
			$this->getMetaAsync(),
		] )->then( function ( $result ) use ( $newPath ) {
			[ $exists, $meta ] = $result;

			if ( ! $exists || ! $meta ) {
				return false;
			}

			$newNode = $this->getFilesystem()->node( $newPath );

			return $newNode->isDirectoryAsync()->then( function ( $isDir ) use ( $newPath, $newNode ) {
				if ( $isDir ) {
					return false;
				}


				$this->getFilesystem()->resetObject( $this );
				$this->getFilesystem()->resetObject( $newNode );
				$this->getFilesystem()->resetFile( $newPath );

				$newFile = $this->getFilesystem()->file( $newPath );

				if ( $newFile->isJson() xor $this->isJson() ) {
					return $this->copyAsync( $newPath )->then( function ( $result ) {
						if ( $result ) {
							return $this->deleteDataLink();
						}

						return false;
					} );

				}

				$parent = $this->getParentDirectory();
				$newDir = $newFile->getPath() === $this->getPath() ? $parent : $this->getFilesystem()->directory( $newFile->getPath() );

				$promises = [];

				$promises[] = $this->deleteDataLink();
				$promises[] = $parent->deleteFileAsync( clone $this );
				$this->setPathInfo( $newPath );
				$promises[] = $this->updateDataLink();
				$promises[] = $newDir->addFileAsync( clone $this );

				$this->getFilesystem()->cacheObject( $this );

				return \GuzzleHttp\Promise\Utils::all( $promises )->otherwise( function ( $e ) {
					if ( $e instanceof \Exception ) {
						throw $e;
					}

					return false;
				} )->then( fn() => true );
			} );
		} );
	}

	public function copyAsync( $newFileorPath, bool $deep = false ): PromiseInterface {
		return \GuzzleHttp\Promise\Utils::all( [
			$this->existsAsync(),
			$this->getMetaAsync(),
		] )->then( function ( $result ) use ( $deep, $newFileorPath ) {
			[ $exists, $meta ] = $result;

			if ( ! $exists || ! $meta ) {
				return false;
			}
			$newFile = $newFileorPath instanceof self ? $newFileorPath : $this->getFilesystem()->file( $newFileorPath );
			$deep    = $deep || ( $newFile->isJson() xor $this->isJson() );

			if ( $deep ) {

				return Create::promiseFor( null )->then( function () use ( $newFile ) {
					return $newFile->open();
				} )->then( function () use ( $newFile ) {
					Utils::copyToStream( $this, $newFile );

					return $newFile->close();
				} )->then( function () {
					return true;
				} );
			}

			return $newFile->import( $this );
		} );
	}

	public function open( ?bool $streaming = null, int $offset = 0 ): bool {
		return $this->openAsync( $streaming, $offset )->wait();
	}

	public function import( $item, string $path = null ): bool {
		return $this->importAsync( $item, $path )->wait();
	}

	public function importAsync( $item, string $path = null ): PromiseInterface {
		if ( $item instanceof self ) {
			if ( $path ) {
				$this->setPathInfo( $path );
			}
			$promise = $item->getSkylinkAsync();
		} else {
			parseSkylink( $item );
			$promise = Create::promiseFor( $item );
		}

		return $promise->then( function ( string $skylink ) {
			$this->setSkylink( $skylink );

			return $this->updateDataLink();
		} )->then( function () {
			return $this->openAsync();
		} )->then( function () use ( $item ) {
			if ( $item instanceof self ) {
				$meta = clone $item->getMeta();
				$meta->setName( $this->getName() );
				$meta->getFile()->setKey( $item->getMeta()->getFile()->getKey( true ), false );
				$this->setMeta( $meta );
			}

			$promises = [];

			$parent = $this->getParentDirectory();
			if ( $parent ) {
				$promises[] = $parent->addFileAsync( $this );
			}

			$promises[] = $this->existsAsync();

			return \GuzzleHttp\Promise\Utils::all( $promises )->then( function ( $results ) {
				if ( 1 < count( $results ) ) {
					return $results[1];
				}

				return $results[0];
			} );
		} );
	}

	private function deleteDataLink(): PromiseInterface {
		return $this->updateDataLink( encodeSkylinkBase64( Db::getEmptySkylink() ) );
	}

	public function copy( $newFileorPath, bool $deep = false ): bool {
		return $this->copyAsync( $newFileorPath, $deep )->wait();
	}

	public function delete( bool $soft = false ): bool {
		return $this->deleteAsync( $soft )->wait();
	}

	public function deleteAsync( bool $soft = false ): PromiseInterface {
		$skylink = $this->getSkylink();

		if ( $this->isJson() ) {
			$deleteMethod = $this->isPrivate() ? 'deleteJSONAsync' : 'deleteJSONEncryptedAsync';
			$this->getClient()->{$deleteMethod}( $this->getFullPath() );
		} else {
			$this->deleteDataLink();
		}

		$promises = [];

		if ( $soft && null !== $skylink ) {
			$promises[] = $this->getClient()->getSkynet()->unpinSkylinkAsync( $skylink );
		}

		$promises[] = $this->getParentDirectory()->deleteFileAsync( $this );

		return \GuzzleHttp\Promise\Utils::all( $promises )->then( fn() => true );
	}

	public function readFromFile( string $filename ): void {
		$this->readFromResource( $filename );
	}

	public function readFromResource( $resource, bool $append = false ): void {
		$this->readFromResourceAsync( $resource, $append )->wait();
	}

	public function readFromResourceAsync( $resource, bool $append = false ): PromiseInterface {
		return $this->openAsync()->then( function () use ( $resource, $append ) {
			$stream = Utils::streamFor( $resource );
			if ( ! $append ) {
				$this->rewind();
			}

			Utils::copyToStream( $stream, $this );
		} );
	}

	public function readFromFileAsync( string $filename ): void {
		$this->readFromResourceAsync( $filename );
	}
}
