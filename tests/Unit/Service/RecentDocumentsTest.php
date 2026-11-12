<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use Closure;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\RecentDocuments;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class RecentDocumentsTest extends TestCase {
	use Accounts;
	use AppServices;

	private const NOW = 1791500000;

	/** The newest documents of Paperless, as it sends them with the fields of the dashboard. */
	private const DOCUMENTS = [
		['id' => 412, 'title' => 'Electricity invoice', 'added' => '2026-08-13T09:12:45.118731+02:00', 'correspondent' => 3, 'document_type' => 1],
		['id' => 389, 'title' => 'Home insurance renewal', 'added' => '2026-07-22T10:00:00+02:00', 'correspondent' => null, 'document_type' => null],
	];

	/** The page of these documents, the only one of Paperless. */
	private const PAGE = ['documents' => self::DOCUMENTS, 'more' => false];

	/** The seconds of the clock of the cache. */
	private int $time = self::NOW;

	private MemoryCache $cache;

	protected function setUp(): void {
		$this->cache = new MemoryCache(fn (): int => $this->time);
		$this->connected();
	}

	/**
	 * Paperless lists its newest documents by the moment that it added them, one page of
	 * them, with the fields that the dashboard shows.
	 */
	public function testAsksPaperlessForItsNewestDocuments(): void {
		$recent = $this->recent($this->paperless($this->documents(self::DOCUMENTS)));

		self::assertSame(self::PAGE, $recent->find());
		self::assertCount(1, $this->requests);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://paperless.example.com/api/documents/', $url);
		self::assertSame([
			'ordering' => '-added',
			'page' => 1,
			'page_size' => RecentDocuments::COUNT,
			'fields' => 'id,title,added,correspondent,document_type,tags,owner',
		], $options['query']);
		self::assertSame(25, RecentDocuments::COUNT);
		// The documents of each Paperless under a key of its own.
		self::assertSame(['recent-documents-' . hash('sha256', 'https://paperless.example.com')], array_keys($this->cache->values()));
	}

	/**
	 * For the users who may see only the documents with some tags (decision 0015), the
	 * dashboard asks for the newest documents with one of them, which the cache keeps apart
	 * from those of everyone, for every user with the same tags.
	 */
	public function testTheDocumentsWithSomeTagsAreKeptApart(): void {
		$recent = $this->recent($this->paperless($this->documents([self::DOCUMENTS[1]]), $this->documents(self::DOCUMENTS)));

		self::assertSame(['documents' => [self::DOCUMENTS[1]], 'more' => false], $recent->find([8, 9]));
		self::assertSame(self::PAGE, $recent->find());
		self::assertSame(['documents' => [self::DOCUMENTS[1]], 'more' => false], $recent->find([8, 9]));
		self::assertCount(2, $this->requests);
		self::assertSame('8,9', $this->requests[0][1]['query']['tags__id__in']);
		self::assertArrayNotHasKey('tags__id__in', $this->requests[1][1]['query']);
		self::assertSame([
			'recent-documents-' . hash('sha256', 'https://paperless.example.com') . '-tags-8-9',
			'recent-documents-' . hash('sha256', 'https://paperless.example.com'),
		], array_keys($this->cache->values()));
	}

	/**
	 * A page after the first holds the next older documents, and a page names whether
	 * Paperless has a further one; each page of each filter has its own key.
	 */
	public function testAPageFurtherHasAKeyOfItsOwn(): void {
		$more = $this->answer(200, (string)json_encode(['count' => 30, 'next' => 'https://paperless.example.com/api/documents/?page=2', 'results' => [self::DOCUMENTS[0]]]));
		$recent = $this->recent($this->paperless($more, $this->documents([self::DOCUMENTS[1]])));

		self::assertSame(['documents' => [self::DOCUMENTS[0]], 'more' => true], $recent->find());
		self::assertSame(['documents' => [self::DOCUMENTS[1]], 'more' => false], $recent->find(page: 2));
		self::assertSame(['documents' => [self::DOCUMENTS[0]], 'more' => true], $recent->find());
		self::assertSame(['documents' => [self::DOCUMENTS[1]], 'more' => false], $recent->find(page: 2));
		self::assertCount(2, $this->requests);
		self::assertSame(1, $this->requests[0][1]['query']['page']);
		self::assertSame(2, $this->requests[1][1]['query']['page']);
		self::assertSame([
			'recent-documents-' . hash('sha256', 'https://paperless.example.com'),
			'recent-documents-' . hash('sha256', 'https://paperless.example.com') . '-page-2',
		], array_keys($this->cache->values()));
	}

	/**
	 * For a user whose own files stand for the documents of some users of Paperless
	 * (decision 0013), the dashboard asks for the newest documents of these owners, which the
	 * cache keeps apart for every user with the same owners.
	 */
	public function testTheDocumentsOfSomeOwnersAreKeptApart(): void {
		$recent = $this->recent($this->paperless($this->documents([self::DOCUMENTS[1]])));

		self::assertSame(['documents' => [self::DOCUMENTS[1]], 'more' => false], $recent->findOwnedBy([5, 7]));
		self::assertSame(['documents' => [self::DOCUMENTS[1]], 'more' => false], $recent->findOwnedBy([5, 7]));
		self::assertCount(1, $this->requests);
		self::assertSame('5,7', $this->requests[0][1]['query']['owner__id__in']);
		self::assertArrayNotHasKey('tags__id__in', $this->requests[0][1]['query']);
		self::assertSame(['recent-documents-' . hash('sha256', 'https://paperless.example.com') . '-owners-5-7'], array_keys($this->cache->values()));
	}

	/**
	 * A page that Paperless doesn't send rests for a minute on its own: the first page of the
	 * same documents still counts.
	 */
	public function testAPageThatFailsRestsAlone(): void {
		$client = $this->paperless($this->documents(self::DOCUMENTS), $this->answer(500, '<h1>Server Error (500)</h1>'));

		self::assertSame(self::PAGE, $this->recent($client)->find());
		self::assertNull($this->recent($client)->find(page: 2));
		self::assertNull($this->recent($client)->find(page: 2));
		self::assertSame(self::PAGE, $this->recent($client)->find());
		self::assertCount(2, $this->requests);
		self::assertSame([
			'recent-documents-' . hash('sha256', 'https://paperless.example.com'),
			'recent-documents-resting-' . hash('sha256', 'https://paperless.example.com') . '-page-2',
		], array_keys($this->cache->values()));
	}

	/**
	 * A page past the last one, which Paperless refuses with 404 once documents went after
	 * the page before it was cached, ends the list: no warning, and no rest of the page.
	 */
	public function testAPagePastTheLastEndsTheList(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$client = $this->paperless($this->answer(404, '{"detail":"Invalid page."}'));

		self::assertSame(['documents' => [], 'more' => false], $this->recent($client, $logger)->find(page: 2));
		self::assertSame(['documents' => [], 'more' => false], $this->recent($client, $logger)->find(page: 2));
		self::assertCount(1, $this->requests);
		self::assertSame(['recent-documents-' . hash('sha256', 'https://paperless.example.com') . '-page-2'], array_keys($this->cache->values()));
	}

	/**
	 * The first page that Paperless refuses with 404 is no end of a list: Paperless isn't
	 * where the settings say.
	 */
	public function testAFirstPageNotFoundRests(): void {
		$client = $this->paperless($this->answer(404, '<h1>Not Found</h1>'));

		self::assertNull($this->recent($client)->find());
		self::assertSame(['recent-documents-resting-' . hash('sha256', 'https://paperless.example.com')], array_keys($this->cache->values()));
	}

	/**
	 * Every request to Paperless carries the token of the settings, so the newest documents
	 * are the same for every user: within a minute, the dashboards of all users cost one
	 * request.
	 */
	public function testTheDocumentsOfAMinuteCostOneRequestForAllUsers(): void {
		$client = $this->paperless($this->documents(self::DOCUMENTS), $this->documents([self::DOCUMENTS[1]]));

		self::assertSame(self::PAGE, $this->recent($client)->find());
		$this->time += 59;
		self::assertSame(self::PAGE, $this->recent($client)->find());
		self::assertSame(self::PAGE, $this->recent($client)->find());
		self::assertCount(1, $this->requests);

		$this->time += 1;
		self::assertSame(['documents' => [self::DOCUMENTS[1]], 'more' => false], $this->recent($client)->find());
		self::assertCount(2, $this->requests);
	}

	public function testAnotherPaperlessHasDocumentsOfItsOwn(): void {
		$client = $this->paperless($this->documents(self::DOCUMENTS), $this->documents([]));

		self::assertSame(self::PAGE, $this->recent($client)->find());
		$this->connected(url: 'https://archive.example.org');
		self::assertSame(['documents' => [], 'more' => false], $this->recent($client)->find());
		self::assertSame(['documents' => [], 'more' => false], $this->recent($client)->find());
		self::assertCount(2, $this->requests);
		self::assertSame('https://archive.example.org/api/documents/', $this->requests[1][0]);
	}

	/**
	 * A Paperless that sends no documents rests for a minute, so that no dashboard waits for
	 * it again meanwhile, and the log names the reason.
	 *
	 * @return array<string, array{Closure(self): (IResponse|Throwable), string}>
	 */
	public static function failures(): array {
		return [
			'an error of Paperless' => [
				static fn (self $test): IResponse => $test->answer(500, '<h1>Server Error (500)</h1>'),
				'Paperless returned HTTP 500.',
			],
			'a refused token' => [
				static fn (self $test): IResponse => $test->answer(401, '{"detail":"Invalid token."}'),
				'Paperless returned HTTP 401.',
			],
			'no answer' => [
				static fn (): Throwable => new RuntimeException('cURL error 28: Operation timed out'),
				RuntimeException::class,
			],
		];
	}

	/**
	 * @param Closure(self): (IResponse|Throwable) $failure
	 */
	#[DataProvider('failures')]
	public function testAPaperlessThatSendsNoDocumentsRestsForAMinute(Closure $failure, string $error): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless sent the dashboard no newest documents ({error})', [
				'app' => AppConstants::APP_ID,
				'error' => $error,
			]);
		$client = $this->paperless($failure($this), $this->documents(self::DOCUMENTS));

		self::assertNull($this->recent($client, $logger)->find());
		self::assertSame(['recent-documents-resting-' . hash('sha256', 'https://paperless.example.com')], array_keys($this->cache->values()));
		$this->time += 59;
		self::assertNull($this->recent($client, $logger)->find());
		self::assertCount(1, $this->requests);

		$this->time += 1;
		self::assertSame(self::PAGE, $this->recent($client, $logger)->find());
		self::assertCount(2, $this->requests);
	}

	/**
	 * The request gets one try, unlike a search: the dashboard waits for it.
	 */
	public function testARequestWithoutAnswerGetsNoSecondTry(): void {
		$client = $this->paperless(new RuntimeException('cURL error 7: Failed to connect'));

		self::assertNull($this->recent($client)->find());
		self::assertCount(1, $this->requests);
		self::assertSame(5, $this->requests[0][1]['timeout']);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function strangeCacheValues(): array {
		return [
			'text' => ['documents'],
			'a map' => [['first' => ['id' => 1]]],
			'a list of something else' => [[['id' => 1], 'id 2']],
			'the documents alone, as an older version of the app kept them' => [self::DOCUMENTS],
			'a page without more' => [['documents' => self::DOCUMENTS]],
			'a page with more as text' => [['documents' => self::DOCUMENTS, 'more' => 'no']],
			'a page of text' => [['documents' => 'none', 'more' => false]],
			'a page of a map' => [['documents' => ['first' => ['id' => 1]], 'more' => false]],
			'a page of something else' => [['documents' => [['id' => 1], 'id 2'], 'more' => false]],
		];
	}

	/**
	 * What the cache holds under the key is used only when it is a page of documents, not
	 * such as the list of documents alone that an older version of the app left there.
	 */
	#[DataProvider('strangeCacheValues')]
	public function testAnythingElseInTheCacheAsksPaperlessAgain(mixed $value): void {
		$this->cache->set('recent-documents-' . hash('sha256', 'https://paperless.example.com'), $value, 60);

		self::assertSame(self::PAGE, $this->recent($this->paperless($this->documents(self::DOCUMENTS)))->find());
		self::assertCount(1, $this->requests);
	}

	private function recent(IClient $client, ?LoggerInterface $logger = null): RecentDocuments {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects(self::atMost(1))->method('createDistributed')->with(AppConstants::APP_ID)->willReturn($this->cache);
		$configService = $this->configService($this->accounts('archive'));

		return new RecentDocuments(
			$this->paperlessApi($configService, $client),
			$configService,
			$cacheFactory,
			$logger ?? $this->createStub(LoggerInterface::class),
		);
	}
}
