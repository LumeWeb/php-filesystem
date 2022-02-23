<?php


namespace Skynet\Filesystem;


use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use Skynet\Filesystem\Types\DirectoryIndex;
use Skynet\Filesystem\Types\DirectoryItem;
use Skynet\Filesystem\Types\FileData;
use Skynet\Filesystem\Types\FileItem;
use Skynet\MySky;
use stdClass;
use function Skynet\functions\misc\entityToArray;
use function Skynet\functions\strings\trimForwardSlash;
use function Skynet\functions\url\trailingslashit;

class Directory extends Node {

	public const INDEX_FILE = '.index.json';
	public const TRASH_FOLDER = '.trash';

	private ?DirectoryIndex $index;

//	private bool $writeable = true;

	public function __construct( $path, array $options, Filesystem $filesystem, MySky $client = null ) {
		parent::__construct( $path, $options, $filesystem, $client );
		$this->initIndexData();
	}

	private function initIndexData() {
		$this->index = new DirectoryIndex( [
			'directories' => [],
			'files'       => [],
		] );
	}

	public function containsNode( string $name ) {
		return $this->containsDirectory( $name ) || $this->containsFile( $name );
	}

	public function containsDirectory( string $name ): bool {
		return isset( $this->index->getDirectories()[ $name ] );
	}

	public function containsFile( string $name ) {
		return isset( $this->index->getFiles()[ $name ] );
	}

	public function getFileMeta( $name ): ?FileItem {
		return $this->getFileMetaAsync( $name )->wait();
	}

	public function getFileMetaAsync( $name ): PromiseInterface {
		return $this->existsAsync()->then( function () use ( $name ) {
			if ( ! $this->getExists() ) {
				return null;
			}

			$files = $this->index->getFiles();

			return $files[ $name ] ?? null;
		} );
	}

	public function existsAsync( $force = false ): PromiseInterface {
		if ( null !== $this->getExists() && ! $force ) {
			return Create::promiseFor( $this->getExists() );
		}

		$parent   = $this->getParentDirectory();
		$promises = [];
		if ( $parent ) {
			$promises[] = $parent->existsAsync( $force );
		}

		$promises [] = $this->fetchIndex();

		return Utils::all( $promises )->then( function () use ( $parent ) {
			$set     = isset( $this->index ) && null !== $this->index;
			$pexists = $parent ? ( $parent->getExists() && $parent->containsDirectory( $this->getName() ) ) : true;
			$this->setExists( $set && $pexists );

			return $this->getExists();
		} )->otherwise( function ($e) {
			if ( $e instanceof \Exception ) {
				throw $e;
			}

			$this->setExists( false );
			return $this->getExists();
		} );
	}

	/**
	 * @return void
	 * @throws \Exception
	 */
	private function fetchIndex(): PromiseInterface {
		$getMethod = $this->isPrivate() ? 'getJSONEncryptedAsync' : 'getJSONAsync';

		return $this->getClient()->{$getMethod}( $this->getIndexPath() )->then( function ( $response ) {
			$this->syncIndex( $response->getData() );
		} );
	}

	private function getIndexPath(): string {
		return trimForwardSlash( sprintf( '%s/%s', $this->getFullPath(), self::INDEX_FILE ) );
	}

	private function syncIndex( ?stdClass $index ): void {
		if ( null === $index ) {
			$this->index = $index;
			$this->setExists( false );

			return;
		}
		$files       = [];
		$directories = [];

		foreach ( $index->directories as $di => $directory ) {
			$directories[ $di ] = new DirectoryItem( [ 'name' => $directory->name, 'created' => $directory->created ] );
		}

		foreach ( $index->files as $fi => $file ) {
			$fileData = new FileData( $file->file );
			$history  = [];

			foreach ( $file->history as $historyItem ) {
				$history [] = new FileData( $historyItem );
			}

			$file->file    = $fileData;
			$file->history = $history;

			$files[ $fi ] = new FileItem( $file );
		}

		$this->index = new DirectoryIndex( [ 'directories' => $directories, 'files' => $files ] );
		$this->setExists( true );
	}

	public function getSubdirectoryMeta( $name ): ?DirectoryItem {
		return $this->getSubdirectoryMetaAsync()->wait();
	}

	public function getSubdirectoryMetaAsync( $name ): PromiseInterface {
		return $this->existsAsync()->then( function () use ( $name ): ?DirectoryItem {
			if ( ! $this->getExists() ) {
				return null;
			}

			$dirs = $this->index->getDirectories();

			return $dirs[ $name ] ?? null;
		} );
	}

	public function exists( $force = false ): bool {
		$this->existsAsync( $force )->wait();

		return $this->getExists();
	}

	public function deleteFile( File $file ): void {
		$this->deleteFileAsync( $file )->wait();
	}

	public function deleteFileAsync( File $file ): PromiseInterface {
		$name = $file->getName();

		return $this->existsAsync()->then( function () use ( $name ) {
			if ( ! $this->getExists() ) {
				$this->initIndexData();
			}


			$files = $this->index->getFiles();
			if ( isset( $files[ $name ] ) ) {
				unset( $files[ $name ] );
				$this->index->setFiles( $files );

				return $this->writeIndex();
			}

			return null;
		} );
	}

	private function writeIndex(): PromiseInterface {
		$index  = entityToArray( $this->index );
		$method = $this->isPrivate() ? 'setJSONEncryptedAsync' : 'setJSONAsync';

		return $this->getClient()->{$method}( $this->getIndexPath(), $index )->otherwise( function ( $e ) {
			if ( $e instanceof \Exception ) {
				throw $e;
			}

			return false;
		} )->then( function ( $response ) {
			$this->syncIndex( (object) $response->getData() );
		} );
	}

	public function addFile( File $file ): bool {
		return $this->addFileAsync( $file )->wait();
	}

	public function addFileAsync( File $file ): PromiseInterface {
		return $this->existsAsync()->then( function () use ( $file ) {
			if ( ! $this->getExists() ) {
				$this->initIndexData();
			}

			return $file->getMetaAsync();
		} )->then( function ( FileItem $meta ) {
			$files                     = $this->index->getFiles();
			$files[ $meta->getName() ] = $meta;

			$this->index->setFiles( $files );
			$indexUpdate = $this->writeIndex();

			$promise = Create::promiseFor( true );

			if ( ( $parent = $this->getParentDirectory() ) ) {
				$promise = $parent->addDirectoryAsync( $this );
			}

			return Utils::all( [ $indexUpdate, $promise ] )->otherwise( function ( $e ) {
				if ( $e instanceof \Exception ) {
					throw $e;
				}

				return false;
			} )->then( fn() => true );
		} );


	}

	public function addDirectoryAsync( Directory $directory ): PromiseInterface {
		return $this->existsAsync()->then( function () use ( $directory ) {
			if ( ! $this->getExists() ) {
				$this->initIndexData();
			}

			$this->createParent();

			if ( $this->containsDirectory( $directory->getName() ) ) {
				return false;
			}

			$dirs                          = $this->index->getDirectories();
			$dirs[ $directory->getName() ] = $this->buildDirectoryItem( $directory );


			$this->index->setDirectories( $dirs );

			return $this->writeIndex()->then( fn() => true );
		} );
	}

	private function createParent(): PromiseInterface {
		$parent = $this->getParentDirectory();

		if ( $parent ) {
			return $parent->addDirectoryAsync( $this );
		}

		return Create::promiseFor( null );
	}

	private function buildDirectoryItem( Directory $directory ): DirectoryItem {
		$meta = [
			'name'    => $directory->getName(),
			'created' => time(),
		];

		return new DirectoryItem( $meta );
	}

	public function addDirectory( Directory $directory ): bool {
		return $this->addDirectoryAsync( $directory )->wait();
	}

	public function copy( $folderPathOrDirectory ): bool {
		return $this->copyAsync( $folderPathOrDirectory, 'copy' )->wait();
	}

	public function copyAsync( $folderPathOrDirectory ): PromiseInterface {
		return $this->copyOrRename( $folderPathOrDirectory, 'copy' );
	}

	private function copyOrRename( $folderPathOrDirectory, string $action ): PromiseInterface {
		return $this->existsAsync()->then( function () use ( $folderPathOrDirectory, $action ) {
			if ( ! $this->getExists() ) {
				return $this->getExists();
			}

			if ( 'copy' !== $action ) {
				$this->getFilesystem()->resetObject( $this );
				$this->getFilesystem()->resetDirectory( $folderPathOrDirectory );
			}

			$newDir = $folderPathOrDirectory instanceof self ? $folderPathOrDirectory : $this->getFilesystem()->directory( $folderPathOrDirectory );

			$newNode = $this->getFilesystem()->node( $newDir->getFullPath() );
			if ( ( $newNode->isDirectory() && 0 < count( $newDir->getItems() ) ) || $newNode->isFile() ) {
				return false;
			}

			if ( 'copy' !== $action ) {
				$this->getFilesystem()->resetObject( $newNode );
			}

			$created = $newDir->createAsync();

			return $created->then( function ( $created ) use ( $newDir, $action ) {
				if ( ! $created ) {
					return false;
				}

				$promises = [];


				/** @var \Skynet\Filesystem\Directory $directory */
				foreach ( $this->getDirectories() as $directory ) {
					$promises [] = $directory->{$action . 'Async'}( trailingslashit( $newDir->getFullPath() ) . $directory->getName() );
				}
				/** @var \Skynet\Filesystem\File $file */
				foreach ( $this->getFiles() as $file ) {
					$promises [] = $file->{$action . 'Async'}( trailingslashit( $newDir->getFullPath() ) . $file->getName() );
				}

				return ( 'copy' === $action ) ? Utils::all( $promises )->otherwise( function ( $e ) {
					if ( $e instanceof \Exception ) {
						throw $e;
					}

					return false;
				} ) : Utils::all( array_merge( $promises, [ $this->deleteAsync() ] ) )->otherwise( function ( $e ) {
					if ( $e instanceof \Exception ) {
						throw $e;
					}

					return false;
				} );
			} );
		} );
	}

	public function getItems(): array {
		return $this->getItemsAsync()->wait();
	}

	public function getItemsAsync(): PromiseInterface {
		return Utils::all( [ $this->getDirectoriesAsync(), $this->getFilesAsync() ] )->then( function ( $promises ) {
			$children = array_merge( ...$promises );
			ksort( $children, SORT_NATURAL );

			return $children;
		} )->otherwise( function ( $e ) {
			if ( $e instanceof \Exception ) {
				throw $e;
			}

			return false;
		} );

	}

	public function getDirectoriesAsync(): PromiseInterface {
		return $this->existsAsync()->then( function () {
			$directories = [];

			if ( ! $this->getExists() ) {
				return $directories;
			}
			/** @var \Skynet\Filesystem\Types\DirectoryItem $file */
			foreach ( $this->index->getDirectories() as $directory ) {
				$path            = trailingslashit( $this->getFullPath() ) . $directory->getName();
				$directoryObject = $this->getFilesystem()->directory( $path, $this->getOptions() );

				if ( Directory::TRASH_FOLDER === $directoryObject->getFullPath() ) {
					continue;
				}

				$directories[ $directory->getName() ] = $directoryObject;
			}

			ksort( $directories, SORT_NATURAL );

			return $directories;
		} );
	}

	public function getFilesAsync(): PromiseInterface {
		return $this->existsAsync()->then( function () {
			$files = [];

			if ( ! $this->getExists() ) {
				return $files;
			}


			/** @var \Skynet\Filesystem\Types\FileItem $file */
			foreach ( $this->index->getFiles() as $file ) {
				$path                      = trailingslashit( $this->getFullPath() ) . $file->getName();
				$files[ $file->getName() ] = $this->getFilesystem()->file( $path, $this->getOptions() );
			}

			ksort( $files, SORT_NATURAL );

			return $files;
		} );

	}

	public function createAsync(): PromiseInterface {
		return $this->existsAsync()->then( function () {
			if ( $this->getExists() ) {
				return $this->getExists();
			}
			$this->initIndexData();

			return Utils::all( [ $this->createParent(), $this->writeIndex() ] )->then( function () {
				$this->setExists( true );

				return true;
			} )->otherwise( function ( $e ) {
				if ( $e instanceof \Exception ) {
					throw $e;
				}

				return false;
			} );
		} );
	}

	public function getDirectories(): array {
		return $this->getDirectoriesAsync()->wait();
	}

	public function getFiles(): array {
		return $this->getFilesAsync()->wait();
	}

	public function deleteAsync( bool $force = false ): PromiseInterface {
		return $this->existsAsync()->then( function () use ( $force ) {
			if ( ! $this->getExists() ) {
				$this->initIndexData();
			}

			return Utils::all( [
				$this->getDirectoriesAsync(),
				$this->getFilesAsync(),
			] )->then( function ( $results ) use ( $force ) {
				[ $directories, $files ] = $results;
				$promises = [];
				/** @var \Skynet\Filesystem\Directory $directory */
				foreach ( $directories as $directory ) {
					$promises[] = $directory->deleteAsync( $force );
				}

				/** @var \Skynet\Filesystem\File $file */
				foreach ( $files as $file ) {
					$promises[] = $file->deleteAsync( $force );
				}

				if ( $this->isRoot() ) {
					return Utils::all( $promises )->then( fn() => true );
				}
				$deleteMethod = $this->isPrivate() ? 'deleteJSONEncryptedAsync' : 'deleteJSONAsync';
				$promises[]   = $this->getClient()->{$deleteMethod}( $this->getIndexPath() );

				$parent = $this->getParentDirectory();
				if ( $parent ) {
					$promises[] = $parent->deleteDirectoryAsync( $this );
				}

				return Utils::all( $promises )->then( function () {
					$this->initIndexData();
					$this->setExists( false );
					$this->getFilesystem()->resetDirectory( $this->getFullPath() );

					return true;
				} )->otherwise( function ( $e ) {
					if ( $e instanceof \Exception ) {
						throw $e;
					}

					return false;
				} );
			} )->otherwise( function ( $e ) {
				if ( $e instanceof \Exception ) {
					throw $e;
				}

				return false;
			} );
		} );


	}

	public function deleteDirectoryAsync( Directory $directory ): PromiseInterface {
		return $this->existsAsync()->then( function () use ( $directory ) {
			if ( ! $this->getExists() ) {
				$this->initIndexData();
			}

			$dirs = $this->index->getDirectories();
			$name = $directory->getName();
			if ( isset( $dirs[ $name ] ) ) {
				unset( $dirs[ $name ] );
				$this->index->setDirectories( $dirs );

				return $this->writeIndex();
			}

			return null;
		} );
	}

	public function delete( bool $force = false ): bool {
		return $this->deleteAsync( $force )->wait();
	}

	public function create(): bool {
		return $this->createAsync()->wait();
	}

	public function deleteDirectory( Directory $directory, bool $force = false ): void {
		$this->deleteDirectoryAsync( $directory, $force )->wait();
	}

	public function move( $folderPathOrDirectory ): bool {
		return $this->rename( $folderPathOrDirectory );
	}

	public function rename( $folderPathOrDirectory ): bool {
		return $this->renameAsync( $folderPathOrDirectory )->wait();
	}

	public function renameAsync( $folderPathOrDirectory ): PromiseInterface {
		return $this->copyOrRename( $folderPathOrDirectory, 'rename' );
	}

	public function moveAsync( $folderPathOrDirectory ): PromiseInterface {
		return $this->renameAsync( $folderPathOrDirectory );
	}

	public function getItem( string $name ): ?Node {
		return $this->getItemAsync( $name )->wait();
	}

	public function getItemAsync( string $name ): PromiseInterface {
		return $this->getItemsAsync()->then( function ( array $children ) use ( $name ) {
			return $children[ $name ] ?? null;
		} );
	}

}
