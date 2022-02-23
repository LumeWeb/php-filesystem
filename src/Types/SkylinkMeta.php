<?php

namespace Skynet\Filesystem\Types;

use Skynet\Entity;

/**
 *
 */
class SkylinkMeta extends Entity {
	/**
	 * @var string
	 */
	protected string $filename;
	/**
	 * @var int
	 */
	protected int $length;
	/**
	 * @var \Skynet\Filesystem\Types\SkylinkMetaSubfile[]
	 */
	protected array $subfiles = [];
	/**
	 * @var string[]
	 */
	protected array $tryfiles = [];

	/**
	 * @return string
	 */
	public function getFilename(): string {
		return $this->filename;
	}

	/**
	 * @return int
	 */
	public function getLength(): int {
		return $this->length;
	}

	/**
	 * @return \Skynet\Filesystem\Types\SkylinkMetaSubfile[]
	 */
	public function getSubfiles(): array {
		return $this->subfiles;
	}

	/**
	 * @return string[]
	 */
	public function getTryfiles(): array {
		return $this->tryfiles;
	}
}
