<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use JsonSerializable;

/**
 * The problems of the search since the history began: how many searches failed, how
 * many requests only their second try answered, when searches worked again after the
 * last failure, and the latest problems themselves, newest first.
 */
final class DiagnosticsHistory implements JsonSerializable {
	/** The number of problems that the history keeps. */
	public const SIZE = 20;

	/**
	 * @param list<SearchEvent> $events newest first
	 */
	public function __construct(
		public readonly int $since,
		public readonly int $failed,
		public readonly int $retried,
		public readonly ?int $lastFailureAt,
		public readonly ?int $recoveredAt,
		public readonly array $events,
	) {
	}

	public static function start(int $time): self {
		return new self($time, 0, 0, null, null, []);
	}

	public function with(SearchEvent $event): self {
		$failed = $event->kind === SearchEvent::KIND_FAILED;

		return new self(
			$this->since,
			$this->failed + ($failed ? 1 : 0),
			$this->retried + ($failed ? 0 : 1),
			$failed ? $event->time : $this->lastFailureAt,
			// A new failure waits for a search that works again.
			$failed ? null : $this->recoveredAt,
			array_slice([$event, ...$this->events], 0, self::SIZE),
		);
	}

	/**
	 * Whether a search failed and none has worked since.
	 */
	public function awaitsRecovery(): bool {
		return $this->lastFailureAt !== null && $this->recoveredAt === null;
	}

	public function recovered(int $time): self {
		return new self($this->since, $this->failed, $this->retried, $this->lastFailureAt, $time, $this->events);
	}

	/**
	 * The history of the stored values, or null when they describe none. Stored events
	 * of the wrong shape are left out.
	 *
	 * @param array<array-key, mixed> $values
	 */
	public static function fromArray(array $values): ?self {
		$since = $values['since'] ?? null;
		$failed = $values['failed'] ?? null;
		$retried = $values['retried'] ?? null;
		$lastFailureAt = $values['lastFailureAt'] ?? null;
		$recoveredAt = $values['recoveredAt'] ?? null;
		$storedEvents = $values['events'] ?? null;

		if (!is_int($since) || !is_int($failed) || !is_int($retried)
			|| ($lastFailureAt !== null && !is_int($lastFailureAt))
			|| ($recoveredAt !== null && !is_int($recoveredAt))
			|| !is_array($storedEvents)) {
			return null;
		}

		$events = [];
		/** @psalm-suppress MixedAssignment Every stored event is checked before use. */
		foreach ($storedEvents as $storedEvent) {
			$event = is_array($storedEvent) ? SearchEvent::fromArray($storedEvent) : null;
			if ($event !== null) {
				$events[] = $event;
			}
		}

		return new self($since, $failed, $retried, $lastFailureAt, $recoveredAt, array_slice($events, 0, self::SIZE));
	}

	/**
	 * @return array{since: int, failed: int, retried: int, lastFailureAt: ?int, recoveredAt: ?int, events: list<array{time: int, kind: string, step: string, error: string, message: string, durationMs: int}>}
	 */
	public function jsonSerialize(): array {
		return [
			'since' => $this->since,
			'failed' => $this->failed,
			'retried' => $this->retried,
			'lastFailureAt' => $this->lastFailureAt,
			'recoveredAt' => $this->recoveredAt,
			'events' => array_map(static fn (SearchEvent $event): array => $event->jsonSerialize(), $this->events),
		];
	}
}
