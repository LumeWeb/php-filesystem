<?php

namespace Skynet\Filesystem;

use Exception;
use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use ParagonIE_Sodium_Compat;
use Psr\Http\Message\StreamInterface;
use Skynet\Filesystem\Types\FileItem;
use SodiumException;

/**
 *
 */
class EncryptedFileStream implements StreamInterface {
	use StreamDecoratorTrait;

	/**
	 *
	 */
	const MODE_ENCRYPT = 'encrypt';
	/**
	 *
	 */
	const MODE_DECRYPT = 'decrypt';

	/**
	 * @var string
	 */
	private string $mode = self::MODE_ENCRYPT;
	/**
	 * @var int
	 */
	private int $blockSize;
	/**
	 * @var string
	 */
	private string $key;
	/**
	 * @var \Psr\Http\Message\StreamInterface
	 */
	private StreamInterface $stream;
	private StreamInterface $buffer;
	private StreamInterface $blockBuffer;
	private int $offset = 0;
	private int $streamOffset = 0;
	/**
	 * @var \Skynet\Filesystem\Types\FileItem
	 */
	private ?FileItem $fileData;

	/**
	 * @param \Psr\Http\Message\StreamInterface $stream
	 * @param string                            $key
	 * @param int                               $blockSize
	 */
	public function __construct( StreamInterface $stream, string $key, int $blockSize, $streamOffset = 0, FileItem $fileData = null ) {
		$this->stream       = new CachingStream( $stream );
		$this->key          = $key;
		$this->blockSize    = $blockSize;
		$this->buffer       = Utils::streamFor();
		$this->blockBuffer  = Utils::streamFor();
		$this->streamOffset = $streamOffset;
		$this->fileData     = $fileData;
	}

	/**
	 * @param \Psr\Http\Message\StreamInterface $stream
	 */
	public function setStream( $stream ): void {
		$this->stream = new CachingStream( $stream );
	}

	/**
	 * @return int|null
	 */
	public function getBlockSize(): ?int {
		return $this->blockSize;
	}

	/**
	 * @return string
	 */
	public function getMode(): string {
		return $this->mode;
	}

	/**
	 * @param string $mode
	 */
	public function setMode( string $mode ): void {
		if ( ! empty( $this->state ) ) {
			return;
		}

		$this->mode = $mode;
	}

	/**
	 * @return string
	 */
	public function getKey(): string {
		return $this->key;
	}

	/**
	 * @param string $key
	 */
	public function setKey( string $key ): void {
		$this->key = $key;
	}

	public function isWritable(): bool {
		return self::MODE_ENCRYPT === $this->mode;
	}

	public function seek( $offset, $whence = SEEK_SET ): void {
		if ( $whence === SEEK_SET ) {
			$byte = $offset - $this->getStreamOffset();
		} elseif ( $whence === SEEK_CUR ) {
			$byte = $offset + $this->getStreamOffset() + $this->buffer->tell();
		} elseif ( $whence === SEEK_END ) {
			$size = $this->stream->getSize();
			if ( $size === null ) {
				$size = $this->cacheEntireStream();
			}
			$byte = $size + $this->getStreamOffset();
		} else {
			throw new InvalidArgumentException( 'Invalid whence' );
		}

		if ( 0 > $byte ) {
			$byte = 0;
		}

		$diff = $byte - $this->buffer->getSize();

		if ( $diff > 0 ) {
			// Read the remoteStream until we have read in at least the amount
			// of bytes requested, or we reach the end of the file.
			while ( $diff > 0 && ! $this->stream->eof() ) {
				$this->read( $diff );
				$diff = $byte - $this->buffer->getSize();
			}
			$this->buffer->seek( $byte );
		} else {
			// We can just do a normal seek since we've already seen this byte.
			$this->buffer->seek( $byte );
		}
	}

	/**
	 * @return int|mixed
	 */
	public function getStreamOffset(): int {
		if ( self::MODE_ENCRYPT === $this->mode ) {
			return 0;
		}

		return $this->streamOffset;
	}

	/**
	 * @param int|mixed $streamOffset
	 */
	public function setStreamOffset( $streamOffset ): void {
		$this->streamOffset = $streamOffset;
	}

	private function cacheEntireStream(): int {
		$target = new FnStream( [ 'write' => 'strlen' ] );
		Utils::copyToStream( $this, $target );

		return $this->tell();
	}

	public function tell(): int {
		if ( self::MODE_ENCRYPT === $this->mode ) {
			return $this->buffer->tell();
		}

		return $this->getStreamOffset() + $this->buffer->tell();
		/*		$pos          = $this->getStreamOffset() + $this->buffer->tell();
				$passedBlocks = floor( $pos / ($this->blockSize + 16 ));
				$pos          -= $passedBlocks * 16;*/

		//return $pos;
	}

	public function read( $length ): string {
		// Perform a regular read on any previously read data from the buffer
		$data      = $this->buffer->read( $length );
		$remaining = $length - strlen( $data );

		$remoteData = '';
		$blockData  = '';
		if ( $remaining ) {

			if ( 0 < $this->blockBuffer->getSize() && ! $this->blockBuffer->eof() ) {
				$blockData = $this->blockBuffer->read( $remaining );
				$remaining -= strlen( $blockData );
			}
			if ( $remaining ) {
				$diff = $remaining;
				do {
					if ( self::MODE_ENCRYPT === $this->mode ) {
						$read = $this->readEncrypt();
					} else if ( self::MODE_DECRYPT === $this->mode ) {
						$read = $this->readDecrypt();
					} else {
						$this->modeError();
					}
					$len        = strlen( $read );
					$remaining  -= $len;
					$remoteData .= $read;
				} while ( 0 < $remaining && 0 < $len );

				if ( $this->blockBuffer ) {
					$this->blockBuffer->close();
				}

				$this->blockBuffer = Utils::streamFor();
				$this->blockBuffer->write( $remoteData );
				$this->blockBuffer->rewind();

				$remoteData = $this->blockBuffer->read( $diff );
				$remoteData = $blockData . $remoteData;
				$data       .= $remoteData;

			} else {
				$remoteData = $blockData;
				$data       = $blockData;
			}
			$this->buffer->write( $remoteData );
		}

		return $data;
	}

	private function readEncrypt(): string {
		$nonce  = $this->getStreamNonce();
		$buffer = $this->readFromStream();

		if ( empty( $buffer ) ) {
			return '';
		}

		if ( $this->eof() ) {
			$buffer = ParagonIE_Sodium_Compat::pad( $buffer, $this->blockSize, version_compare( SODIUM_LIBRARY_VERSION, '1.0.17', '<' ) );
		}

		$buffer = sodium_crypto_secretbox( $buffer, $nonce, $this->key );

		file_put_contents( 'data.bin', $buffer);

		return $buffer;
	}

	private function getStreamNonce() {
		$index = floor( ( $this->getStreamOffset() + $this->stream->tell() + ( self::MODE_DECRYPT === $this->mode ? 16 : 0 ) ) / ( $this->blockSize ) );

		if ( $index < 0 ) {
			$index = 0;
		}

		if ( (int) $index === 0 ) {
			$index = str_repeat( 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		}

		if ( strlen( $index ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			$index = str_repeat( 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES - strlen( $index ) ) . $index;
		}


		return $index;
	}

	private function readFromStream() {
		$bufferSize = $this->blockSize;
		if ( $this->mode === self::MODE_DECRYPT ) {
			$bufferSize += 16;
		}

		$buffer = '';
		$left   = $bufferSize;
		do {
			$bufferPart = $this->stream->read( $left );
			if ( empty( $bufferPart ) && empty( $buffer ) ) {
				return null;
			}
			$buffer .= $bufferPart;
			$left   -= strlen( $bufferPart );
		} while ( $left > 0 && ! $this->stream->eof() );

		return $buffer;
	}

	public function eof(): bool {
		$bufTell = $this->buffer->tell();
		$bufSize = $this->buffer->getSize();
	$bufEof  = false;
		if ( 0 < $bufTell && 0 < $bufSize && 1 === (int) floor( $bufTell / $bufSize ) ) {
			$bufEof = true;
		}

		return ( $bufEof || $this->buffer->eof() ) && $this->blockBuffer->eof() && $this->stream->eof();
	}

	private function readDecrypt(): string {
		$nonce  = $this->getStreamNonce();
		$buffer = $this->readFromStream();

		if ( empty( $buffer ) ) {
			return '';
		}

		$decrypted = sodium_crypto_secretbox_open( $buffer, $nonce, $this->key );

		if ( ! $decrypted ) {
			throw  new Exception( 'Failed to decrypt stream chunk' );
		}

		try {
			$decrypted = sodium_unpad( $decrypted, $this->blockSize );
		} catch ( SodiumException $e ) {

		}

		return $decrypted;
	}

	private function modeError(): void {
		throw new Exception( sprintf( 'Invalid stream mode %s', $this->mode ) );
	}

	public function getSize(): int {

		$size = $this->stream->getSize();

		if ( null === $size ) {
			$size = 0;
			if ( isset( $this->fileData ) ) {
				$size = $this->fileData->getFile()->getSize();
				if ( ! empty( $this->fileData->getFile()->getKey() ) ) {
					$convertSize = true;
				}
			}
		}


		if ( self::MODE_ENCRYPT === $this->mode || $convertSize ) {
			$blocks    = ceil( $size / $this->blockSize );
			$blockMeta = $blocks * 16;
			$size      = ( $blocks * $this->blockSize ) + $blockMeta;
		}


		return $size;
	}

	/**
	 * @return \Psr\Http\Message\StreamInterface
	 */
	public function getPlaintextStream() {
		return $this->stream;
	}
}
