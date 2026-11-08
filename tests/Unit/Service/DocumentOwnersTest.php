<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Service\DocumentOwners;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Whom Paperless Sync gives the documents of each owner (decision 0013): the search asks
 * Paperless for the names of its users once in ten minutes and follows the rules of
 * Paperless Sync, which guesses nothing.
 */
final class DocumentOwnersTest extends TestCase {
	use Accounts;
	use AppServices;

	private const NOW = 1794000000;

	/** The time of the test, in seconds, which the cache reads. */
	private int $now = self::NOW;

	private ?MemoryCache $cache = null;

	/** @var list<array{string, string, array<string, mixed>}> what the service logged */
	private array $logged = [];

	/** The users of Paperless: jamie, Sam in another case, alex and a user without a user of Nextcloud. */
	private const USERS = [['id' => 5, 'username' => 'jamie'], ['id' => 6, 'username' => 'Sam'], ['id' => 7, 'username' => 'alex'], ['id' => 8, 'username' => 'paperless-only']];

	protected function setUp(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
	}

	public function testGivesEachDocumentTheUserOfItsOwner(): void {
		$owners = $this->owners($this->users(self::USERS));

		self::assertSame(
			[1 => 'jamie', 2 => 'sam', 4 => 'jamie'],
			// 8 has no user of Nextcloud, and Paperless doesn't list 99, such as a deleted user.
			$owners->of([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 6], ['id' => 3, 'owner' => 8], ['id' => 4, 'owner' => 5], ['id' => 5, 'owner' => 99]]),
		);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://paperless.example.com/api/users/', $url);
		self::assertSame(['page' => 1, 'page_size' => 1000], $options['query']);
	}

	/**
	 * Only a document with an ID and the ID of an owner has a user, and without any such
	 * document Paperless isn't asked.
	 *
	 * @return array<string, array{list<array<string, mixed>>}>
	 */
	public static function documentsWithoutAnOwner(): array {
		return [
			'none' => [[]],
			'no owner' => [[['id' => 1, 'owner' => null]]],
			'no field owner' => [[['id' => 1]]],
			'an owner that is no number' => [[['id' => 1, 'owner' => '5']]],
			'an owner of 0' => [[['id' => 1, 'owner' => 0]]],
			'no ID' => [[['owner' => 5]]],
			'an ID of 0' => [[['id' => 0, 'owner' => 5]]],
		];
	}

	/**
	 * @param list<array<string, mixed>> $documents
	 */
	#[DataProvider('documentsWithoutAnOwner')]
	public function testADocumentWithoutAnOwnerHasNoUser(array $documents): void {
		self::assertSame([], $this->owners()->of($documents));
		self::assertSame([], $this->requests);
	}

	/**
	 * Only while Paperless Sync gives each user the documents that their owner owns, and the
	 * archive account is its target user, do the files of the owners count.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function otherArchives(): array {
		return [
			'the target user, all of them' => ['single', ''],
			'each user, also those they may view' => ['per-user-shared', ''],
			'another archive account' => ['per-user', 'jamie'],
		];
	}

	#[DataProvider('otherArchives')]
	public function testOnlyTheArchivesOfEachOwnerHaveUsers(string $ownership, string $archiveOwner): void {
		$this->connected($archiveOwner);
		$this->settings['paperless_sync.ownership'] = $ownership;

		self::assertSame([], $this->owners()->of([['id' => 1, 'owner' => 5]]));
		self::assertSame([], $this->requests);
	}

	/**
	 * The table "Paperless users with another Nextcloud ID" of Paperless Sync names the user
	 * of Nextcloud for a user of Paperless, whatever the case of either name; a line that is
	 * no mapping counts for nothing, and a user that the table names but Nextcloud doesn't
	 * have gets nothing, as Paperless Sync gives those documents to the target user.
	 */
	public function testTheTableOfPaperlessSyncNamesTheUserOfNextcloud(): void {
		$this->settings['paperless_sync.user_map'] = "JAMIE = sam\n\nnot a line\n = nobody\nalex = deleted\nmore = than = one";
		$owners = $this->owners($this->users([['id' => 5, 'username' => 'jamie'], ['id' => 7, 'username' => 'alex']]));

		self::assertSame([1 => 'sam'], $owners->of([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 7]]));
	}

	/**
	 * By its name, a user of Paperless gets only the user of Nextcloud with the same ID,
	 * whatever its case: not another one that a backend of Nextcloud finds for it.
	 */
	public function testANameGetsOnlyTheUserWithTheSameId(): void {
		$other = $this->createStub(IUser::class);
		$other->method('getUID')->willReturn('john.doe');
		$accounts = $this->createStub(IUserManager::class);
		$accounts->method('get')->willReturn($other);

		$owners = $this->ownersWith([$this->users([['id' => 5, 'username' => 'jdoe']])], accounts: $accounts);

		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
	}

	/**
	 * Two users of Paperless with one user of Nextcloud get neither, as Paperless Sync
	 * doesn't guess which one is meant: by their names in another case, or by the table and
	 * a name, also when the document is of a user that the table doesn't name.
	 *
	 * @return array<string, array{string, list<array{id: int, username: string}>}>
	 */
	public static function twoUsersForOne(): array {
		return [
			'the same name in another case' => ['', [['id' => 5, 'username' => 'jamie'], ['id' => 9, 'username' => 'Jamie']]],
			'the table and a name' => ['jdoe = jamie', [['id' => 5, 'username' => 'jamie'], ['id' => 9, 'username' => 'jdoe']]],
		];
	}

	/**
	 * @param list<array{id: int, username: string}> $users
	 */
	#[DataProvider('twoUsersForOne')]
	public function testTwoUsersOfPaperlessForOneUserOfNextcloudGetNeither(string $table, array $users): void {
		$this->settings['paperless_sync.user_map'] = $table;
		$owners = $this->owners($this->users($users));

		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 9]]));
	}

	/**
	 * With a group in the settings of Paperless Sync, only its members get documents.
	 */
	public function testOnlyMembersOfTheGroupGetDocuments(): void {
		$this->settings['paperless_sync.user_group'] = ' paperless ';
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'jamie' && $group === 'paperless');

		$owners = $this->ownersWith([$this->users(self::USERS)], groups: $groups);

		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 6]]));
	}

	/**
	 * The users of Paperless come from the cache for ten minutes, for every search of that
	 * time, and from Paperless again after them.
	 */
	public function testTheCacheKeepsTheUsersForTenMinutes(): void {
		$owners = $this->owners($this->users(self::USERS), $this->users([['id' => 5, 'username' => 'sam']]));

		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		$this->now += 599;
		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(1, $this->requests);
		$this->now += 2;
		self::assertSame([1 => 'sam'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(2, $this->requests);
	}

	/**
	 * What the cache holds other than the users, such as after an update of the app,
	 * counts for nothing: Paperless is asked again.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function wrongCacheContents(): array {
		return [
			'a text' => ['jamie'],
			'a name that is no text' => [[5 => 5]],
			'an ID that is no number' => [['five' => 'jamie']],
		];
	}

	#[DataProvider('wrongCacheContents')]
	public function testWhatTheCacheHoldsWronglyCountsForNothing(mixed $cached): void {
		$owners = $this->owners($this->users(self::USERS));
		$this->cache()->set('users-' . hash('sha256', 'https://paperless.example.com'), $cached);

		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(1, $this->requests);
	}

	/**
	 * An account that may not view users leaves the files of the archive account alone, as
	 * before, and Paperless isn't asked again for ten minutes; a request without answer, or
	 * an answer that isn't a list, rests for a minute.
	 *
	 * @return array<string, array{IResponse|Throwable|null, int}>
	 */
	public static function failures(): array {
		return [
			'refused' => [null, 600],
			'without answer' => [new RuntimeException('Paperless is gone'), 60],
		];
	}

	#[DataProvider('failures')]
	public function testAListThatFailsRests(?Throwable $error, int $seconds): void {
		$owners = $this->owners($error ?? $this->answer(403, '{"detail":"You do not have permission to perform this action."}'), $this->users(self::USERS));

		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
		$this->now += $seconds - 1;
		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(1, $this->requests);
		$this->now += 2;
		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertSame('info', $this->logged[0][0]);
		self::assertStringContainsString('only the files of the archive account stand for documents', $this->logged[0][1]);
	}

	public function testAnAnswerThatIsNoListRestsForAMinute(): void {
		$owners = $this->owners($this->answer(502, '<html>Bad gateway</html>'), $this->users(self::USERS));

		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
		$this->now += 61;
		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
	}

	/**
	 * A backend of users that fails costs the files of the owners, not the search.
	 */
	public function testAFailingBackendOfUsersLeavesTheArchiveAccount(): void {
		$accounts = $this->createStub(IUserManager::class);
		$accounts->method('get')->willThrowException(new RuntimeException('LDAP is gone'));

		$owners = $this->ownersWith([$this->users(self::USERS)], accounts: $accounts);

		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertSame(['warning', 'The owners of Paperless documents could not be matched with users of Nextcloud ({errorType})'], array_slice($this->logged[0], 0, 2));
		self::assertSame(RuntimeException::class, $this->logged[0][2]['errorType']);
	}

	/**
	 * The answer of Paperless with these users, as /api/users/ lists them.
	 *
	 * @param list<array{id: int, username: string}> $users
	 */
	private function users(array $users): IResponse {
		return $this->answer(200, (string)json_encode(['count' => count($users), 'next' => null, 'previous' => null, 'results' => $users]));
	}

	private function cache(): MemoryCache {
		return $this->cache ??= new MemoryCache(fn (): int => $this->now);
	}

	private function owners(IResponse|Throwable ...$answers): DocumentOwners {
		return $this->ownersWith($answers);
	}

	/**
	 * @param list<IResponse|Throwable> $answers
	 */
	private function ownersWith(array $answers, ?IUserManager $accounts = null, ?IGroupManager $groups = null): DocumentOwners {
		// The settings find the archive account among the users of the test, whatever $accounts finds.
		$configService = $this->configService($this->accounts('archive'));
		$accounts ??= $this->accounts('archive', 'jamie', 'sam', 'alex');
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache());
		$logger = $this->createStub(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}

		return new DocumentOwners(
			$this->paperlessApi($configService, $this->paperless(...$answers)),
			$configService,
			$accounts,
			$groups ?? $this->createStub(IGroupManager::class),
			$cacheFactory,
			$logger,
		);
	}
}
