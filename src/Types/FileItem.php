<?php

namespace Skynet\Filesystem\Types;

use Skynet\Entity;

/**
 *
 */
class FileItem extends Entity {
	/**
	 * @var string
	 */
	protected string $name;
	/**
	 * @var int
	 */
	protected int $created;
	/**
	 * @var int
	 */
	protected int $modified;
	/**
	 * @var string
	 */
	protected string $mimeType;
	/**
	 * @var int
	 */
	protected int $version;
	/**
	 * @var \Skynet\Filesystem\Types\FileData
	 */
	protected FileData $file;
	/**
	 * @var \Skynet\Filesystem\Types\FileData[]
	 */
	protected array $history = [];

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
	}

	/**
	 * @return int
	 */
	public function getCreated(): int {
		return $this->created;
	}

	/**
	 * @param int $created
	 */
	public function setCreated( int $created ): void {
		$this->created = $created;
	}

	/**
	 * @return int
	 */
	public function getModified(): int {
		return $this->modified;
	}

	/**
	 * @param int $modified
	 */
	public function setModified( int $modified ): void {
		$this->modified = $modified;
	}

	/**
	 * @return string
	 */
	public function getMimeType(): string {
		return $this->mimeType;
	}

	/**
	 * @param string $mimeType
	 */
	public function setMimeType( string $mimeType ): void {
		$this->mimeType = $mimeType;
	}

	/**
	 * @return int
	 */
	public function getVersion(): int {
		return $this->version;
	}

	/**
	 * @param int $version
	 */
	public function setVersion( int $version ): void {
		$this->version = $version;
	}

	/**
	 * @return \Skynet\Filesystem\Types\FileData
	 */
	public function getFile(): FileData {
		return $this->file;
	}

	/**
	 * @param \Skynet\Filesystem\Types\FileData $file
	 */
	public function setFile( FileData $file ): void {
		$this->file = $file;
	}

	/**
	 * @return array
	 */
	public function getHistory(): array {
		return $this->history;
	}

	/**
	 * @param array $history
	 */
	public function setHistory( array $history ): void {
		$this->history = $history;
	}
}
