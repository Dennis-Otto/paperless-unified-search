<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\SearchFailure;
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
	public function testAFailureIsRememberedWithoutTheTokenTheTermAndTheQuery(Throwable $error, array $secrets, string $type, string $message): void {
		$diagnostics = $this->diagnostics();

		$diagnostics->recordFailure(SearchFailure::STEP_PAPERLESS, $error, 4711, $secrets);

		$failure = $diagnostics->getLastFailure();
		self::assertNotNull($failure);
		self::assertSame([
			'time' => self::NOW,
			'step' => SearchFailure::STEP_PAPERLESS,
			'error' => $type,
			'message' => $message,
			'durationMs' => 4711,
		], $failure->jsonSerialize());
	}

	public function testTheFailureIsStoredLazilyAsTheOnlyOneAndForgetsTheRecovery(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('setValueArray')
			->with(AppConstants::APP_ID, 'last_failure', self::callback(static fn (array $value): bool => $value['step'] === SearchFailure::STEP_FILES), true);
		$config->expects(self::once())
			->method('deleteKey')
			->with(AppConstants::APP_ID, 'last_recovery');

		(new SearchDiagnostics($config, $this->clock()))
			->recordFailure(SearchFailure::STEP_FILES, new RuntimeException('Storage unavailable'), 20, []);
	}

	public function testANegativeDurationCountsAsNone(): void {
		$diagnostics = $this->diagnostics();

		$diagnostics->recordFailure(SearchFailure::STEP_FILES, new RuntimeException('Clock went back'), -5, []);

		self::assertSame(0, $diagnostics->getLastFailure()?->durationMs);
	}

	public function testTheFirstSearchThatWorksAfterAFailureIsTheRecovery(): void {
		$diagnostics = $this->diagnostics();
		$diagnostics->recordFailure(SearchFailure::STEP_PAPERLESS, new RuntimeException('Timeout'), 3000, []);
		self::assertNull($diagnostics->getRecoveredAt());

		$diagnostics->recordSuccess();
		self::assertSame(self::NOW, $diagnostics->getRecoveredAt());

		$later = new SearchDiagnostics($this->config(), $this->clock(self::NOW + 60));
		$later->recordSuccess();
		self::assertSame(self::NOW, $later->getRecoveredAt());

		$later->recordFailure(SearchFailure::STEP_PAPERLESS, new RuntimeException('Timeout'), 3000, []);
		self::assertNull($later->getRecoveredAt());
	}

	public function testASearchThatWorksWithoutAFailureStoresNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturn([]);
		$config->expects(self::never())->method('setValueInt');

		(new SearchDiagnostics($config, $this->clock()))->recordSuccess();
	}

	public function testClearForgetsTheFailureAndTheRecovery(): void {
		$diagnostics = $this->diagnostics();
		$diagnostics->recordFailure(SearchFailure::STEP_PAPERLESS, new RuntimeException('Timeout'), 3000, []);
		$diagnostics->recordSuccess();

		$diagnostics->clear();

		self::assertNull($diagnostics->getLastFailure());
		self::assertNull($diagnostics->getRecoveredAt());
	}

	/**
	 * @return array<string, array{array<array-key, mixed>}>
	 */
	public static function storedValuesOfNoFailure(): array {
		$failure = ['time' => self::NOW, 'step' => 'paperless', 'error' => 'RuntimeException', 'message' => '', 'durationMs' => 1];

		return [
			'nothing' => [[]],
			'time as text' => [['time' => '1791500000'] + $failure],
			'unknown step' => [['step' => 'mail'] + $failure],
			'error that is no text' => [['error' => 42] + $failure],
			'message that is no text' => [['message' => null] + $failure],
			'duration as decimal' => [['durationMs' => 1.5] + $failure],
		];
	}

	/**
	 * @param array<array-key, mixed> $values
	 */
	#[DataProvider('storedValuesOfNoFailure')]
	public function testStoredValuesOfTheWrongShapeAreNoFailure(array $values): void {
		$this->stored['last_failure'] = $values;

		self::assertNull($this->diagnostics()->getLastFailure());
	}

	private function diagnostics(): SearchDiagnostics {
		return new SearchDiagnostics($this->config(), $this->clock());
	}

	private function clock(int $time = self::NOW): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn($time);

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
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('getValueArray')->willReturnCallback(function (string $app, string $key, array $default): array {
			$value = $this->stored[$key] ?? $default;

			return is_array($value) ? $value : $default;
		});
		$config->method('getValueInt')->willReturnCallback(function (string $app, string $key, int $default): int {
			$value = $this->stored[$key] ?? $default;

			return is_int($value) ? $value : $default;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->stored[$key]);
		});

		return $config;
	}
}
