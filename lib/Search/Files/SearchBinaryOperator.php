<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search\Files;

use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchOperator;
use Stringable;

/**
 * Conditions of a search of the file cache joined by OR or AND, or one negated by NOT.
 * Nextcloud's public API has only the interface; the class that Nextcloud joins its own
 * conditions with is private, so the app brings its own.
 */
final class SearchBinaryOperator implements ISearchBinaryOperator, Stringable {
	/** @var array<string, mixed> the hints of Nextcloud's query optimizer, by name */
	private array $hints = [];

	/**
	 * @param string $type one of the ISearchBinaryOperator::OPERATOR_* constants
	 * @param list<ISearchOperator> $arguments the conditions, one for NOT
	 */
	public function __construct(
		private string $type,
		private array $arguments,
	) {
	}

	public function getType(): string {
		return $this->type;
	}

	/**
	 * @return list<ISearchOperator>
	 */
	public function getArguments(): array {
		return $this->arguments;
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
		$arguments = array_map(
			static fn (ISearchOperator $argument): string => $argument instanceof Stringable ? (string)$argument : $argument::class,
			$this->arguments,
		);
		if ($this->type === self::OPERATOR_NOT) {
			return '(not ' . implode(' ', $arguments) . ')';
		}

		return '(' . implode(' ' . $this->type . ' ', $arguments) . ')';
	}
}
