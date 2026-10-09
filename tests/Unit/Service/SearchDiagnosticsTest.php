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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class SearchDiagnosticsTest extends TestCase {
	private const NOW = 1791500000;

	/** @var array<string, mixed> the stored values of the app, by key */
	private array $stored = [];
	private int $now = self::NOW;

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
	}

	public function testTheHistoryIsStoredLazilyUnderOneKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturn([]);
		$config->expects(self::once())
			->method('setValueArray')
			->with(AppConstants::APP_ID, 'diagnostics', self::callback(static fn (array $value): bool => $value['failed'] === 1), true);

		(new SearchDiagnostics($config, $this->clock()))
			->recordFailure(SearchEvent::STEP_FILES, new RuntimeException('Storage unavailable'), 20, []);
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
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturn($stored);
		$config->expects(self::never())->method('setValueArray');

		(new SearchDiagnostics($config, $this->clock()))->recordSuccess();
	}

	public function testClearForgetsTheHistoryAndTheLastFailureOfVersion030(): void {
		$deleted = [];
		$config = $this->createStub(IAppConfig::class);
		$config->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$deleted): void {
			$deleted[] = $app . '.' . $key;
		});

		(new SearchDiagnostics($config, $this->clock()))->clear();

		self::assertSame([
			AppConstants::APP_ID . '.diagnostics',
			AppConstants::APP_ID . '.last_failure',
			AppConstants::APP_ID . '.last_recovery',
		], $deleted);
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
		$this->stored['diagnostics'] = $values;

		self::assertNull($this->diagnostics()->getHistory());
	}

	public function testStoredEventsOfTheWrongShapeAreLeftOut(): void {
		$event = ['time' => self::NOW, 'kind' => 'failed', 'step' => 'paperless', 'error' => 'RuntimeException', 'message' => '', 'durationMs' => 1];
		$this->stored['diagnostics'] = [
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
		];

		$history = $this->diagnostics()->getHistory();

		self::assertNotNull($history);
		self::assertSame(self::NOW + 5, $history->recoveredAt);
		self::assertCount(DiagnosticsHistory::SIZE, $history->events);
		self::assertSame(SearchEvent::KIND_FAILED, $history->events[0]->kind);
		self::assertSame(SearchEvent::KIND_RETRIED, $history->events[1]->kind);
	}

	private function diagnostics(): SearchDiagnostics {
		return new SearchDiagnostics($this->config(), $this->clock());
	}

	/**
	 * A clock at $this->now.
	 */
	private function clock(): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return $clock;
	}

	/**
	 * The configuration of the app, in $this->stored.
	 */
	private function config(): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('getValueArray')->willReturnCallback(function (string $app, string $key, array $default): array {
			$value = $this->stored[$key] ?? $default;

			return is_array($value) ? $value : $default;
		});

		return $config;
	}
}
