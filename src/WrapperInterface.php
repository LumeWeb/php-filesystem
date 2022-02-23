<?php

namespace Skynet\FileSystem;

interface WrapperInterface {
	/**
	 * resource state
	 *
	 * @var resource
	 */
	//public $state;

	/**
	 * constructor
	 *
	 */
	public function __construct();

	/**
	 *
	 *
	 * @return bool
	 */
	public function dir_closedir(): bool;

	/**
	 * Enter description here...
	 *
	 * @param string $path
	 * @param int    $options
	 *
	 * @return bool
	 */
	public function dir_opendir( string $path, int $options ): bool;

	/**
	 * Enter description here...
	 *
	 * @return string|bool
	 */
	public function dir_readdir();

	/**
	 * Enter description here...
	 *
	 * @return bool
	 */
	public function dir_rewinddir(): bool;

	/**
	 * Enter description here...
	 *
	 * @param string $path
	 * @param int    $mode
	 * @param int    $options
	 *
	 * @return bool
	 */
	public function mkdir( $path, $mode, $options ): bool;

	/**
	 * Enter description here...
	 *
	 * @param string $path_from
	 * @param string $path_to
	 *
	 * @return bool
	 */
	public function rename( string $path_from, string $path_to ): bool;

	/**
	 * Enter description here...
	 *
	 * @param string $path
	 * @param int    $options
	 *
	 * @return bool
	 */
	public function rmdir( string $path, int $options ): bool;

	/**
	 * Enter description here...
	 *
	 * @param int $cast_as
	 *
	 * @return resource
	 */
	public function stream_cast( int $cast_as );

	/**
	 * Enter description here...
	 *
	 */
	public function stream_close(): void;

	/**
	 * Enter description here...
	 *
	 * @return bool
	 */
	public function stream_eof(): bool;

	/**
	 * Enter description here...
	 *
	 * @return bool
	 */
	public function stream_flush(): bool;

	/**
	 * Enter description here...
	 *
	 * @param mode $operation
	 *
	 * @return bool
	 */
	public function stream_lock( int $operation ): bool;


	public function stream_metadata( string $path, int $option, $value ): bool;

	/**
	 * Enter description here...
	 *
	 * @param string  $path
	 * @param string  $mode
	 * @param int     $options
	 * @param string &$opened_path
	 *
	 * @return bool
	 */
	public function stream_open( string $path, string $mode, int $options, string &$opened_path = null ): bool;

	/**
	 * Enter description here...
	 *
	 * @param int $count
	 *
	 * @return string
	 */
	public function stream_read( $count ): string;

	/**
	 * Enter description here...
	 *
	 * @param int $offset
	 * @param int $whence = SEEK_SET
	 *
	 * @return bool
	 */
	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool;

	/**
	 * Enter description here...
	 *
	 * @param int $option
	 * @param int $arg1
	 * @param int $arg2
	 *
	 * @return bool
	 */
	public function stream_set_option( int $option, int $arg1, int $arg2 ): bool;

	/**
	 * Enter description here...
	 *
	 * @return array|bool
	 */
	public function stream_stat();

	/**
	 * Enter description here...
	 *
	 * @return int
	 */
	public function stream_tell(): int;

	/**
	 * Enter description here...
	 *
	 * @param string $data
	 *
	 * @return int
	 */
	public function stream_write( string $data ): int;

	/**
	 * Enter description here...
	 *
	 * @param string $path
	 *
	 * @return bool
	 */
	public function unlink( string $path ): bool;

	/**
	 * Enter description here...
	 *
	 * @param string $path
	 * @param int    $flags
	 *
	 * @return array
	 */
	public function url_stat( string $path, int $flags );
}
