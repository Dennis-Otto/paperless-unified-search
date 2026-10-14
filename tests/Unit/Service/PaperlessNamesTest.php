<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessNames;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PaperlessNamesTest extends TestCase {
	private const NOW = 1791500000;

	/** @var list<string> the URL of every request to Paperless, with its query, oldest first */
	private array $requests = [];

	/** The seconds of the clock of the cache. */
	private int $time = self::NOW;

	private MemoryCache $cache;

	protected function setUp(): void {
		$this->cache = new MemoryCache(fn (): int => $this->time);
	}

	public function testDocumentsWithoutCorrespondentAndTypeCostNoRequest(): void {
		$names = $this->names($this->paperless([]))->of([
			['id' => 7, 'title' => 'Invoice'],
			['id' => 8, 'correspondent' => null, 'document_type' => null],
		]);

		self::assertSame(['correspondent' => [], 'document_type' => []], $names);
		self::assertSame([], $this->requests);
	}

	public function testOneRequestPerKindNamesEveryDocument(): void {
		$names = $this->names($this->paperless([
			'correspondents' => [3 => 'ACME Energy', 5 => 'Harbor Mutual'],
			'document_types' => [1 => 'Invoice'],
		]))->of([
			['id' => 7, 'correspondent' => 3, 'document_type' => 1],
			['id' => 8, 'correspondent' => 5, 'document_type' => 1],
			['id' => 9, 'correspondent' => 3],
		]);

		self::assertSame([
			'correspondent' => [3 => 'ACME Energy', 5 => 'Harbor Mutual'],
			'document_type' => [1 => 'Invoice'],
		], $names);
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3,5&page_size=2',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
		], $this->requests);
	}

	/**
	 * Paperless leaves out an ID that it doesn't have or that the token may not view: the
	 * cache knows that too, until it forgets after ten minutes.
	 */
	public function testTheCacheKeepsTheNamesAndTheIdsWithoutOneForTenMinutes(): void {
		$paperless = $this->paperless(['correspondents' => [3 => 'ACME Energy']]);
		$documents = [['id' => 7, 'correspondent' => 3], ['id' => 8, 'correspondent' => 4]];

		$first = $this->names($paperless)->of($documents);
		$this->time += 599;
		$second = $this->names($paperless)->of($documents);
		$this->time += 1;
		$this->names($paperless)->of($documents);

		self::assertSame(['correspondent' => [3 => 'ACME Energy'], 'document_type' => []], $first);
		self::assertSame($first, $second);
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3,4&page_size=2',
			'https://paperless.example.com/api/correspondents/?id__in=3,4&page_size=2',
		], $this->requests);
	}

	/**
	 * Two Paperless servers, one after the other in the settings, have names of their own.
	 */
	public function testTheCacheKeepsTheNamesOfEachServerApart(): void {
		$documents = [['id' => 7, 'correspondent' => 3]];

		$this->names($this->paperless(['correspondents' => [3 => 'ACME Energy']]))->of($documents);
		$names = $this->names($this->paperless(['correspondents' => [3 => 'Example Bank']], 'https://archive.example.org'), 'https://archive.example.org')
			->of($documents);

		self::assertSame([3 => 'Example Bank'], $names['correspondent']);
		self::assertCount(2, $this->requests);
	}

	/**
	 * @return array<string, array{array<array-key, mixed>}>
	 */
	public static function documentsWithoutAnObject(): array {
		return [
			'none' => [['id' => 7]],
			'null' => [['id' => 7, 'correspondent' => null]],
			'zero' => [['id' => 7, 'correspondent' => 0]],
			'negative' => [['id' => 7, 'correspondent' => -3]],
			'text' => [['id' => 7, 'correspondent' => '3']],
			'object' => [['id' => 7, 'correspondent' => ['id' => 3]]],
		];
	}

	/**
	 * @param array<array-key, mixed> $document
	 */
	#[DataProvider('documentsWithoutAnObject')]
	public function testOnlyAPositiveNumberNamesAnObject(array $document): void {
		self::assertNull(PaperlessNames::idOf($document, 'correspondent'));
		self::assertSame(['correspondent' => [], 'document_type' => []], $this->names($this->paperless([]))->of([$document]));
		self::assertSame([], $this->requests);
	}

	public function testAListThatPaperlessRefusesIsNotAskedForAgainForTenMinutes(): void {
		$paperless = $this->paperless(['correspondents' => 403, 'document_types' => [1 => 'Invoice']]);
		$documents = [['id' => 7, 'correspondent' => 3, 'document_type' => 1]];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))
			->method('info')
			->with('Paperless answered the request for the names of the {list} of search results without them ({error})', [
				'app' => AppConstants::APP_ID,
				'list' => 'correspondents',
				'error' => 'Paperless returned HTTP 403.',
			]);

		$first = $this->names($paperless, logger: $logger)->of($documents);
		$this->time += 599;
		$second = $this->names($paperless, logger: $logger)->of($documents);
		$this->time += 1;
		$this->names($paperless, logger: $logger)->of($documents);

		self::assertSame(['correspondent' => [], 'document_type' => [1 => 'Invoice']], $first);
		self::assertSame($first, $second);
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
		], $this->requests);
	}

	/**
	 * A failure that can pass, such as an error of the server, costs only the names of
	 * this search.
	 */
	public function testAnotherFailedAnswerIsAskedForAgainNextTime(): void {
		$paperless = $this->paperless(['correspondents' => 502, 'document_types' => '<html>Bad gateway</html>']);
		$documents = [['id' => 7, 'correspondent' => 3, 'document_type' => 1]];

		$first = $this->names($paperless)->of($documents);
		$this->names($paperless)->of($documents);

		self::assertSame(['correspondent' => [], 'document_type' => []], $first);
		self::assertCount(4, $this->requests);
	}

	/**
	 * Without an answer, the other kind isn't asked for: its names aren't worth a second
	 * wait for a Paperless that has stopped answering.
	 */
	public function testARequestWithoutAnswerEndsTheLookup(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with('Paperless gave no names of the {list} of search results ({errorType})', [
				'app' => AppConstants::APP_ID,
				'list' => 'correspondents',
				'errorType' => RuntimeException::class,
			]);

		$names = $this->names($this->paperless([
			'correspondents' => new RuntimeException('cURL error 28: Operation timed out'),
			'document_types' => [1 => 'Invoice'],
		]), logger: $logger)->of([['id' => 7, 'correspondent' => 3, 'document_type' => 1]]);

		self::assertSame(['correspondent' => [], 'document_type' => []], $names);
		self::assertSame(['https://paperless.example.com/api/correspondents/?id__in=3&page_size=1'], $this->requests);
	}

	/**
	 * A list that doesn't answer, as one slower than the timeout, rests for a minute: the
	 * searches of every keystroke in between don't wait for it again, and still name the
	 * other kind.
	 */
	public function testAListWithoutAnswerIsNotAskedForAgainForAMinute(): void {
		$paperless = $this->paperless([
			'correspondents' => new RuntimeException('cURL error 28: Operation timed out'),
			'document_types' => [1 => 'Invoice'],
		]);
		$documents = [['id' => 7, 'correspondent' => 3, 'document_type' => 1]];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))
			->method('info')
			->with('Paperless gave no names of the {list} of search results ({errorType})', [
				'app' => AppConstants::APP_ID,
				'list' => 'correspondents',
				'errorType' => RuntimeException::class,
			]);

		$first = $this->names($paperless, logger: $logger)->of($documents);
		$this->time += 59;
		$second = $this->names($paperless, logger: $logger)->of($documents);
		$this->time += 1;
		$third = $this->names($paperless, logger: $logger)->of($documents);

		self::assertSame(['correspondent' => [], 'document_type' => []], $first);
		self::assertSame(['correspondent' => [], 'document_type' => [1 => 'Invoice']], $second);
		self::assertSame($second, $third);
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
		], $this->requests);
	}

	/**
	 * Nothing that fails in the lookup fails the search, a cache that fails neither.
	 */
	public function testACacheThatFailsLeavesOutTheNames(): void {
		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willThrowException(new RuntimeException('Redis server went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('The names of the correspondents and types of search results failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
			]);

		$names = $this->names($this->paperless(['correspondents' => [3 => 'ACME Energy']]), cache: $cache, logger: $logger)
			->of([['id' => 7, 'correspondent' => 3]]);

		self::assertSame([], $names);
	}

	/**
	 * A Paperless whose lists of correspondents and types hold these names by ID, and
	 * answer as Paperless-ngx 3.3.0 does: only with the IDs of the parameter id__in. A list
	 * is the names by ID, the status of an answer that refuses it, the body of an answer,
	 * or the error of a request without answer.
	 *
	 * @param array<string, array<int, string>|int|string|RuntimeException> $lists by path
	 */
	private function paperless(array $lists, string $url = 'https://paperless.example.com'): IClient {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $requested, array $options) use ($lists, $url): IResponse {
			$query = $options['query'];
			$this->requests[] = $requested . '?id__in=' . $query['id__in'] . '&page_size=' . $query['page_size'];
			$list = substr($requested, strlen($url . '/api/'), -1);
			$names = $lists[$list] ?? [];
			if ($names instanceof RuntimeException) {
				throw $names;
			}
			if (is_int($names)) {
				return $this->response($names, '{"detail":"You do not have permission to perform this action."}');
			}
			if (is_string($names)) {
				return $this->response(200, $names);
			}

			$results = [];
			foreach (array_map('intval', explode(',', (string)$query['id__in'])) as $id) {
				if (isset($names[$id])) {
					$results[] = ['id' => $id, 'slug' => 'slug-' . $id, 'name' => $names[$id], 'document_count' => 1];
				}
			}

			return $this->response(200, json_encode(['count' => count($results), 'next' => null, 'previous' => null, 'results' => $results], JSON_THROW_ON_ERROR));
		});

		return $client;
	}

	private function names(
		IClient $client,
		string $url = 'https://paperless.example.com',
		?ICache $cache = null,
		?LoggerInterface $logger = null,
	): PaperlessNames {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'paperless_url' ? $url : $default,
		);

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);
		$clock->method('now')->willReturn(new DateTimeImmutable('@' . self::NOW));

		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.5.1');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->with(AppConstants::APP_ID)->willReturn($cache ?? $this->cache);

		$configService = new ConfigService($config, $credentials, $this->createStub(IUserManager::class), $this->createStub(LoggerInterface::class));

		return new PaperlessNames(
			new PaperlessApiService($configService, $clientService, new SearchDiagnostics($config, $clock, $this->createStub(ICacheFactory::class)), $clock, $appManager),
			$configService,
			$cacheFactory,
			$logger ?? $this->createStub(LoggerInterface::class),
		);
	}

	private function response(int $status, string $body): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}
}
