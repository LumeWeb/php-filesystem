<?php

namespace Skynet\Filesystem\Types;

use Skynet\Entity;

/**
 *
 */
class FileData extends Entity {
	/**
	 * @var string
	 */
	protected string $url;
	/**
	 * @var string
	 */
	protected string $key;
	/**
	 * @var string
	 */
	protected string $encryptionType;
	/**
	 * @var int
	 */
	protected int $size;
	/**
	 * @var int
	 */
	protected int $chunkSize;
	/**
	 * @var string
	 */
	protected string $hash;
	/**
	 * @var int
	 */
	protected int $ts;

	/**
	 * @return string
	 */
	public function getUrl(): string {
		return $this->url;
	}

	/**
	 * @param string $url
	 */
	public function setUrl( string $url ): void {
		$this->url = $url;
	}

	/**
	 * @return string
	 */
	public function getKey( $raw = false ): string {
		$key = $this->key;

		if ( ! $raw ) {
			$key = str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $key ) );
		}

		return $key;
	}

	/**
	 * @param string $key
	 */
	public function setKey( string $key, bool $encoded = true ): void {
		if ( $encoded ) {
			$key = base64_decode( str_replace( [ '-', '_' ], [ '+', '/' ], $key ) );
		}

		$this->key = $key;
	}

	/**
	 * @return string
	 */
	public function getEncryptionType(): string {
		return $this->encryptionType;
	}

	/**
	 * @param string $encryptionType
	 */
	public function setEncryptionType( string $encryptionType ): void {
		$this->encryptionType = $encryptionType;
	}

	/**
	 * @return int
	 */
	public function getSize(): int {
		return $this->size;
	}

	/**
	 * @param int $size
	 */
	public function setSize( int $size ): void {
		$this->size = $size;
	}

	/**
	 * @return int
	 */
	public function getChunkSize(): int {
		return $this->chunkSize;
	}

	/**
	 * @param int $chunkSize
	 */
	public function setChunkSize( int $chunkSize ): void {
		$this->chunkSize = $chunkSize;
	}

	/**
	 * @return string
	 */
	public function getHash(): string {
		return $this->hash;
	}

	/**
	 * @param string $hash
	 */
	public function setHash( string $hash ): void {
		$this->hash = $hash;
	}

	/**
	 * @return int
	 */
	public function getTs(): int {
		return $this->ts;
	}

	/**
	 * @param int $ts
	 */
	public function setTs( int $ts ): void {
		$this->ts = $ts;
	}

}
