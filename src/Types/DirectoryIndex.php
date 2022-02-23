<?php

namespace Skynet\Filesystem\Types;

use Skynet\Entity;

class DirectoryIndex extends Entity {
	protected array $directories = [];
	protected array $files = [];

	/**
	 * @return array
	 */
	public function getFiles(): array {
		return $this->files;
	}

	/**
	 * @param array $files
	 */
	public function setFiles( array $files ): void {
		$this->files = $files;
	}

	/**
	 * @return array
	 */
	public function getDirectories(): array {
		return $this->directories;
	}

	/**
	 * @param array $directories
	 */
	public function setDirectories( array $directories ): void {
		$this->directories = $directories;
	}
}
