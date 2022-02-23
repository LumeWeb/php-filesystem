<?php

namespace Skynet\Filesystem;

use ArrayIterator;
use function Skynet\functions\url\trimUriPrefix;

class LumeStreamWrapper implements WrapperInterface {

	public const SCHEME = 'lume';
	/**
	 * @var resource
	 */
	public $context = null;
	private ?Node $node = null;
	private ?ArrayIterator $iterator;

	public function __construct() {
	}

	public function dir_closedir(): bool {
		$this->iterator = null;

		filesystem()->resetObject( $this->node );
		$this->node = null;

		return true;
	}

	public function dir_opendir( string $path, int $options ): bool {
		$path = $this->stripSkynetPrefix( $path );
		$dir  = $this->getDirectory( $path );

		if ( ! $dir->exists() ) {
			return false;
		}

		$this->node = $dir;
		$this->setupDirectoryIterator();

		return true;
	}

	private function stripSkynetPrefix( $path ): string {
		return normalizePath( $path );
	}

	private function getDirectory( $path ): Directory {
		$path = $this->stripSkynetPrefix( $path );

		return filesystem()->directory( $path, $this->getOptions() );
	}

	private function getOptions(): array {
		return getContextOptions( $this->context, false );
	}

	private function setupDirectoryIterator(): void {
		if ( ! isset( $this->iterator ) || null === $this->iterator ) {
			$this->iterator = new ArrayIterator( $this->node->getItems() );
		}
	}

	public function dir_readdir() {
		$cur = $this->iterator->current();

		/** @var \Skynet\Filesystem\Node $cur */
		if ( $cur ) {
			$cur = $cur->getName();
		}

		$this->iterator->next();

		return $cur ?? false;
	}

	public function dir_rewinddir(): bool {
		$this->iterator->rewind();

		return true;
	}

	public function mkdir( $path, $mode, $options ): bool {
		return $this->getDirectory( $path )->create();
	}

	public function rename( string $path_from, string $path_to ): bool {
		$path_from = $this->stripSkynetPrefix( $path_from );
		$path_to   = $this->stripSkynetPrefix( $path_to );

		$node = $this->getNode( $path_from );

		if ( $node->isDirectory() ) {
			$node = $this->getDirectory( $path_from );

			return $node->rename( $path_to );
		}

		if ( $node->isFile() ) {
			$node = $this->getFile( $path_from );

			return $node->rename( $path_to );
		}

		return false;
	}

	private function getNode( $path ): Node {
		$path = $this->stripSkynetPrefix( $path );

		return filesystem()->node( $path, $this->getOptions() );
	}

	private function getFile( $path ): File {
		$path = $this->stripSkynetPrefix( $path );

		return filesystem()->file( $path, $this->getOptions() );
	}

	public function rmdir( string $path, int $options ): bool {
		return $this->getDirectory( $path )->delete();
	}

	public function stream_cast( int $cast_as ) {
	}

	public function stream_close(): void {
		$this->node->close();
	}

	public function stream_eof(): bool {
		return $this->node->eof();
	}

	public function stream_flush(): bool {
		return true;
	}

	public function stream_lock( int $operation ): bool {
		return false;
	}

	public function stream_metadata( string $path, int $option, $value ): bool {
		$path = $this->stripSkynetPrefix( $path );

		if ( $option === STREAM_META_TOUCH ) {
			$node = $this->getNode( $path );
			if ( $node->isDirectory() ) {
				return true;
			}

			return $this->getFile( $path )->touch();
		}

		return true;
	}

	public function stream_open( string $path, string $mode, int $options, string &$opened_path = null ): bool {
		$file       = $this->getFile( $path );
		$this->node = $file;
		switch ( $mode ) {
			case 'r':
			case 'r+':
			case 'rb':
				if ( ! $file->exists() ) {
					return false;
				}
				$file->open();
				$file->rewind();
				break;
			case 'w':
			case 'w+':
			case 'wb':
				$file->open();
				$file->rewind();
				break;
			case 'a':
			case 'a+':
				$file->open();
				$file->end();
				break;
			case 'x':
			case 'x+':
				if ( $file->exists() ) {
					return false;
				}
				$file->open();
				$file->end();
				break;
		}


		return true;
		// TODO: Implement stream_open() method.
	}

	public function stream_read( $count ): string {
		return $this->node->read( $count );
		// TODO: Implement stream_read() method.
	}

	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool {
		return $this->node->seek( $offset, $whence );
		// TODO: Implement stream_seek() method.
	}

	public function stream_set_option( int $option, int $arg1, int $arg2 ): bool {
		return true;
		// TODO: Implement stream_set_option() method.
	}

	public function stream_stat() {
		return $this->node->stat();
	}

	public function stream_tell(): int {
		return $this->node->tell();
		// TODO: Implement stream_tell() method.
	}

	public function stream_write( string $data ): int {
		return $this->node->write( $data );
		// TODO: Implement stream_write() method.
	}

	public function unlink( string $path ): bool {
		$file = $this->getFile( $path );

		if ( $file->isFile() ) {
			$file->delete();

			return true;
		}

		return false;
	}

	public function url_stat( string $path, int $flags ) {
		return $this->getNode( $path )->stat();
	}
}
