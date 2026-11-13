<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Model;

use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A problem of the search as the history stores it, with the cause of a request to Paperless
 * that failed (#402).
 */
final class SearchEventTest extends TestCase {
	private const EVENT = ['time' => 1791500000, 'kind' => 'failed', 'step' => 'paperless', 'error' => 'PaperlessRequestException', 'message' => 'Paperless returned HTTP 502.', 'durationMs' => 40];

	public function testAnEventWithACauseKeepsItAndItsStatus(): void {
		$event = SearchEvent::fromArray(['reason' => 'http', 'status' => 502] + self::EVENT);

		self::assertNotNull($event);
		self::assertSame(RequestFailure::Http, $event->reason);
		self::assertSame(502, $event->status);
		self::assertSame(self::EVENT + ['reason' => 'http', 'status' => 502], $event->jsonSerialize());
	}

	/**
	 * An event without a cause, as of an error of the files or of a version before the
	 * causes, stores what it stored before.
	 */
	public function testAnEventWithoutACauseStoresAsBefore(): void {
		$event = SearchEvent::fromArray(self::EVENT);

		self::assertNotNull($event);
		self::assertNull($event->reason);
		self::assertNull($event->status);
		self::assertSame(self::EVENT, $event->jsonSerialize());
	}

	/**
	 * @return array<string, array{array<string, mixed>, ?RequestFailure, ?int}>
	 */
	public static function storedCauses(): array {
		return [
			// stored values besides the event, the cause and the status that it keeps
			'a cause without an answer' => [['reason' => 'timeout', 'status' => null], RequestFailure::Timeout, null],
			'a cause without a status' => [['reason' => 'unreachable'], RequestFailure::Unreachable, null],
			'a cause that this version does not know' => [['reason' => 'quantum', 'status' => 502], null, null],
			'a cause that is no text' => [['reason' => 7, 'status' => 502], null, null],
			'a status without a cause' => [['status' => 502], null, null],
			'a status that is no number' => [['reason' => 'http', 'status' => '502'], RequestFailure::Http, null],
		];
	}

	/**
	 * The row of an event stays when its cause can't be read: it shows the error alone.
	 *
	 * @param array<string, mixed> $stored
	 */
	#[DataProvider('storedCauses')]
	public function testACauseThatCantBeReadLeavesTheEventWithoutIt(array $stored, ?RequestFailure $reason, ?int $status): void {
		$event = SearchEvent::fromArray($stored + self::EVENT);

		self::assertNotNull($event);
		self::assertSame($reason, $event->reason);
		self::assertSame($status, $event->status);
	}
}
