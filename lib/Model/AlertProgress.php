<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

/**
 * How far the job of the notifications has come with one Paperless server, as the settings
 * of the app keep it from one run to the next.
 */
final class AlertProgress {
	/**
	 * @param string $server the hash of the address of the server, whose IDs these are
	 * @param ?int $lastSeen the highest ID of a document that the job has seen, null before
	 *                       Paperless first told
	 * @param int $checkedAt the moment that Paperless last answered which documents are new
	 * @param array<int, array{title: string, since: int}> $waiting the new documents without
	 *                                                              a file yet, by ID, highest first,
	 *                                                              with the moment that the job
	 *                                                              first saw each
	 * @param int $failures how many runs failed since Paperless last answered them all
	 * @param int $restingUntil the moment before which no run asks Paperless, 0 for none
	 */
	public function __construct(
		public readonly string $server,
		public readonly ?int $lastSeen,
		public readonly int $checkedAt,
		public readonly array $waiting = [],
		public readonly int $failures = 0,
		public readonly int $restingUntil = 0,
	) {
	}

	/**
	 * The progress as the settings store it, or null without any for this server: a
	 * document of another Paperless has its own IDs. What the settings hold wrongly is left
	 * out.
	 *
	 * @param array<array-key, mixed> $stored
	 */
	public static function fromStored(array $stored, string $server): ?self {
		if (($stored['server'] ?? null) !== $server) {
			return null;
		}

		$waiting = [];
		/** @psalm-suppress MixedAssignment Every document is checked before use. */
		foreach (is_array($stored['waiting'] ?? null) ? $stored['waiting'] : [] as $document) {
			$id = self::numberOf($document, 'id');
			$since = self::numberOf($document, 'since');
			/** @psalm-suppress MixedAssignment The title is checked before use. */
			$title = is_array($document) ? $document['title'] ?? null : null;
			if ($id !== null && $since !== null && is_string($title)) {
				$waiting[$id] = ['title' => $title, 'since' => $since];
			}
		}

		return new self(
			$server,
			self::numberOf($stored, 'lastSeen'),
			self::numberOf($stored, 'checkedAt') ?? 0,
			$waiting,
			self::numberOf($stored, 'failures') ?? 0,
			self::numberOf($stored, 'restingUntil') ?? 0,
		);
	}

	/**
	 * The whole number under a key of what the settings hold, or null for anything else.
	 */
	private static function numberOf(mixed $stored, string $key): ?int {
		/** @psalm-suppress MixedAssignment The number is checked before use. */
		$number = is_array($stored) ? $stored[$key] ?? null : null;

		return is_int($number) ? $number : null;
	}

	/**
	 * The progress as the settings store it: the documents as a list, since JSON would turn
	 * the keys of a list that isn't numbered from 0 into text.
	 *
	 * @return array{server: string, lastSeen: ?int, checkedAt: int, waiting: list<array{id: int, title: string, since: int}>, failures: int, restingUntil: int}
	 */
	public function toStored(): array {
		$waiting = [];
		foreach ($this->waiting as $id => $document) {
			$waiting[] = ['id' => $id, 'title' => $document['title'], 'since' => $document['since']];
		}

		return [
			'server' => $this->server,
			'lastSeen' => $this->lastSeen,
			'checkedAt' => $this->checkedAt,
			'waiting' => $waiting,
			'failures' => $this->failures,
			'restingUntil' => $this->restingUntil,
		];
	}
}
