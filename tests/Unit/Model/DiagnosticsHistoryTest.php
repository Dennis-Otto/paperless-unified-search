<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Model;

use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use PHPUnit\Framework\TestCase;

final class DiagnosticsHistoryTest extends TestCase {
	private const NOW = 1791500000;

	/**
	 * However many problems come, the history keeps the latest ones, newest first, also in
	 * what it stores.
	 */
	public function testTheHistoryKeepsTheLatestProblems(): void {
		$history = DiagnosticsHistory::start(self::NOW);
		for ($problem = 1; $problem <= DiagnosticsHistory::SIZE + 5; $problem++) {
			$history = $history->with(new SearchEvent(self::NOW + $problem, SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS, 'RuntimeException', 'Problem ' . $problem, 10));
		}

		$events = $history->jsonSerialize()['events'];
		self::assertCount(DiagnosticsHistory::SIZE, $events);
		self::assertSame('Problem 25', $events[0]['message']);
		self::assertSame('Problem 6', $events[DiagnosticsHistory::SIZE - 1]['message']);
		self::assertSame(25, $history->retried);
	}

	/**
	 * Counts of problems come from the cache, which a broken one or a race may leave below
	 * zero: they take nothing away from the history.
	 */
	public function testACountBelowZeroTakesNothingAway(): void {
		$history = new DiagnosticsHistory(self::NOW, 3, 2, self::NOW, null, []);

		$counted = $history->counting(-1, -5)->counting(2, 1);

		self::assertSame(5, $counted->failed);
		self::assertSame(3, $counted->retried);
		self::assertSame(self::NOW, $counted->lastFailureAt);
		self::assertTrue($counted->awaitsRecovery());
	}
}
