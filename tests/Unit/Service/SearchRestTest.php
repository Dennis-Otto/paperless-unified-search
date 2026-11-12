<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Service\SearchRest;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class SearchRestTest extends TestCase {
	private const URL = 'https://paperless.example.com';

	/** The time of the cache, in seconds. */
	private int $now = 1791500000;

	private MemoryCache $cache;

	protected function setUp(): void {
		$this->cache = new MemoryCache(fn (): int => $this->now);
	}

	/**
	 * A request without answer makes the search rest for 30 seconds, and no longer.
	 */
	#[DataProvider('failuresWithoutAnswer')]
	public function testAFailureWithoutAnswerRestsForThirtySeconds(Throwable $failure): void {
		$rest = $this->rest();

		$rest->restAfter(self::URL, $failure);

		self::assertTrue($rest->isResting(self::URL));
		$this->now += 29;
		self::assertTrue($rest->isResting(self::URL));
		$this->now += 1;
		self::assertFalse($rest->isResting(self::URL));
	}

	/**
	 * @return array<string, array{Throwable}>
	 */
	public static function failuresWithoutAnswer(): array {
		return [
			'a timeout of cURL' => [new RuntimeException('cURL error 28: Operation timed out')],
			'a refused connection' => [new RuntimeException('cURL error 7: Failed to connect')],
			'a name that does not resolve' => [new RuntimeException('cURL error 6: Could not resolve host: paperless.example.com')],
			'an error of the client without a code of cURL' => [new RuntimeException('Connection reset by peer')],
			'a failure of the app that names a timeout' => [new PaperlessRequestException(RequestFailure::Timeout, 'Paperless took too long.')],
		];
	}

	/**
	 * A failure that Paperless answered, or that costs no wait for it, makes no rest.
	 */
	#[DataProvider('failuresWithoutRest')]
	public function testAFailureThatCostsNoWaitMakesNoRest(Throwable $failure): void {
		$rest = $this->rest();

		$rest->restAfter(self::URL, $failure);

		self::assertFalse($rest->isResting(self::URL));
		self::assertSame([], $this->cache->values());
	}

	/**
	 * @return array<string, array{Throwable}>
	 */
	public static function failuresWithoutRest(): array {
		return [
			'HTTP 500' => [PaperlessRequestException::forStatus(500)],
			'HTTP 401' => [PaperlessRequestException::forStatus(401)],
			'a certificate that Nextcloud does not trust' => [new RuntimeException('cURL error 60: SSL certificate problem')],
			'a failed handshake' => [new RuntimeException('cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL')],
		];
	}

	public function testTheRestBelongsToTheUrlOfItsPaperless(): void {
		$rest = $this->rest();

		$rest->restAfter(self::URL, new RuntimeException('cURL error 28: Operation timed out'));

		self::assertFalse($rest->isResting('https://other.example.com'));
		self::assertSame(['search-resting-' . hash('sha256', self::URL)], array_keys($this->cache->values()));
	}

	/**
	 * A Paperless that answers the test of the connection ends the rest at once.
	 */
	public function testEndingTheRestAsksPaperlessAgainAtOnce(): void {
		$rest = $this->rest();
		$rest->restAfter(self::URL, new RuntimeException('cURL error 28: Operation timed out'));
		$rest->restAfter('https://other.example.com', new RuntimeException('cURL error 28: Operation timed out'));

		$rest->end(self::URL);

		self::assertFalse($rest->isResting(self::URL));
		self::assertTrue($rest->isResting('https://other.example.com'));
	}

	/**
	 * A cache that fails keeps no rest: every search asks Paperless, as without a cache.
	 */
	public function testACacheThatFailsKeepsNoRest(): void {
		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willThrowException(new RuntimeException('Redis server went away'));

		self::assertFalse($this->rest($cache)->isResting(self::URL));
	}

	public function testARestThatTheCacheFailsToKeepIsLogged(): void {
		$cache = $this->createStub(ICache::class);
		$cache->method('set')->willThrowException(new RuntimeException('Redis server went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('The search could not rest after Paperless gave no answer, so the next search asks it again ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
			]);

		$this->rest($cache, $logger)->restAfter(self::URL, new RuntimeException('cURL error 28: Operation timed out'));
	}

	public function testARestThatTheCacheFailsToEndIsLogged(): void {
		$cache = $this->createStub(ICache::class);
		$cache->method('remove')->willThrowException(new RuntimeException('Redis server went away'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('The rest of the search could not be ended, so the search may find nothing for up to 30 seconds more ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
			]);

		$this->rest($cache, $logger)->end(self::URL);
	}

	/**
	 * The rest asks Nextcloud for its distributed cache once, by the ID of the app.
	 */
	public function testTheRestTakesTheDistributedCacheOfTheAppOnce(): void {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects(self::once())->method('createDistributed')->with(AppConstants::APP_ID)->willReturn($this->cache);
		$rest = new SearchRest($cacheFactory, $this->createStub(LoggerInterface::class));

		$rest->restAfter(self::URL, new RuntimeException('cURL error 28: Operation timed out'));

		self::assertTrue($rest->isResting(self::URL));
	}

	private function rest(?ICache $cache = null, ?LoggerInterface $logger = null): SearchRest {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache ?? $this->cache);

		return new SearchRest($cacheFactory, $logger ?? $this->createStub(LoggerInterface::class));
	}
}
