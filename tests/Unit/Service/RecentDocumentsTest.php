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

		self::assertSame(self::DOCUMENTS, $recent->find());
		self::assertCount(1, $this->requests);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://paperless.example.com/api/documents/', $url);
		self::assertSame([
			'ordering' => '-added',
			'page' => 1,
			'page_size' => RecentDocuments::COUNT,
			'fields' => 'id,title,added,correspondent,document_type,tags',
		], $options['query']);
		self::assertSame(25, RecentDocuments::COUNT);
		// The documents of each Paperless under a key of its own.
		self::assertSame(['recent-documents-' . hash('sha256', 'https://paperless.example.com')], array_keys($this->cache->values()));
	}

	/**
	 * Every request to Paperless carries the token of the settings, so the newest documents
	 * are the same for every user: within a minute, the dashboards of all users cost one
	 * request.
	 */
	public function testTheDocumentsOfAMinuteCostOneRequestForAllUsers(): void {
		$client = $this->paperless($this->documents(self::DOCUMENTS), $this->documents([self::DOCUMENTS[1]]));

		self::assertSame(self::DOCUMENTS, $this->recent($client)->find());
		$this->time += 59;
		self::assertSame(self::DOCUMENTS, $this->recent($client)->find());
		self::assertSame(self::DOCUMENTS, $this->recent($client)->find());
		self::assertCount(1, $this->requests);

		$this->time += 1;
		self::assertSame([self::DOCUMENTS[1]], $this->recent($client)->find());
		self::assertCount(2, $this->requests);
	}

	public function testAnotherPaperlessHasDocumentsOfItsOwn(): void {
		$client = $this->paperless($this->documents(self::DOCUMENTS), $this->documents([]));

		self::assertSame(self::DOCUMENTS, $this->recent($client)->find());
		$this->connected(url: 'https://archive.example.org');
		self::assertSame([], $this->recent($client)->find());
		self::assertSame([], $this->recent($client)->find());
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
		self::assertSame(self::DOCUMENTS, $this->recent($client, $logger)->find());
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
		];
	}

	/**
	 * What the cache holds under the key is used only when it is a list of documents, such
	 * as one that an older version of the app left there.
	 */
	#[DataProvider('strangeCacheValues')]
	public function testAnythingElseInTheCacheAsksPaperlessAgain(mixed $value): void {
		$this->cache->set('recent-documents-' . hash('sha256', 'https://paperless.example.com'), $value, 60);

		self::assertSame(self::DOCUMENTS, $this->recent($this->paperless($this->documents(self::DOCUMENTS)))->find());
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
