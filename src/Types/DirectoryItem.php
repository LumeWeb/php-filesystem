<?php

namespace Skynet\FileSystem\Types;

use Skynet\Entity;

class DirectoryItem extends Entity {
	/**
	 * @var string
	 */
	protected string $name;
	/**
	 * @var string
	 */
	protected string $created;

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
	 * @return string
	 */
	public function getCreated(): string {
		return $this->created;
	}

	/**
	 * @param string $created
	 */
	public function setCreated( string $created ): void {
		$this->created = $created;
	}
}
