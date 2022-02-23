<?php


namespace Skynet\Filesystem;


use Exception;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Skynet\Db;
use Skynet\FileSystem\Types\DirectoryItem;
use Skynet\Filesystem\Types\SkylinkMeta;
use Skynet\Filesystem\Types\SkylinkMetaSubfile;
use Skynet\MySky;
use Skynet\Types\GetMetadataResponse;
use function Skynet\functions\encoding\encodeSkylinkBase64;
use function Skynet\functions\encrypted_files\deriveEncryptedFileTweak;
use function Skynet\functions\formatting\formatSkylink;
use function Skynet\functions\options\makeGetEntryOptions;
use function Skynet\functions\registry\getEntryLink;
use function Skynet\functions\strings\trimForwardSlash;
use function Skynet\functions\tweak\deriveDiscoverableFileTweak;
use function Skynet\functions\url\trailingslashit;

/**
 *
 */
class Node {
	/**
	 * @var array
	 */
	private array $options;
	/**
	 * @var string
	 */
	private string $path;
	/**
	 * @var string
	 */
	private string $name;
	/**
	 * @var \Skynet\MySky|null
	 */
	private MySky $client;
	/**
	 * @var string
	 */
	private string $skylink;
	/**
	 * @var string
	 */
	private string $nodeLink;
	/**
	 * @var \Skynet\Filesystem\Node|null
	 */
	private ?Node $parent;
	/**
	 * @var bool|null
	 */
	private ?bool $exists = null;
	/**
	 * @var \Skynet\Filesystem\Types\SkylinkMeta|null
	 */
	private ?SkylinkMeta $metaData;
	/**
	 * @var \Skynet\Filesystem\Filesystem
	 */
	private Filesystem $filesystem;

	/**
	 * @param array $options
	 *
	 * @throws \Exception
	 */
	public function __construct( $path, array $options, Filesystem $filesystem, MySky $client = null ) {
		$this->filesystem = $filesystem;

		if ( null === $client ) {
			$client = new MySky( $options['seed'] ?? null );
			if ( ! empty( $options['portal'] ) ) {
				if ( ! empty( $options['portal']['url'] ) ) {
					$client->setPortal( $options['portal']['url'] );
				}

				if ( ! empty( $options['portal']['email'] ) && ! empty( $options['portal']['password'] ) ) {
					$client->setPortalLogin( $options['portal']['email'], $options['portal']['password'] );
				}
			}
		}

		$this->client = $client;

		$this->setPathInfo( $path );
		$this->setOptions( $options );
	}

	public function setPathInfo( $path ): void {
		$path = normalizePath( $path );
		$path = $this->resolvePath( $path );

		$this->path = dirname( $path );
		if ( '.' === $this->path ) {
			$this->path = '';
		}
		$this->setName( basename( $path ) );
	}

	private function resolvePath( $path ): string {
		$path      = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $path );
		$parts     = array_filter( explode( DIRECTORY_SEPARATOR, $path ), 'strlen' );
		$absolutes = array();
		foreach ( $parts as $part ) {
			if ( '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $absolutes );
			} else {
				$absolutes[] = $part;
			}
		}

		return implode( DIRECTORY_SEPARATOR, $absolutes );
	}

	/**
	 * @return string
	 */
	public function isPublic(): string {
		return Filesystem::MODE_PUBLIC === $this->options['mode'];
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function isFile(): bool {
		return $this->isFileAsync()->wait();
	}

	/**
	 * @return \GuzzleHttp\Promise\PromiseInterface
	 * @throws \Exception
	 */
	public function isFileAsync(): PromiseInterface {
		if ( $this instanceof File ) {
			return $this->existsAsync();
		}

		$parent = $this->getParentDirectory();

		if ( ! $parent ) {
			return Create::promiseFor( false );
		}

		return $parent->existsAsync()->then( function () use ( $parent ) {
			if ( ! $parent->getExists() ) {
				return false;
			}

			if ( ! ( $parent->containsFile( $this->name ) ) ) {
				return false;
			}

			return $this->existsAsync();
		} );
	}

	/**
	 * @param $lazy
	 * @param $force
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function existsAsync( $force = false ): PromiseInterface {
		if ( ! $force ) {
			if ( isset( $this->metaData ) && null !== $this->metaData ) {
				$this->setExists( true );
			}

			if ( null !== $this->getExists() ) {
				return Create::promiseFor( $this->getExists() );
			}

		}

		return $this->fetchMetadata()->then( function () use ( $force ) {
			$nodeExists = false;
			if ( isset( $this->metaData ) && null !== $this->metaData ) {
				$nodeExists = true;
			}

			$parent = $this->getParentDirectory();
			if ( $parent ) {
				return $parent->existsAsync( $force )->then( function () use ( $nodeExists, $parent ) {
					$this->setExists( $nodeExists && $parent->getExists() && $parent->containsNode( $this->getName() ) );

					return $this->getExists();
				} );
			}

			$this->setExists( $nodeExists );

			return $this->getExists();
		} )->otherwise( function () {
			$this->setExists( false );

			return false;
		} );
	}

	/**
	 * @return bool|null
	 */
	public function getExists(): ?bool {
		return $this->exists;
	}

	/**
	 * @param bool|null $exists
	 */
	public function setExists( ?bool $exists ): void {
		$this->exists = $exists;
	}

	/**
	 * @return void
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \JsonException
	 * @throws \Requests_Exception
	 * @throws \SodiumException
	 */
	protected function fetchMetadata(): PromiseInterface {
		if ( $this instanceof Directory ) {
			throw new Exception( 'Metadata lookup not supported on directories' );
		}


		$skylinkMethod = $this->isPrivate() ? 'resolveSkylinkFromEncryptedEntryLinkAsync' : 'resolveSkylinkFromEntryLinkAsync';

		return $this->getClient()->{$skylinkMethod}( $this->getFullPath() )->then( function ( $skylink ) {
			if ( $skylink ) {
				$this->setSkylink( $skylink );
			}

			if ( null !== ( $this->skylink ?? null ) && stripSkylinkPrefix( $this->skylink ) !== encodeSkylinkBase64( Db::getEmptySkylink() ) ) {
				return $this->getClient()->getSkynet()->getMetadataAsync( $this->getSkylink() )->then( function ( GetMetadataResponse $response ) {
					[ 'metadata' => $metadata ] = $response;

					if ( null !== $metadata ) {
						$subfiles = [];

						foreach ( $metadata->subfiles as $name => $subfile ) {
							$subfiles[ $name ] = new SkylinkMetaSubfile( $subfile );
						}
						$metadata->subfiles = $subfiles;
						$this->metaData     = new SkylinkMeta( $metadata );
					}
				} );
			}

			return Create::promiseFor( null );
		} );
	}

	/**
	 * @return string
	 */
	public function isPrivate(): string {
		return Filesystem::MODE_PRIVATE === $this->options['mode'];
	}

	/**
	 * @return \Skynet\MySky
	 */
	public function getClient(): MySky {
		return $this->client;
	}

	/**
	 * @return string
	 */
	public function getFullPath(): string {
		return trimForwardSlash( trailingslashit( $this->path ) . $this->name );
	}

	/**
	 * @return string
	 */
	public function getSkylink(): ?string {
		return $this->getSkylinkAsync()->wait();
	}

	/**
	 * @param string $skylink
	 */
	public function setSkylink( string $skylink ): void {
		$this->skylink = formatSkylink( $skylink );
	}

	/**
	 * @return string
	 */
	public function getSkylinkAsync(): PromiseInterface {
		$skylink = $this->skylink ?? null;
		$promise = Create::promiseFor( null );

		if ( null === $skylink && $this instanceof File ) {
			$promise = $promise->then( fn() => $this->fetchMetadata() );
		}

		return $promise->then( fn() => $this->skylink ?? null );

	}

	/**
	 * @return false|\Skynet\Filesystem\Directory|\Skynet\Filesystem\Node
	 * @throws \Exception
	 */
	public function getParentDirectory() {
		$parent = $this->path;
		if ( '' === $this->path ) {
			if ( '' === $this->name ) {
				return false;
			}

			$parent = '/';
		}

		$this->setParent( filesystem()->directory( $parent, $this->options, $this->client ) );

		return $this->getParent();
	}

	/**
	 * @return \Skynet\Filesystem\Node|null
	 */
	public function getParent(): ?Node {
		return $this->parent ?? null;
	}

	/**
	 * @param \Skynet\Filesystem\Node $parent
	 */
	public function setParent( Node $parent ): void {
		$this->parent = $parent;
	}

	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @param string $name
	 */
	public function setName( string $name ): void {
		$this->name = $name;
		if ( $this instanceof File && ( $this->meta ?? null ) ) {
			$this->getMeta()->setName( $name );
		}
	}

	public function exists( $force = false ): bool {
		return $this->existsAsync( $force )->wait();
	}

	/**
	 * @return string
	 */
	public function getPath(): string {
		return $this->path;
	}

	/**
	 * @return array|bool
	 * @throws \Exception
	 */
	public function stat() {
		return $this->statAsync()->wait();
	}

	/**
	 * @return array|bool
	 * @throws \Exception
	 */
	public function statAsync(): PromiseInterface {
		$parent  = $this->getParentDirectory();
		$promise = Create::promiseFor( null );
		if ( $this->isRoot() ) {
			$root    = $this->getFilesystem()->root();
			$promise = $root->createAsync();
		}

		return $promise->then( fn() => $this->existsAsync() )->then( function () {
			$created  = 0;
			$modified = 0;

			$size = 0;

			$promise = new FulfilledPromise( null );

			if ( $this instanceof File ) {
				$promise = $this->getMetaAsync()->then( function ( $_meta ) use ( &$created, &$modified, &$meta, &$size ) {
					/** @var \Skynet\Filesystem\Types\FileItem $meta */
					if ( $_meta ) {
						$meta     = $_meta;
						$created  = $meta->getCreated();
						$modified = $meta->getModified();


						$size = $meta->getFile()->getSize();
					}
				} );
			}
			if ( $this instanceof Directory ) {
				$parent = $this->getParentDirectory();
				if ( $parent ) {
					$promise = $parent->getSubdirectoryMetaAsync( $this->getName() )->then( function ( ?DirectoryItem $item ) use ( &$created, &$modified ) {
						if ( $item ) {
							$created  = $item->getCreated();
							$modified = time();
						}
					} );
				}
			}

			return $promise->then( fn() => $this->isDirectoryAsync() )->then( function ( $isDir ) use ( &$created, &$modified, &$size ) {
				if ( $isDir && $this->isRoot() ) {
					$created = $modified = time();
				}
				$info = [
					'dev'     => 0,
					'ino'     => 0,
					'mode'    => $isDir ? 0040000 : 0100000,
					'nlink'   => 0,
					'uid'     => 0,
					'gid'     => 0,
					'rdev'    => 0,
					'size'    => $size,
					'atime'   => 0,
					'mtime'   => $modified,
					'ctime'   => $created,
					'blksize' => - 1,
					'blocks'  => - 1,
				];

				return array_merge( array_values( $info ), $info );
			} );
		} );
	}

	public function isRoot(): bool {
		$parent = $this->getParentDirectory();

		return ! $parent && '' === $this->getFullPath();
	}

	/**
	 * @return \Skynet\Filesystem\Filesystem
	 */
	public function getFilesystem(): Filesystem {
		return $this->filesystem;
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function isDirectoryAsync(): PromiseInterface {
		if ( $this instanceof Directory ) {
			return $this->existsAsync();
		}

		$parent = $this->getParentDirectory();
		if ( $this->isRoot() ) {
			$root = $this->getFilesystem()->root();

			return $root->existsAsync();
		}

		return $this->existsAsync()->then( function () use ( $parent ) {
			if ( $parent ) {
				if ( ! $parent->getExists() || ! $parent->containsDirectory( $this->name ) ) {
					return false;
				}
			}

			$dir = $this->getFilesystem()->directory( $this->getFullPath() );

			return $dir->existsAsync()->then( function () use ( $dir ) {
				return $dir->getExists();
			} );
		} );

	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function isDirectory(): bool {
		return $this->isDirectoryAsync()->wait();
	}

	/**
	 * @return array
	 */
	public function getOptions(): array {
		return $this->options;
	}

	/**
	 * @param array $options
	 */
	public function setOptions( array $options ): void {
		$this->options = $options;

		if ( $this instanceof File && isset( $options['streaming'] ) ) {
			$this->setStreaming( $options['streaming'] );
		}
	}

	public function setOption( string $name, $value ): void {
		$this->options[ $name ] = $value;
	}

	/**
	 * @param string $name
	 *
	 * @return mixed|null
	 */
	public function getOption( string $name ) {
		if ( isset( $this->options[ $name ] ) ) {
			return $this->options[ $name ];
		}

		return null;
	}

	/**
	 * @return string
	 */
	public function updateNodeLink(): string {
		$this->nodeLink = null;

		return $this->getNodeLink();
	}

	/**
	 * @return string
	 */
	public function getNodeLink(): string {
		if ( ! isset( $this->nodeLink ) || null === $this->nodeLink ) {
			$path = $this->getFullPath();
			if ( $this->isPrivate() ) {
				$pathSeed       = $this->getClient()->getEncryptedFileSeed( $path, false );
				$this->nodeLink = deriveEncryptedFileTweak( $pathSeed );
			} else {
				$this->nodeLink = deriveDiscoverableFileTweak( $path );
			}

			$this->nodeLink = getEntryLink( $this->getClient()->getUserId(), $this->nodeLink, makeGetEntryOptions( [ 'hashedDataKeyHex' => true ] ) );

		}

		return $this->nodeLink;
	}

	/**
	 * @return \Skynet\Filesystem\Types\SkylinkMeta|null
	 */
	public function getSkylinkMeta(): ?SkylinkMeta {
		return $this->metaData ?? null;
	}

	public function unTrash(): bool {
		return $this->unTrashAsync()->wait();
	}

	public function unTrashAsync(): PromiseInterface {
		return $this->trashOrUnTrash( false );
	}

	private function trashOrUnTrash( bool $trash = true ): PromiseInterface {

		return $this->existsAsync()->then( function ( $exists ) {
			if ( ! $exists ) {
				return false;
			}

			return $this->isTrashedAsync();
		} )->then( function ( bool $trashed ) use ( $trash ) {
			if ( ! ( $trash xor $trashed ) ) {
				return false;
			}

			if ( $trash ) {
				$newPath = trailingslashit( Directory::TRASH_FOLDER ) . $this->getFullPath();
			} else {
				$newPath = normalizePath( str_replace( Directory::TRASH_FOLDER, '', $this->getFullPath() ) );
			}

			if ( $this instanceof Directory ) {
				$error = null;
				if ( Directory::TRASH_FOLDER === $this->getFullPath() ) {
					$error = 'trash';
				}
				if ( $this->isRoot() ) {
					$error = 'root';
				}
				if ( $error ) {
					throw new Exception( sprintf( 'Cannot trash the %s folder, it is protected', $error ) );
				}

				$parent = $this->getParentDirectory();
				if ( ! $parent && '' === $this->getFullPath() ) {
					$method = $trash ? 'trash' : 'unTrash';

					/** @var \Skynet\Filesystem\Node $child */

					return $this->getItemsAsync()->then( function ( array $items ) use ( $method ) {
						$promises = [];
						foreach ( $items as $child ) {
							$path = $child->getFullPath();

							if ( Directory::TRASH_FOLDER === $path ) {
								continue;
							}
							$promises [] = $child->{$method . 'Async'}();
						}

						return Utils::all( $promises )->then( function () {
							return true;
						} )->otherwise( fn() => false );
					} );

				}
			}

			return $this->renameAsync( $newPath );
		} );
	}

	public function isTrashedAsync(): PromiseInterface {
		return $this->existsAsync()->then( function ( $exists ) {
			if ( ! $exists ) {
				return false;
			}
			$levels = [];

			$parent = $this;
			do {
				$parent = $parent->getParentDirectory();
				if ( $parent ) {
					$levels[] = $parent;
				}
			} while ( $parent );

			/** @var \Skynet\Filesystem\Directory[] $levels */
			$levels = array_reverse( $levels );

			return isset( $levels[1] ) && Directory::TRASH_FOLDER === $levels[1]->getName() && 2 < count( $levels );
		} );
	}

	public function isTrashed(): bool {
		return $this->isTrashedAsync()->wait();
	}

	public function trash(): bool {
		return $this->trashAsync()->wait();
	}

	public function trashAsync(): PromiseInterface {
		return $this->trashOrUnTrash();
	}
}
