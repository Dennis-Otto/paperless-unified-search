<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class SearchDiagnosticsTest extends TestCase {
	private const NOW = 1791500000;

	private const HISTORY = AppConstants::APP_ID . '.diagnostics';
	private const PENDING = AppConstants::APP_ID . '.diagnostics_pending';

	/** The configuration of the apps, which keeps the history and the mark. */
	private MemoryAppConfig $config;
	private int $now = self::NOW;

	protected function setUp(): void {
		$this->config = new MemoryAppConfig();
	}

	/**
	 * @return array<string, array{Throwable, list<string>, string, string}>
	 */
	public static function failures(): array {
		return [
			'timeout of cURL, with the term in the query' => [
				new RuntimeException('cURL error 28: Connection timed out after 3001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://paperless.example.com/api/documents/?query=salary&page=1&page_size=5'),
				['TEST_VALUE', 'salary'],
				'RuntimeException',
				'cURL error 28: Connection timed out after 3001 milliseconds for https://paperless.example.com/api/documents/',
			],
			'token and term in the message' => [
				new RuntimeException("Token TEST_VALUE refused\n\tfor Salary July"),
				['TEST_VALUE', 'salary july'],
				'RuntimeException',
				'Token … refused for …',
			],
			'term too short to leave out' => [
				new RuntimeException('Paperless returned HTTP 502.'),
				['TEST_VALUE', 'HT'],
				'RuntimeException',
				'Paperless returned HTTP 502.',
			],
			'term of three letters, just long enough' => [
				new RuntimeException('Tax return for TAX 2026'),
				['TEST_VALUE', 'tax'],
				'RuntimeException',
				'… return for … 2026',
			],
			// Two letters in four bytes of UTF-8 are too short: the letters count, not the bytes.
			'term of two letters beyond ASCII' => [
				new RuntimeException('Bescheid äö für ÄÖ'),
				['TEST_VALUE', 'äö'],
				'RuntimeException',
				'Bescheid äö für ÄÖ',
			],
			'host refused by Nextcloud' => [
				new LocalServerException('Host "paperless.example.com" violates local access rules'),
				['TEST_VALUE', 'salary'],
				'LocalServerException',
				'Host "paperless.example.com" violates local access rules',
			],
			'fragment of a URL' => [
				new RuntimeException('Redirected to `https://paperless.example.com/login#next`'),
				[],
				'RuntimeException',
				'Redirected to `https://paperless.example.com/login`',
			],
			// str_ireplace() folds the case of ASCII letters only, and left Ärger in place (#128).
			'term in another case of another script' => [
				new RuntimeException('Ärger über die Rechnung, ÄRGER mit dem Amt'),
				['TEST_VALUE', 'ärger'],
				'RuntimeException',
				'… über die Rechnung, … mit dem Amt',
			],
			'term with blanks of its own' => [new RuntimeException('Nothing for Salary July'), ['salary   july '], 'RuntimeException', 'Nothing for …'],
			// Leaving out the pointer of cURL would join the term together.
			'term split by the pointer of cURL' => [
				new RuntimeException('Searched inv (see https://curl.se/libcurl/c/libcurl-errors.html)oice'),
				['invoice'],
				'RuntimeException',
				'Searched …',
			],
			// The longer secret first, or the term would leave its ends behind.
			'term that holds the token' => [new RuntimeException('Searched for xTEST_VALUEx'), ['TEST_VALUE', 'xtest_valuex'], 'RuntimeException', 'Searched for …'],
			'bytes that are not UTF-8' => [new RuntimeException("Bad \xC3( answer for invoice"), ['invoice'], 'RuntimeException', 'Bad ?( answer for …'],
			'term with bytes that are not UTF-8' => [new RuntimeException("Nothing for inv\xFFoice"), ["inv\xFFoice"], 'RuntimeException', 'Nothing for …'],
			'no message' => [new RuntimeException(''), ['TEST_VALUE'], 'RuntimeException', ''],
			'long message' => [new RuntimeException(str_repeat('a', 400)), [], 'RuntimeException', str_repeat('a', 299) . '…'],
		];
	}

	/**
	 * @param list<string> $secrets
	 */
	#[DataProvider('failures')]
	public function testAFailureIsRecordedWithoutTheTokenTheTermAndTheQuery(Throwable $error, array $secrets, string $type, string $message): void {
		$diagnostics = $this->diagnostics();

		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, $error, 4711, $secrets);

		self::assertSame([
			'since' => self::NOW,
			'failed' => 1,
			'retried' => 0,
			'lastFailureAt' => self::NOW,
			'recoveredAt' => null,
			'events' => [[
				'time' => self::NOW,
				'kind' => SearchEvent::KIND_FAILED,
				'step' => SearchEvent::STEP_PAPERLESS,
				'error' => $type,
				'message' => $message,
				'durationMs' => 4711,
			]],
		], $diagnostics->getHistory()?->jsonSerialize());
	}

	public function testARetryIsRecordedAsAProblemOfTheRequestToPaperless(): void {
		$diagnostics = $this->diagnostics();

		$diagnostics->recordRetry(new RuntimeException('cURL error 6: Could not resolve host: paperless.example.com'), 3002, ['TEST_VALUE', 'salary']);

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(0, $history->failed);
		self::assertSame(1, $history->retried);
		self::assertNull($history->lastFailureAt);
		self::assertFalse($history->awaitsRecovery());
		self::assertSame([
			'time' => self::NOW,
			'kind' => SearchEvent::KIND_RETRIED,
			'step' => SearchEvent::STEP_PAPERLESS,
			'error' => 'RuntimeException',
			'message' => 'cURL error 6: Could not resolve host: paperless.example.com',
			'durationMs' => 3002,
		], $history->events[0]->jsonSerialize());
	}

	public function testTheHistoryCountsEveryProblemAndKeepsTheLatestNewestFirst(): void {
		$diagnostics = $this->diagnostics();

		for ($problem = 1; $problem <= DiagnosticsHistory::SIZE + 5; $problem++) {
			$this->now = self::NOW + $problem;
			if ($problem % 5 === 0) {
				$diagnostics->recordFailure(SearchEvent::STEP_FILES, new RuntimeException('Problem ' . $problem), $problem, []);
			} else {
				$diagnostics->recordRetry(new RuntimeException('Problem ' . $problem), $problem, []);
			}
		}

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(self::NOW + 1, $history->since);
		self::assertSame(5, $history->failed);
		self::assertSame(20, $history->retried);
		self::assertSame(self::NOW + 25, $history->lastFailureAt);
		self::assertCount(DiagnosticsHistory::SIZE, $history->events);
		self::assertSame('Problem 25', $history->events[0]->message);
		self::assertSame('Problem 6', $history->events[DiagnosticsHistory::SIZE - 1]->message);
		// The stored history keeps no more problems than it shows, however many come.
		self::assertCount(DiagnosticsHistory::SIZE, $this->storedHistory()['events']);
	}

	/**
	 * The history is lazy, and the mark that a failure waits for a search that works again
	 * isn't, so that a search that works reads only the mark. A failure removes the mark
	 * after it saved the history: a request that began before a recovery still holds the
	 * mark as true, and Nextcloud writes no value that it holds (#133).
	 */
	public function testTheHistoryIsStoredLazilyUnderOneKey(): void {
		$calls = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturn([]);
		$config->expects(self::once())
			->method('setValueArray')
			->with(AppConstants::APP_ID, 'diagnostics', self::callback(static fn (array $value): bool => $value['failed'] === 1), true)
			->willReturnCallback(static function () use (&$calls): bool {
				$calls[] = 'history';

				return true;
			});
		$config->expects(self::once())
			->method('deleteKey')
			->with(AppConstants::APP_ID, 'diagnostics_pending')
			->willReturnCallback(static function () use (&$calls): void {
				$calls[] = 'mark';
			});
		$config->expects(self::never())->method('setValueBool');

		(new SearchDiagnostics($config, $this->clock(), $this->noCache()))
			->recordFailure(SearchEvent::STEP_FILES, new RuntimeException('Storage unavailable'), 20, []);

		self::assertSame(['history', 'mark'], $calls);
	}

	public function testANegativeDurationCountsAsNone(): void {
		$diagnostics = $this->diagnostics();

		$diagnostics->recordFailure(SearchEvent::STEP_FILES, new RuntimeException('Clock went back'), -5, []);

		self::assertSame(0, $diagnostics->getHistory()?->events[0]->durationMs);
	}

	public function testTheFirstSearchThatWorksAfterAFailureIsTheRecovery(): void {
		$diagnostics = $this->diagnostics();
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Timeout'), 3000, []);
		self::assertTrue($diagnostics->getHistory()?->awaitsRecovery());

		$this->now = self::NOW + 60;
		$diagnostics->recordSuccess();
		self::assertSame(self::NOW + 60, $diagnostics->getHistory()?->recoveredAt);

		$this->now = self::NOW + 120;
		$diagnostics->recordSuccess();
		$diagnostics->recordRetry(new RuntimeException('Timeout'), 3000, []);
		self::assertSame(self::NOW + 60, $diagnostics->getHistory()?->recoveredAt);

		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Timeout'), 3000, []);
		self::assertNull($diagnostics->getHistory()?->recoveredAt);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function historiesThatAwaitNoRecovery(): array {
		return [
			'nothing stored' => [[]],
			'retries only' => [['since' => self::NOW, 'failed' => 0, 'retried' => 2, 'lastFailureAt' => null, 'recoveredAt' => null, 'events' => []]],
		];
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	#[DataProvider('historiesThatAwaitNoRecovery')]
	public function testASearchThatWorksWithoutAFailureStoresNothing(array $stored): void {
		if ($stored !== []) {
			$this->config->seed(AppConstants::APP_ID, 'diagnostics', $stored, true);
		}

		$this->diagnostics()->recordSuccess();

		self::assertSame(1, $this->reads());
		self::assertSame(0, $this->writes());
	}

	/**
	 * While no failure waits for a search that works again, a search that works reads only
	 * the mark, which Nextcloud holds in memory, and writes nothing (#128).
	 */
	public function testASearchThatWorksReadsNoHistoryWhileNoFailureWaits(): void {
		$this->config->seed(AppConstants::APP_ID, 'diagnostics_pending', false);

		$this->diagnostics()->recordSuccess();

		self::assertSame([self::PENDING], $this->config->calls('hasKey'));
		self::assertSame([self::PENDING], $this->config->calls('getValueBool'));
		self::assertSame(0, $this->reads());
		self::assertSame(0, $this->writes());
		self::assertSame(0, $this->marks());
	}

	public function testAFailureMarksThatItWaitsForASearchThatWorksAgain(): void {
		$this->config->seed(AppConstants::APP_ID, 'diagnostics_pending', false);
		$diagnostics = $this->diagnostics();

		$diagnostics->recordRetry(new RuntimeException('Timeout'), 3000, []);
		self::assertFalse($this->config->stored(AppConstants::APP_ID, 'diagnostics_pending'));

		// Without the mark, the next search that works reads the history once.
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Timeout'), 3000, []);
		self::assertNull($this->config->stored(AppConstants::APP_ID, 'diagnostics_pending'));

		$diagnostics->recordSuccess();
		self::assertFalse($this->config->stored(AppConstants::APP_ID, 'diagnostics_pending'));
		self::assertSame(self::NOW, $diagnostics->getHistory()?->recoveredAt);

		$reads = $this->reads();
		$diagnostics->recordSuccess();
		self::assertSame($reads, $this->reads());
	}

	/**
	 * Version 0.5.0 kept no mark: the first search that works after the update reads the
	 * history once, and ends a failure that waits in it.
	 */
	public function testTheFirstSearchThatWorksAfterAnUpdateReadsTheHistoryOnce(): void {
		$this->config->seed(AppConstants::APP_ID, 'diagnostics', ['since' => self::NOW, 'failed' => 1, 'retried' => 0, 'lastFailureAt' => self::NOW, 'recoveredAt' => null, 'events' => []], true);
		$diagnostics = $this->diagnostics();
		$this->now = self::NOW + 60;

		$diagnostics->recordSuccess();
		$diagnostics->recordSuccess();

		self::assertSame(1, $this->reads());
		self::assertSame(self::NOW + 60, $diagnostics->getHistory()?->recoveredAt);
		self::assertFalse($this->config->stored(AppConstants::APP_ID, 'diagnostics_pending'));
	}

	/**
	 * During an outage every keystroke of every user fails, and every write of the
	 * configuration of the apps empties its cache for all of Nextcloud: within 30 seconds,
	 * failures write once and count the others, which the next write takes along (#128).
	 */
	public function testFailuresWithinThirtySecondsWriteTheHistoryOnce(): void {
		$diagnostics = $this->diagnostics($this->memoryCache());

		foreach ([0, 10, 29] as $second) {
			$this->now = self::NOW + $second;
			$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Failure at ' . $second), 3000, []);
		}
		self::assertSame(1, $this->writes());
		self::assertSame(1, $diagnostics->getHistory()?->failed);

		$this->now = self::NOW + 30;
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Failure at 30'), 3000, []);

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(2, $this->writes());
		self::assertSame(4, $history->failed);
		self::assertSame(self::NOW + 30, $history->lastFailureAt);
		self::assertSame(['Failure at 30', 'Failure at 0'], array_map(static fn (SearchEvent $event): string => $event->message, $history->events));
	}

	/**
	 * A retry and a failure each write the history, though they come within 30 seconds;
	 * retries count among themselves.
	 */
	public function testRetriesWriteApartFromFailures(): void {
		$diagnostics = $this->diagnostics($this->memoryCache());

		$diagnostics->recordRetry(new RuntimeException('Retry'), 100, []);
		$this->now = self::NOW + 1;
		$diagnostics->recordRetry(new RuntimeException('Retry'), 100, []);
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Failure'), 3000, []);

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(2, $this->writes());
		self::assertSame(1, $history->failed);
		self::assertSame(2, $history->retried);
		self::assertCount(2, $history->events);
	}

	/**
	 * The search that works again takes the counted failures along. It doesn't end the 30
	 * seconds: a failure within them only counts, and the first one after them writes as a
	 * new outage (#133).
	 */
	public function testTheRecoveryTakesTheCountedFailuresAlong(): void {
		$diagnostics = $this->diagnostics($this->memoryCache());

		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('First'), 3000, []);
		$this->now = self::NOW + 5;
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Counted'), 3000, []);
		$this->now = self::NOW + 6;
		$diagnostics->recordSuccess();

		self::assertSame(2, $diagnostics->getHistory()?->failed);
		self::assertSame(self::NOW + 6, $diagnostics->getHistory()?->recoveredAt);

		$this->now = self::NOW + 7;
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Counted after the recovery'), 3000, []);
		self::assertSame(2, $this->writes());
		self::assertSame(self::NOW + 6, $diagnostics->getHistory()?->recoveredAt);

		$this->now = self::NOW + 30;
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('New outage'), 3000, []);

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(4, $history->failed);
		self::assertTrue($history->awaitsRecovery());
		self::assertSame('New outage', $history->events[0]->message);
	}

	/**
	 * A struggling Paperless often fails only some of the requests. Failing every other one
	 * for 30 seconds writes one failure and one recovery, not one of each per request, and
	 * the first write after the 30 seconds counts every failure in between (#133).
	 */
	public function testAPaperlessThatFailsEveryOtherRequestWritesOnceInThirtySeconds(): void {
		$diagnostics = $this->diagnostics($this->memoryCache());

		for ($second = 0; $second < 30; $second++) {
			$this->now = self::NOW + $second;
			if ($second % 2 === 0) {
				$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Failure at ' . $second), 3000, []);
			} else {
				$diagnostics->recordSuccess();
			}
		}

		self::assertSame(2, $this->writes());
		self::assertSame(2, $this->marks());
		self::assertSame(1, $diagnostics->getHistory()?->failed);
		self::assertSame(self::NOW + 1, $diagnostics->getHistory()?->recoveredAt);

		$this->now = self::NOW + 30;
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Failure at 30'), 3000, []);
		$this->now = self::NOW + 31;
		$diagnostics->recordSuccess();

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(4, $this->writes());
		self::assertSame(16, $history->failed);
		self::assertSame(self::NOW + 31, $history->recoveredAt);
		self::assertSame(['Failure at 30', 'Failure at 0'], array_map(static fn (SearchEvent $event): string => $event->message, $history->events));
	}

	/**
	 * A failure of another request that is saved right after a recovery awaits the next
	 * search that works, which notes its recovery, rather than staying unrecovered while
	 * every search after it works (#133).
	 */
	public function testAFailureSavedRightAfterTheRecoveryAwaitsTheNextSearchThatWorks(): void {
		$diagnostics = $this->diagnostics();
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('First'), 3000, []);

		$this->now = self::NOW + 10;
		$this->config->afterNextWrite(AppConstants::APP_ID, 'diagnostics', function (): void {
			$this->diagnostics()->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Meanwhile'), 3000, []);
		});
		$diagnostics->recordSuccess();
		self::assertTrue($diagnostics->getHistory()?->awaitsRecovery());

		$this->now = self::NOW + 20;
		$diagnostics->recordSuccess();

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(2, $history->failed);
		self::assertSame(self::NOW + 20, $history->recoveredAt);
		self::assertFalse($this->config->stored(AppConstants::APP_ID, 'diagnostics_pending'));
	}

	/**
	 * Clearing the history forgets the counted problems too, and the next one writes.
	 */
	public function testClearForgetsTheCountedProblems(): void {
		$diagnostics = $this->diagnostics($this->memoryCache());
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('First'), 3000, []);
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Counted'), 3000, []);
		$diagnostics->recordRetry(new RuntimeException('Retry'), 100, []);
		$diagnostics->recordRetry(new RuntimeException('Counted retry'), 100, []);

		$diagnostics->clear();
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('After clearing'), 3000, []);
		$diagnostics->recordRetry(new RuntimeException('Retry after clearing'), 100, []);

		$history = $diagnostics->getHistory();
		self::assertNotNull($history);
		self::assertSame(1, $history->failed);
		self::assertSame(1, $history->retried);
		self::assertCount(2, $history->events);
	}

	/**
	 * Without a cache that can count, as in a Nextcloud without any, every problem writes.
	 */
	public function testWithoutACacheThatCountsEveryProblemWrites(): void {
		$diagnostics = $this->diagnostics($this->noCache());

		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('First'), 3000, []);
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Second'), 3000, []);

		self::assertSame(2, $this->writes());
		self::assertSame(2, $diagnostics->getHistory()?->failed);
	}

	/**
	 * Clearing forgets the history alone: the last failure that version 0.3.0 kept is gone
	 * after the update, which runs the repair step RemoveLegacyDiagnostics.
	 */
	public function testClearForgetsTheHistory(): void {
		$this->config->seed(AppConstants::APP_ID, 'diagnostics', ['since' => self::NOW, 'failed' => 1, 'retried' => 0, 'lastFailureAt' => self::NOW, 'recoveredAt' => null, 'events' => []], true);

		$this->diagnostics()->clear();

		self::assertSame([AppConstants::APP_ID . '.diagnostics'], $this->config->calls('deleteKey'));
		// No failure waits any more, which a search that works then reads without the history.
		self::assertSame(['diagnostics_pending' => false], $this->config->storedValues(AppConstants::APP_ID));
	}

	/**
	 * @return array<string, array{array<array-key, mixed>}>
	 */
	public static function storedValuesOfNoHistory(): array {
		$history = ['since' => self::NOW, 'failed' => 1, 'retried' => 0, 'lastFailureAt' => self::NOW, 'recoveredAt' => null, 'events' => []];

		return [
			'nothing' => [[]],
			'since as text' => [['since' => '1791500000'] + $history],
			'count of failures that is no number' => [['failed' => null] + $history],
			'count of retries as decimal' => [['retried' => 1.5] + $history],
			'last failure as text' => [['lastFailureAt' => 'yesterday'] + $history],
			'recovery as decimal' => [['recoveredAt' => 1.5] + $history],
			'events that are no list' => [['events' => 'none'] + $history],
		];
	}

	/**
	 * @param array<array-key, mixed> $values
	 */
	#[DataProvider('storedValuesOfNoHistory')]
	public function testStoredValuesOfTheWrongShapeAreNoHistory(array $values): void {
		$this->config->seed(AppConstants::APP_ID, 'diagnostics', $values, true);

		self::assertNull($this->diagnostics()->getHistory());
	}

	public function testStoredEventsOfTheWrongShapeAreLeftOut(): void {
		$event = ['time' => self::NOW, 'kind' => 'failed', 'step' => 'paperless', 'error' => 'RuntimeException', 'message' => '', 'durationMs' => 1];
		$this->config->seed(AppConstants::APP_ID, 'diagnostics', [
			'since' => self::NOW,
			'failed' => 1,
			'retried' => 0,
			'lastFailureAt' => self::NOW,
			'recoveredAt' => self::NOW + 5,
			'events' => [
				'not an event',
				['time' => '1791500000'] + $event,
				['kind' => 'warning'] + $event,
				['step' => 'mail'] + $event,
				['error' => 42] + $event,
				['message' => null] + $event,
				['durationMs' => 1.5] + $event,
				$event,
				...array_fill(0, DiagnosticsHistory::SIZE + 3, ['kind' => 'retried'] + $event),
			],
		], true);

		$history = $this->diagnostics()->getHistory();

		self::assertNotNull($history);
		self::assertSame(self::NOW + 5, $history->recoveredAt);
		self::assertCount(DiagnosticsHistory::SIZE, $history->events);
		self::assertSame(SearchEvent::KIND_FAILED, $history->events[0]->kind);
		self::assertSame(SearchEvent::KIND_RETRIED, $history->events[1]->kind);
	}

	/**
	 * A history that only a lazy read finds: Nextcloud loads no lazy value for a read that
	 * isn't lazy, and the fakes before ignored the flag (#137).
	 */
	public function testTheHistoryIsReadLazily(): void {
		$this->diagnostics()->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Timeout'), 3000, []);

		self::assertTrue($this->config->isLazy(AppConstants::APP_ID, 'diagnostics'));
		self::assertSame([], $this->config->getValueArray(AppConstants::APP_ID, 'diagnostics'));
		self::assertSame(1, $this->diagnostics()->getHistory()?->failed);
	}

	/**
	 * Diagnostics ask Nextcloud for their cache once, however many problems they count.
	 */
	public function testTheCacheIsCreatedOnce(): void {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects(self::once())
			->method('createDistributed')
			->with(AppConstants::APP_ID)
			->willReturn(new MemoryCache(fn (): int => $this->now));
		$diagnostics = $this->diagnostics($cacheFactory);

		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('First'), 3000, []);
		$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException('Counted'), 3000, []);
		$diagnostics->recordRetry(new RuntimeException('Retry'), 100, []);
		$diagnostics->recordSuccess();

		self::assertSame(2, $diagnostics->getHistory()?->failed);
	}

	/**
	 * The keys of the cache stay the same from one version to the next, as the cache keeps
	 * them across an update: for each kind of problem, the mark of the last write and the
	 * problems counted since.
	 */
	public function testTheCacheKeepsTheMarkAndTheCountOfEachKindUnderAKeyOfItsOwn(): void {
		$cache = new MemoryCache(fn (): int => $this->now);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$diagnostics = $this->diagnostics($cacheFactory);

		foreach (['First', 'Counted', 'Counted too'] as $message) {
			$diagnostics->recordFailure(SearchEvent::STEP_PAPERLESS, new RuntimeException($message), 3000, []);
		}
		$diagnostics->recordRetry(new RuntimeException('Retry'), 100, []);
		$diagnostics->recordRetry(new RuntimeException('Counted retry'), 100, []);

		// The write of the first retry took the two counted failures along.
		self::assertSame([
			'diagnostics-written-failed' => 1,
			'diagnostics-counted-failed' => 0,
			'diagnostics-written-retried' => 1,
			'diagnostics-counted-retried' => 1,
		], $cache->values());
	}

	private function diagnostics(?ICacheFactory $cacheFactory = null): SearchDiagnostics {
		return new SearchDiagnostics($this->config, $this->clock(), $cacheFactory ?? $this->noCache());
	}

	/**
	 * The history as the configuration of the app stores it.
	 *
	 * @return array<array-key, mixed>
	 */
	private function storedHistory(): array {
		$history = $this->config->stored(AppConstants::APP_ID, 'diagnostics');
		self::assertIsArray($history);

		return $history;
	}

	/**
	 * How often the history was read.
	 */
	private function reads(): int {
		return count(array_keys($this->config->calls('getValueArray'), self::HISTORY, true));
	}

	/**
	 * How often the history was written.
	 */
	private function writes(): int {
		return count(array_keys($this->config->calls('setValueArray'), self::HISTORY, true));
	}

	/**
	 * How often the mark of a failure that waits for a search that works was set or removed.
	 */
	private function marks(): int {
		return count(array_keys([...$this->config->calls('setValueBool'), ...$this->config->calls('deleteKey')], self::PENDING, true));
	}

	/**
	 * A Nextcloud whose cache can't count, as one without any.
	 */
	private function noCache(): ICacheFactory {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createStub(ICache::class));

		return $cacheFactory;
	}

	/**
	 * A Nextcloud with a distributed cache, which runs on the clock of the test.
	 */
	private function memoryCache(): ICacheFactory {
		$cache = new MemoryCache(fn (): int => $this->now);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return $cacheFactory;
	}

	/**
	 * A clock at $this->now.
	 */
	private function clock(): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return $clock;
	}
}
