<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use JsonSerializable;

/**
 * What a user wants to be notified about: every new document that they can open, or only
 * those that one of their saved searches finds. The searches stay stored while the user
 * asks for every document, so that taking that back brings them back.
 */
final class AlertChoice implements JsonSerializable {
	/**
	 * @param list<string> $terms the saved searches, in the syntax of Paperless
	 */
	public function __construct(
		public readonly bool $everyDocument = false,
		public readonly array $terms = [],
	) {
	}

	/**
	 * The choice as a preference stores it, without anything else that it may hold.
	 */
	public static function fromStored(mixed $stored): self {
		$stored = is_array($stored) ? $stored : [];

		$terms = [];
		/** @psalm-suppress MixedAssignment Every term is checked before use. */
		foreach (is_array($stored['terms'] ?? null) ? $stored['terms'] : [] as $term) {
			if (is_string($term)) {
				$terms[] = $term;
			}
		}

		return new self(($stored['everyDocument'] ?? false) === true, $terms);
	}

	/**
	 * Whether the user wants no notification at all.
	 */
	public function isNothing(): bool {
		return !$this->everyDocument && $this->terms === [];
	}

	/**
	 * @return array{everyDocument: bool, terms: list<string>}
	 */
	public function jsonSerialize(): array {
		return ['everyDocument' => $this->everyDocument, 'terms' => $this->terms];
	}
}
