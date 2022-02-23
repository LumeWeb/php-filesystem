<?php

namespace Skynet\Filesystem\Types;

use Skynet\Entity;

/**
 *
 */
class SkylinkMetaSubfile extends Entity {
	/**
	 * @var string
	 */
	protected string $filename;
	/**
	 * @var string
	 */
	protected string $contenttype;
	/**
	 * @var int
	 */
	protected int $len;

	/**
	 * @return string
	 */
	public function getFilename(): string {
		return $this->filename;
	}

	/**
	 * @param string $filename
	 */
	public function setFilename( string $filename ): void {
		$this->filename = $filename;
	}

	/**
	 * @return string
	 */
	public function getContenttype(): string {
		return $this->contenttype;
	}

	/**
	 * @param string $contenttype
	 */
	public function setContenttype( string $contenttype ): void {
		$this->contenttype = $contenttype;
	}

	/**
	 * @return int
	 */
	public function getLen(): int {
		return $this->len;
	}

	/**
	 * @param int $len
	 */
	public function setLen( int $len ): void {
		$this->len = $len;
	}
}
