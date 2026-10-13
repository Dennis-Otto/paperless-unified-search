<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search\Files;

use OCP\Files\Search\ISearchComparison;
use Stringable;

/**
 * A condition of a search of the file cache, such as a name LIKE '%[P412]%'. Nextcloud's
 * public API has only the interface; the class that Nextcloud builds its own conditions
 * with is private, so the app brings its own.
 */
final class SearchComparison implements ISearchComparison, Stringable {
	/** @var array<string, mixed> the hints of Nextcloud's query optimizer, by name */
	private array $hints = [];

	/**
	 * @param string $type one of the ISearchComparison::COMPARE_* constants
	 * @param string $field a column of the file cache, such as 'name'
	 */
	public function __construct(
		private string $type,
		private string $field,
		private string $value,
	) {
	}

	public function getType(): string {
		return $this->type;
	}

	public function getField(): string {
		return $this->field;
	}

	/**
	 * The condition is on the file cache itself, not on the metadata of the files.
	 */
	public function getExtra(): string {
		return '';
	}

	public function getValue(): string {
		return $this->value;
	}

	public function getQueryHint(string $name, mixed $default): mixed {
		return $this->hints[$name] ?? $default;
	}

	public function setQueryHint(string $name, mixed $value): void {
		$this->hints[$name] = $value;
	}

	/**
	 * Nextcloud's query optimizer tells conditions apart by this text, so it is the one
	 * of Nextcloud's own class.
	 */
	public function __toString(): string {
		return $this->field . ' ' . $this->type . ' ' . json_encode($this->value, JSON_THROW_ON_ERROR);
	}
}
