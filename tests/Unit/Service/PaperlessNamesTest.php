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

	public function testDocumentsWithoutCorrespondentTypeAndTagsCostNoRequest(): void {
		$names = $this->names($this->paperless([]))->of([
			['id' => 7, 'title' => 'Invoice'],
			['id' => 8, 'correspondent' => null, 'document_type' => null, 'tags' => null],
			['id' => 9, 'tags' => []],
		]);

		self::assertSame(['correspondent' => [], 'document_type' => [], 'tags' => []], $names);
		self::assertSame([], $this->requests);
	}

	/**
	 * One request per kind names all documents of a page, the tags as well, however many
	 * each document has.
	 */
	public function testOneRequestPerKindNamesEveryDocument(): void {
		$names = $this->names($this->paperless([
			'correspondents' => [3 => 'ACME Energy', 5 => 'Harbor Mutual'],
			'document_types' => [1 => 'Invoice'],
			'tags' => [2 => 'tax 2026', 4 => 'unpaid'],
		]))->of([
			['id' => 7, 'correspondent' => 3, 'document_type' => 1, 'tags' => [4, 2]],
			['id' => 8, 'correspondent' => 5, 'document_type' => 1, 'tags' => [2]],
			['id' => 9, 'correspondent' => 3],
		]);

		self::assertSame([
			'correspondent' => [3 => 'ACME Energy', 5 => 'Harbor Mutual'],
			'document_type' => [1 => 'Invoice'],
			'tags' => [4 => 'unpaid', 2 => 'tax 2026'],
		], $names);
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3,5&page_size=2',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
			'https://paperless.example.com/api/tags/?id__in=4,2&page_size=2',
		], $this->requests);
	}

	/**
	 * @return array<string, array{mixed, list<int>}>
	 */
	public static function tagsOfADocument(): array {
		return [
			'two tags' => [[4, 2], [4, 2]],
			'a tag twice' => [[4, 2, 4], [4, 2]],
			'none' => [[], []],
			'null' => [null, []],
			// Only a positive number is the ID of a tag.
			'IDs that are none' => [[0, -1, '2', null, [3], 2.0, 5], [5]],
			// The tags are a list, never a single ID.
			'a single ID' => [4, []],
			'a text' => ['4,2', []],
		];
	}

	/**
	 * @param list<int> $ids
	 */
	#[DataProvider('tagsOfADocument')]
	public function testTheTagsOfADocumentAreAListOfIds(mixed $tags, array $ids): void {
		$names = ['tags' => [2 => 'tag 2', 3 => 'tag 3', 4 => 'tag 4', 5 => 'tag 5']];

		self::assertSame(
			array_map(static fn (int $id): string => 'tag ' . $id, $ids),
			PaperlessNames::namesOf(['id' => 7, 'tags' => $tags], 'tags', $names),
		);
	}

	/**
	 * The tag of the inbox, which Paperless gives every new document until someone has
	 * looked at it, says nothing about a document: it has no name, which the cache keeps.
	 */
	public function testTheTagOfTheInboxHasNoName(): void {
		$paperless = $this->paperless(['tags' => [1 => ['name' => 'Inbox', 'is_inbox_tag' => true], 4 => ['name' => 'unpaid', 'is_inbox_tag' => false]]]);
		$documents = [['id' => 7, 'tags' => [1, 4]]];

		$first = $this->names($paperless)->of($documents);
		$second = $this->names($paperless)->of($documents);

		self::assertSame(['correspondent' => [], 'document_type' => [], 'tags' => [4 => 'unpaid']], $first);
		self::assertSame($first, $second);
		self::assertSame(['https://paperless.example.com/api/tags/?id__in=1,4&page_size=2'], $this->requests);
	}

	/**
	 * @return array<string, array{array<array-key, mixed>, list<string>}>
	 */
	public static function shownNames(): array {
		return [
			'all of them' => [['correspondent' => 3, 'document_type' => 1, 'tags' => [4, 2]], ['ACME Energy', 'Invoice', 'unpaid', 'tax 2026']],
			'a tag alone' => [['tags' => [2]], ['tax 2026']],
			'three tags' => [['correspondent' => 3, 'tags' => [4, 2, 6]], ['ACME Energy', 'unpaid', 'tax 2026', '+1']],
			'five tags' => [['tags' => [6, 4, 2, 8, 9]], ['warranty', 'unpaid', '+3']],
			// Only a tag with a name counts, so the tag of the inbox and an unknown one not.
			'three tags of five with a name' => [['tags' => [1, 6, 99, 4, 2]], ['warranty', 'unpaid', '+1']],
			'two tags of four with a name' => [['tags' => [1, 6, 99, 4]], ['warranty', 'unpaid']],
			'names on one line' => [['correspondent' => 5, 'tags' => [8]], ['Harbor Mutual', 'tax 2025']],
			'IDs without a name' => [['correspondent' => 99, 'document_type' => 99, 'tags' => [99, 1]], []],
			'none' => [['id' => 7], []],
		];
	}

	/**
	 * A result shows the names of the correspondent, the type and up to two tags of its
	 * document, then how many more tags it has.
	 *
	 * @param array<array-key, mixed> $document
	 * @param list<string> $shown
	 */
	#[DataProvider('shownNames')]
	public function testAResultShowsTheCorrespondentTheTypeAndUpToTwoTags(array $document, array $shown): void {
		$names = [
			'correspondent' => [3 => 'ACME Energy', 5 => "Harbor\n Mutual "],
			'document_type' => [1 => 'Invoice'],
			'tags' => [1 => '', 2 => 'tax 2026', 4 => 'unpaid', 6 => 'warranty', 8 => "\ttax  2025", 9 => 'paid'],
		];

		self::assertSame($shown, PaperlessNames::shownOf($document, $names));
	}

	/**
	 * Try a search of the settings shows the tags of a document alone, and every one of them.
	 */
	public function testOnlyTheFieldsAskedForCostARequest(): void {
		$names = $this->names($this->paperless(['tags' => [4 => 'unpaid', 2 => 'tax 2026', 6 => 'paid']]))
			->of([['id' => 7, 'correspondent' => 3, 'document_type' => 1, 'tags' => [4, 2, 6]]], PaperlessNames::TAGS);

		self::assertSame(['tags' => [4 => 'unpaid', 2 => 'tax 2026', 6 => 'paid']], $names);
		self::assertSame(['https://paperless.example.com/api/tags/?id__in=4,2,6&page_size=3'], $this->requests);
		self::assertSame(['unpaid', 'tax 2026', 'paid'], PaperlessNames::namesOf(['tags' => [4, 2, 6]], 'tags', $names));
	}

	public function testAResultWithoutNamesShowsNone(): void {
		self::assertSame([], PaperlessNames::shownOf(['correspondent' => 3, 'document_type' => 1, 'tags' => [4]], []));
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

		self::assertSame(['correspondent' => [3 => 'ACME Energy'], 'document_type' => [], 'tags' => []], $first);
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
	 * A correspondent and a type of document may have the same ID, and each keeps its own
	 * name: the cache keeps each name under a key of its own, of its kind, of the server
	 * and of its ID, as it keeps the newest documents of the dashboard.
	 */
	public function testTheCacheKeepsTheNamesOfEachKindApart(): void {
		$paperless = $this->paperless([
			'correspondents' => [3 => 'ACME Energy'],
			'document_types' => [3 => 'Invoice'],
		]);
		$documents = [['id' => 7, 'correspondent' => 3, 'document_type' => 3]];

		$first = $this->names($paperless)->of($documents);
		$second = $this->names($paperless)->of($documents);

		self::assertSame(['correspondent' => [3 => 'ACME Energy'], 'document_type' => [3 => 'Invoice'], 'tags' => []], $first);
		self::assertSame($first, $second);
		self::assertCount(2, $this->requests);
		$server = hash('sha256', 'https://paperless.example.com');
		self::assertSame([
			'name-correspondent-' . $server . '-3' => 'ACME Energy',
			'name-document_type-' . $server . '-3' => 'Invoice',
		], $this->cache->values());
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
		self::assertSame([], PaperlessNames::namesOf($document, 'correspondent', ['correspondent' => [3 => 'ACME Energy']]));
		self::assertSame(['correspondent' => [], 'document_type' => [], 'tags' => []], $this->names($this->paperless([]))->of([$document]));
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

		self::assertSame(['correspondent' => [], 'document_type' => [1 => 'Invoice'], 'tags' => []], $first);
		self::assertSame($first, $second);
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
		], $this->requests);
		// Only the list of that kind on that server rests, under a key of its own.
		$server = hash('sha256', 'https://paperless.example.com');
		self::assertSame([
			'names-resting-correspondent-' . $server => true,
			'name-document_type-' . $server . '-1' => 'Invoice',
		], $this->cache->values());
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

		self::assertSame(['correspondent' => [], 'document_type' => [], 'tags' => []], $first);
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

		self::assertSame(['correspondent' => [], 'document_type' => [], 'tags' => []], $names);
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

		self::assertSame(['correspondent' => [], 'document_type' => [], 'tags' => []], $first);
		self::assertSame(['correspondent' => [], 'document_type' => [1 => 'Invoice'], 'tags' => []], $second);
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
			->with('The names of the correspondents, types and tags of search results failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
			]);

		$names = $this->names($this->paperless(['correspondents' => [3 => 'ACME Energy']]), cache: $cache, logger: $logger)
			->of([['id' => 7, 'correspondent' => 3]]);

		self::assertSame([], $names);
	}

	/**
	 * A token that may not view tags gets HTTP 403 for them: the correspondent and the
	 * type are still named, the results show without tags, and Paperless isn't asked for
	 * the tags again for ten minutes.
	 */
	public function testTagsThatPaperlessRefusesAreNotAskedForAgainForTenMinutes(): void {
		$paperless = $this->paperless(['correspondents' => [3 => 'ACME Energy'], 'document_types' => [1 => 'Invoice'], 'tags' => 403]);
		$documents = [['id' => 7, 'correspondent' => 3, 'document_type' => 1, 'tags' => [4, 2]]];

		$first = $this->names($paperless)->of($documents);
		$this->time += 599;
		$second = $this->names($paperless)->of($documents);
		$this->time += 1;
		$this->names($paperless)->of($documents);

		self::assertSame(['correspondent' => [3 => 'ACME Energy'], 'document_type' => [1 => 'Invoice'], 'tags' => []], $first);
		self::assertSame($first, $second);
		self::assertSame(['ACME Energy', 'Invoice'], PaperlessNames::shownOf($documents[0], $first));
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
			'https://paperless.example.com/api/tags/?id__in=4,2&page_size=2',
			// Ten minutes later, the cache has forgotten the names as well.
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/document_types/?id__in=1&page_size=1',
			'https://paperless.example.com/api/tags/?id__in=4,2&page_size=2',
		], $this->requests);
	}

	/**
	 * Tags that take longer than the timeout rest for a minute, while the correspondents
	 * and the types are still named; and after correspondents without answer, the tags
	 * aren't asked for either.
	 */
	public function testTagsWithoutAnswerAreNotAskedForAgainForAMinute(): void {
		$timeout = new RuntimeException('cURL error 28: Operation timed out');
		$documents = [['id' => 7, 'correspondent' => 3, 'tags' => [4]]];

		$first = $this->names($this->paperless(['correspondents' => [3 => 'ACME Energy'], 'tags' => $timeout]))->of($documents);
		$this->time += 59;
		$second = $this->names($this->paperless(['correspondents' => [3 => 'ACME Energy'], 'tags' => [4 => 'unpaid']]))->of($documents);
		$this->time += 1;
		$third = $this->names($this->paperless(['correspondents' => [3 => 'ACME Energy'], 'tags' => [4 => 'unpaid']]))->of($documents);
		// Once the cache has forgotten every name.
		$this->time += 700;
		$fourth = $this->names($this->paperless(['correspondents' => $timeout, 'tags' => [4 => 'unpaid']]))->of($documents);

		self::assertSame(['correspondent' => [3 => 'ACME Energy'], 'document_type' => [], 'tags' => []], $first);
		self::assertSame($first, $second);
		self::assertSame(['correspondent' => [3 => 'ACME Energy'], 'document_type' => [], 'tags' => [4 => 'unpaid']], $third);
		self::assertSame(['correspondent' => [], 'document_type' => [], 'tags' => []], $fourth);
		self::assertSame([
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
			'https://paperless.example.com/api/tags/?id__in=4&page_size=1',
			'https://paperless.example.com/api/tags/?id__in=4&page_size=1',
			'https://paperless.example.com/api/correspondents/?id__in=3&page_size=1',
		], $this->requests);
	}

	/**
	 * A Paperless whose lists of correspondents, types and tags hold these names by ID,
	 * and answer as Paperless-ngx 3.3.0 does: only with the IDs of the parameter id__in. A
	 * list is the names by ID, or the fields of each object by ID, such as is_inbox_tag of
	 * a tag, the status of an answer that refuses it, the body of an answer, or the error
	 * of a request without answer.
	 *
	 * @param array<string, array<int, string|array<string, mixed>>|int|string|RuntimeException> $lists by path
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
					$fields = is_array($names[$id]) ? $names[$id] : ['name' => $names[$id]];
					$results[] = ['id' => $id, 'slug' => 'slug-' . $id] + $fields + ['document_count' => 1];
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

		// A lookup opens the cache of Nextcloud once, however many names it reads.
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects(self::atMost(1))->method('createDistributed')->with(AppConstants::APP_ID)->willReturn($cache ?? $this->cache);

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
