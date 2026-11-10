<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Service\UserTags;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The tags that some users may see, from the table Tags that a user may see of Paperless Sync
 * (decision 0015): the lines as Paperless Sync writes and reads them, and the IDs of the tags
 * of Paperless with their names, which the search asks for once in ten minutes, and without
 * which a user with tags meets no document.
 */
final class UserTagsTest extends TestCase {
	use Accounts;
	use AppServices;

	private const NOW = 1794000000;

	/** The time of the test, in seconds, which the cache reads. */
	private int $now = self::NOW;

	private ?MemoryCache $cache = null;

	/** @var list<array{string, string, array<string, mixed>}> what the service logged */
	private array $logged = [];

	/** The tags of Paperless: the inbox, Katja twice, of two owners and in two cases, Household and Taxes. */
	private const TAGS = [['id' => 1, 'name' => 'Inbox'], ['id' => 8, 'name' => 'Katja'], ['id' => 9, 'name' => 'KATJA'], ['id' => 10, 'name' => 'Household'], ['id' => 11, 'name' => 'Taxes']];

	protected function setUp(): void {
		$this->connected();
		$this->settings['paperless_sync.target_user'] = 'archive';
	}

	/**
	 * A line names the user, an equals sign and the tags, separated by commas, as Paperless
	 * Sync saves it: each name once whatever its case, and of two lines of one user, which it
	 * refuses to save, the later one. A line without an equals sign, a user or a tag counts
	 * for nobody, and a user ID counts only as Nextcloud spells it.
	 */
	public function testReadsTheLinesAsPaperlessSyncDoes(): void {
		$this->settings['paperless_sync.user_tags'] = "katja = Katja, Household\r\n\n  sam=Tax 2026 , TAX 2026,\nno equals sign\n = Orphan\nalex =  , \n12345 = Taxes\njules = Über, über, a=b\nkatja = Later";
		$tags = $this->userTags();

		self::assertSame(['katja' => ['Later'], 'sam' => ['Tax 2026'], '12345' => ['Taxes'], 'jules' => ['Über', 'a=b']], $tags->lines());
		self::assertTrue($tags->restricts('katja'));
		self::assertTrue($tags->restricts('12345'));
		self::assertFalse($tags->restricts('Katja'));
		self::assertFalse($tags->restricts('alex'));
		self::assertSame(['Tax 2026'], $tags->namesOf('sam'));
		self::assertSame([], $tags->namesOf('alex'));
		self::assertSame([], UserTags::parse(''));
		self::assertSame([], $this->requests);
	}

	/**
	 * Without a line, as without Paperless Sync, every user meets every document, and
	 * Paperless is never asked for its tags.
	 */
	public function testAUserWithoutTagsSeesEveryDocumentWithoutARequest(): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Katja';
		$tags = $this->userTags();

		self::assertNull($tags->tagIdsOf('dennis'));
		self::assertSame([412, 7, 389], $tags->visibleIds('dennis', [412, 7, 389], [['id' => 412, 'tags' => []]]));
		self::assertSame([], $this->requests);
	}

	/**
	 * The names become the IDs of every tag of Paperless with one of them, whatever the case,
	 * as Paperless Sync compares them: from one request, which the cache keeps for every
	 * search of ten minutes.
	 */
	public function testTheNamesBecomeTheIdsOfEveryTagWithOneOfThemWhateverTheCase(): void {
		$this->settings['paperless_sync.user_tags'] = "katja = katja, household\nsam = Taxes\njules = über";
		$tags = $this->userTags($this->tags([...self::TAGS, ['id' => 12, 'name' => 'ÜBER']]), $this->tags(self::TAGS));

		self::assertSame([8, 9, 10], $tags->tagIdsOf('katja'));
		self::assertSame([11], $tags->tagIdsOf('sam'));
		self::assertSame([12], $tags->tagIdsOf('jules'));
		self::assertCount(1, $this->requests);
		self::assertSame('https://paperless.example.com/api/tags/', $this->requests[0][0]);
		// The tags of each Paperless and token under a key of their own.
		self::assertSame(['tags-' . hash('sha256', 'https://paperless.example.com TEST_VALUE')], array_keys($this->cache()->values()));

		$this->now += 599;
		self::assertSame([8, 9, 10], $tags->tagIdsOf('katja'));
		self::assertCount(1, $this->requests);

		$this->now += 2;
		self::assertSame([8, 9, 10], $tags->tagIdsOf('katja'));
		self::assertCount(2, $this->requests);
	}

	/**
	 * A user with tags meets only the documents whose tags in Paperless hold one of theirs,
	 * whatever a file says: a document without the field tags, or with tags that are no
	 * IDs, holds none, and an ID without its document is none of theirs. The IDs keep the
	 * order of the caller, also an ID that the document sends as text.
	 */
	public function testAUserWithTagsSeesOnlyTheDocumentsWithOneOfThem(): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Katja';
		$tags = $this->userTags($this->tags(self::TAGS));

		self::assertSame([7, 412, 356], $tags->visibleIds('katja', [7, 389, 412, 371, 402, 356, 999], [
			['id' => 412, 'tags' => [1, 9]],
			['id' => '7', 'tags' => [8]],
			['id' => 389, 'tags' => [1, 10]],
			['id' => 371],
			['id' => 402, 'tags' => ['8', '9']],
			['id' => 356, 'tags' => [8], 'owner' => 5],
			['tags' => [8]],
			['id' => 'none', 'tags' => [8]],
		]));
		self::assertTrue(UserTags::carries(['id' => 1], null));
		self::assertFalse(UserTags::carries(['id' => 1, 'tags' => 8], [8]));
	}

	/**
	 * A tag that Paperless doesn't have, after a typo or a rename, matches no document, and a
	 * user without another tag meets none at all, never every document.
	 */
	public function testATagThatPaperlessDoesNotHaveShowsNoDocument(): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Kattja';
		$tags = $this->userTags($this->tags(self::TAGS));

		self::assertSame([], $tags->tagIdsOf('katja'));
		self::assertSame([], $tags->visibleIds('katja', [412], [['id' => 412, 'tags' => [8]]]));
	}

	/**
	 * Paperless refuses its tags to an account that may not view them: the list rests for
	 * ten minutes, the users with tags meet no document meanwhile, and the log says why.
	 */
	public function testTagsThatTheAccountMayNotViewRestForTenMinutes(): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Katja';
		$tags = $this->userTags($this->answer(403, '{"detail":"You do not have permission to perform this action."}'), $this->tags(self::TAGS));

		self::assertSame([], $tags->tagIdsOf('katja'));
		self::assertSame(['warning', 'Paperless gave no tags, so the users with tags meet no document ({error})', ['app' => 'paperless_unified_search', 'error' => 'Paperless returned HTTP 403.']], $this->logged[0]);
		self::assertSame(['tags-resting-' . hash('sha256', 'https://paperless.example.com TEST_VALUE')], array_keys($this->cache()->values()));
		$this->now += 599;
		self::assertSame([], $tags->tagIdsOf('katja'));
		self::assertCount(1, $this->requests);
		$this->now += 2;
		self::assertSame([8, 9], $tags->tagIdsOf('katja'));
	}

	/**
	 * An answer that is no list, or no answer at all, rests for a minute.
	 *
	 * @return array<string, array{?int, string}>
	 */
	public static function failures(): array {
		return [
			'an answer that is no list' => [502, 'Paperless returned HTTP 502.'],
			'no answer' => [null, RuntimeException::class],
		];
	}

	#[DataProvider('failures')]
	public function testAFailureRestsForAMinute(?int $status, string $logged): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Katja';
		$failure = $status === null ? new RuntimeException('cURL error 7: Failed to connect') : $this->answer($status, '<html>Bad gateway</html>');
		$tags = $this->userTags($failure, $this->tags(self::TAGS));

		self::assertSame([], $tags->tagIdsOf('katja'));
		self::assertSame($logged, $this->logged[0][2]['error']);
		$this->now += 59;
		self::assertSame([], $tags->tagIdsOf('katja'));
		$this->now += 2;
		self::assertSame([8, 9], $tags->tagIdsOf('katja'));
		self::assertCount(2, $this->requests);
	}

	/**
	 * A cache that fails costs the users with tags their documents, never more: they meet
	 * none, and the log says so.
	 */
	public function testAFailingCacheShowsNoDocument(): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Katja';
		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willThrowException(new RuntimeException('Redis is gone'));

		self::assertSame([], $this->userTagsWith($cache, [$this->tags(self::TAGS)])->tagIdsOf('katja'));
		self::assertSame(['warning', 'The tags of Paperless could not be read, so the users with tags meet no document ({errorType})', ['app' => 'paperless_unified_search', 'errorType' => RuntimeException::class]], $this->logged[0]);
		self::assertSame([], $this->requests);
	}

	/**
	 * A cache that holds something else than the tags, such as after an update, counts for
	 * nothing: Paperless is asked again.
	 */
	public function testACacheWithoutTagsAsksPaperless(): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Katja';
		$tags = $this->userTags($this->tags(self::TAGS), $this->tags(self::TAGS));
		$tags->tagIdsOf('katja');
		foreach (array_keys($this->cache()->values()) as $key) {
			$this->cache()->set($key, [8 => 'Katja', 9 => 7]);
		}

		self::assertSame([8, 9], $tags->tagIdsOf('katja'));
		self::assertCount(2, $this->requests);
	}

	/**
	 * After a save of the settings or before occ paperless_unified_search:check, the tags of
	 * Paperless and a list that rests count no longer.
	 */
	public function testForgetAsksAgain(): void {
		$this->settings['paperless_sync.user_tags'] = 'katja = Katja';
		$tags = $this->userTags($this->answer(403, '{"detail":"Forbidden"}'), $this->tags(self::TAGS));

		self::assertSame([], $tags->tagIdsOf('katja'));
		$tags->forget();
		self::assertSame([8, 9], $tags->tagIdsOf('katja'));
	}

	/**
	 * A cache that fails to forget only logs: the tags count until they expire.
	 */
	public function testForgetWithAFailingCacheOnlyLogs(): void {
		$cache = $this->createStub(ICache::class);
		$cache->method('remove')->willThrowException(new RuntimeException('Redis is gone'));

		$this->userTagsWith($cache, [])->forget();

		self::assertSame(['warning', 'The tags of Paperless could not be removed from the cache, so they may count as they were for up to ten minutes more ({errorType})', ['app' => 'paperless_unified_search', 'errorType' => RuntimeException::class]], $this->logged[0]);
	}

	/**
	 * The answer of Paperless with these tags, as /api/tags/ lists them.
	 *
	 * @param list<array{id: int, name: string}> $tags
	 */
	private function tags(array $tags): IResponse {
		return $this->answer(200, (string)json_encode(['count' => count($tags), 'next' => null, 'previous' => null, 'results' => $tags]));
	}

	private function cache(): MemoryCache {
		return $this->cache ??= new MemoryCache(fn (): int => $this->now);
	}

	private function userTags(IResponse|Throwable ...$answers): UserTags {
		return $this->userTagsWith(null, $answers);
	}

	/**
	 * @param list<IResponse|Throwable> $answers
	 */
	private function userTagsWith(?ICache $cache, array $answers): UserTags {
		$configService = $this->configService($this->accounts('archive'));
		// One cache for the service, created once.
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects(self::atMost(1))->method('createDistributed')->willReturn($cache ?? $this->cache());
		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
			$this->logged[] = ['warning', $message, $context];
		});

		return new UserTags($configService, $this->paperlessApi($configService, $this->paperless(...$answers)), $cacheFactory, $logger);
	}
}
