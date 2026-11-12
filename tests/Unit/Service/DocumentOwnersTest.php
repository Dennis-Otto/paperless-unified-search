<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\DocumentOwners;
use OCA\PaperlessUnifiedSearch\Service\UserTags;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\Http\Client\IResponse;
use OCP\ICache;
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
			// Paperless doesn't list 99, such as a deleted user, and 8 has no user of Nextcloud:
			// the documents after them still get theirs.
			$owners->of([['id' => 5, 'owner' => 99], ['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 6], ['id' => 3, 'owner' => 8], ['id' => 4, 'owner' => 5]]),
		);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://paperless.example.com/api/users/', $url);
		self::assertSame(['page' => 1, 'page_size' => 1000], $options['query']);
	}

	/**
	 * Paperless Sync gives an owner whom it lets see only the documents with some tags only
	 * their documents with one of them, and the others to the target user (decision 0015):
	 * a file of such an owner stands for nothing else, and nor for a document whose tags
	 * Paperless didn't send. The files of other owners count as before.
	 */
	public function testTheFilesOfAnOwnerWithTagsStandOnlyForTheirDocumentsWithOneOfThem(): void {
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$owners = $this->owners($this->users(self::USERS), $this->tags([['id' => 1, 'name' => 'Inbox'], ['id' => 8, 'name' => 'katja']]));

		self::assertSame(
			[1 => 'jamie', 3 => 'sam'],
			$owners->of([['id' => 1, 'owner' => 5, 'tags' => [1, 8]], ['id' => 2, 'owner' => 5, 'tags' => [1]], ['id' => 4, 'owner' => 5], ['id' => 3, 'owner' => 6, 'tags' => []]]),
		);
		self::assertSame('https://paperless.example.com/api/tags/', $this->requests[1][0]);
	}

	/**
	 * For a user without tags, the search goes on with every document and the user of each
	 * owner, as before.
	 */
	public function testAUserWithoutTagsSeesEveryDocumentWithTheFilesOfTheOwners(): void {
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$owners = $this->owners($this->users(self::USERS));

		self::assertSame(
			[[2, 1], [2 => 'sam']],
			$owners->forUser($this->account('sam'), [2, 1], [['id' => 1, 'owner' => 8], ['id' => 2, 'owner' => 6]]),
		);
	}

	/**
	 * A user with tags meets only the documents with one of them, and while the archive
	 * account is the target user of Paperless Sync, which writes their copies into their own
	 * files, their own files stand for each of them, whoever owns it in Paperless.
	 */
	public function testAUserWithTagsSeesTheDocumentsWithOneOfThemThroughTheirOwnFiles(): void {
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$owners = $this->owners($this->tags([['id' => 8, 'name' => 'Katja']]));

		self::assertSame(
			[[3, 1], [3 => 'jamie', 1 => 'jamie']],
			$owners->forUser($this->account('jamie'), [3, 2, 1], [['id' => 1, 'owner' => 6, 'tags' => [8]], ['id' => 2, 'owner' => 5, 'tags' => []], ['id' => 3, 'tags' => [8]]]),
		);
		self::assertCount(1, $this->requests);
	}

	/**
	 * With another archive account, whose files Paperless Sync doesn't write, the own files of
	 * a user with tags stand for nothing either: only the files of the archive account count,
	 * for the documents with their tags.
	 */
	public function testAUserWithTagsAndAnotherArchiveAccountSeesOnlyItsFiles(): void {
		$this->settings['paperless_unified_search.archive_owner'] = 'sam';
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$owners = $this->ownersWith([$this->tags([['id' => 8, 'name' => 'Katja']])], configAccounts: ['archive', 'sam']);

		self::assertSame([[1], []], $owners->forUser($this->account('jamie'), [1, 2], [['id' => 1, 'owner' => 6, 'tags' => [8]], ['id' => 2, 'owner' => 5]]));
	}

	/**
	 * A save of the settings and occ paperless_unified_search:check forget the tags of
	 * Paperless along with its users.
	 */
	public function testForgettingForgetsTheTagsToo(): void {
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$owners = $this->owners($this->tags([]), $this->tags([['id' => 8, 'name' => 'Katja']]));

		self::assertSame([[], []], $owners->forUser($this->account('jamie'), [1], [['id' => 1, 'tags' => [8]]]));
		$owners->forget();
		self::assertSame([[1], [1 => 'jamie']], $owners->forUser($this->account('jamie'), [1], [['id' => 1, 'tags' => [8]]]));
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
	 * With the documents that each user may view as well, Paperless Sync still gives the
	 * owner of each document a copy, which stands for it by the same rules; the copies of the
	 * users who may only view it don't, since only its permissions in Paperless tell who
	 * they are.
	 */
	public function testWithTheDocumentsThatEachUserMayViewTheCopiesOfTheOwnersCount(): void {
		$this->settings['paperless_sync.ownership'] = 'per-user-shared';

		self::assertSame([1 => 'jamie', 2 => 'sam'], $this->owners($this->users(self::USERS))->of([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 6]]));
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
			'another archive account' => ['per-user', 'jamie'],
			'another archive account, also with the documents that each user may view' => ['per-user-shared', 'jamie'],
		];
	}

	#[DataProvider('otherArchives')]
	public function testOnlyTheArchivesOfEachOwnerHaveUsers(string $ownership, string $archiveOwner): void {
		$this->connected($archiveOwner);
		$this->settings['paperless_sync.ownership'] = $ownership;

		self::assertSame([], $this->owners()->of([['id' => 1, 'owner' => 5]]));
		self::assertSame([], $this->owners()->accountsOf([['id' => 1, 'owner' => 5]]));
		self::assertSame([], $this->requests);
	}

	/**
	 * occ paperless_unified_search:check needs the accounts of the users, and to know when
	 * Paperless named no users, which leaves it unable to look through their files: no
	 * users are none, unknown users are null.
	 */
	public function testTheAccountsOfTheOwnersAreUnknownWithoutTheUsersOfPaperless(): void {
		$accounts = $this->owners($this->users(self::USERS))->accountsOf([['id' => 1, 'owner' => 5], ['id' => 3, 'owner' => 8]]);
		self::assertSame([1], array_keys($accounts ?? []));
		self::assertSame('jamie', $accounts[1]->getUID());

		$this->storedToken = 'OTHER_TEST_VALUE';
		self::assertNull($this->owners($this->answer(403, '{"detail":"You do not have permission to perform this action."}'))->accountsOf([['id' => 1, 'owner' => 5]]));
		self::assertSame([], $this->owners()->accountsOf([['id' => 1, 'owner' => null]]));
		// Users that Paperless refused are unknown, which is no failure to match them.
		self::assertSame(['info'], array_column($this->logged, 0));
	}

	/**
	 * Each owner is looked up once in Nextcloud, however many documents they own: a backend
	 * of users such as LDAP answers each lookup on its own.
	 */
	public function testEachOwnerIsLookedUpOnce(): void {
		$looked = [];
		$accounts = $this->createStub(IUserManager::class);
		$accounts->method('get')->willReturnCallback(function (string $id) use (&$looked): IUser {
			$looked[] = $id;

			return $this->account($id);
		});

		$owners = $this->ownersWith([$this->users(self::USERS)], accounts: $accounts);

		self::assertSame([1 => 'jamie', 2 => 'jamie', 3 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 5], ['id' => 3, 'owner' => 5]]));
		self::assertSame(['jamie'], $looked);
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
	 * Paperless Sync compares the names of the table, of the users of Paperless and of the
	 * users of Nextcloud whatever their case, also beyond ASCII, and a line without a user
	 * of Nextcloud counts for nothing: the user of Paperless keeps their own name.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function namesWhateverTheCase(): array {
		return [
			'a user of Paperless beyond ASCII in another case than the table' => ['jörg = jamie', 'JÖRG', 'jamie'],
			'a line of the table beyond ASCII in another case' => ['JÖRG = jamie', 'jörg', 'jamie'],
			'a user of Nextcloud beyond ASCII in another case' => ['', 'änne', 'ÄNNE'],
			'a line without a user of Nextcloud' => ['jamie =', 'jamie', 'jamie'],
		];
	}

	#[DataProvider('namesWhateverTheCase')]
	public function testTheNamesCountWhateverTheirCase(string $table, string $username, string $user): void {
		$this->settings['paperless_sync.user_map'] = $table;

		self::assertSame([1 => $user], $this->owners($this->users([['id' => 5, 'username' => $username]]))->of([['id' => 1, 'owner' => 5]]));
	}

	/**
	 * A line of the table without a user of Paperless names nobody, also not a user of
	 * Paperless without a name, should an answer of Paperless list one.
	 */
	public function testALineWithoutAUserOfPaperlessNamesNobody(): void {
		$this->settings['paperless_sync.user_map'] = ' = jamie';

		self::assertSame([], $this->owners($this->users([['id' => 5, 'username' => '']]))->of([['id' => 1, 'owner' => 5]]));
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
			'the table, for a name beyond ASCII in another case, and a name' => ['jörg = jamie', [['id' => 5, 'username' => 'JÖRG'], ['id' => 9, 'username' => 'jamie']]],
			'the same name beyond ASCII in another case' => ['', [['id' => 5, 'username' => 'JÖRG'], ['id' => 9, 'username' => 'jörg']]],
			'two names for a user of Nextcloud beyond ASCII in another case' => ['', [['id' => 5, 'username' => 'änne'], ['id' => 9, 'username' => 'Änne']]],
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

		// The document of a user outside the group comes first: the next one still counts.
		self::assertSame([1 => 'jamie'], $owners->of([['id' => 2, 'owner' => 6], ['id' => 1, 'owner' => 5]]));
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
	 * The users that the cache holds for the server and the token of the settings count
	 * without a request, also when another server of Nextcloud that shares the cache put
	 * them there.
	 */
	public function testTheUsersInTheCacheCountWithoutARequest(): void {
		$this->cache()->set('users-' . hash('sha256', 'https://paperless.example.com TEST_VALUE'), [5 => 'sam']);

		self::assertSame([1 => 'sam'], $this->owners($this->users(self::USERS))->of([['id' => 1, 'owner' => 5]]));
		self::assertSame([], $this->requests);
	}

	/**
	 * The service opens the distributed cache once, and so do the tags of Paperless, however
	 * often they read and write it.
	 */
	public function testTheCacheOpensOnce(): void {
		$opened = 0;
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(function () use (&$opened): ICache {
			$opened++;

			return $this->cache();
		});
		$owners = $this->ownersWith([$this->users(self::USERS)], cacheFactory: $cacheFactory);

		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		$owners->forget();
		$owners->forget();

		self::assertSame(2, $opened);
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
		$this->cache()->set('users-' . hash('sha256', 'https://paperless.example.com TEST_VALUE'), $cached);

		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(1, $this->requests);
	}

	/**
	 * An account that may not view users leaves the files of the archive account alone, as
	 * before, and Paperless isn't asked again for ten minutes; a request without answer, or
	 * an answer that isn't a list, rests for a minute.
	 *
	 * The log names the app and the error, or the class of an error without answer.
	 *
	 * @return array<string, array{?Throwable, int, string}>
	 */
	public static function failures(): array {
		return [
			'refused' => [null, 600, 'error'],
			'without answer' => [new RuntimeException('Paperless is gone'), 60, 'errorType'],
		];
	}

	#[DataProvider('failures')]
	public function testAListThatFailsRests(?Throwable $error, int $seconds, string $detail): void {
		$owners = $this->owners($error ?? $this->answer(403, '{"detail":"You do not have permission to perform this action."}'), $this->users(self::USERS));

		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
		$this->now += $seconds - 1;
		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(1, $this->requests);
		$this->now += 2;
		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertSame('info', $this->logged[0][0]);
		self::assertStringContainsString('only the files of the archive account stand for documents', $this->logged[0][1]);
		self::assertSame(['app', $detail], array_keys($this->logged[0][2]));
		self::assertSame(AppConstants::APP_ID, $this->logged[0][2]['app']);
		self::assertIsString($this->logged[0][2][$detail]);
	}

	/**
	 * Another token, such as one of an account that the administrator gave View for Users
	 * after the warning, asks Paperless at once, while the list of the old one still rests.
	 */
	public function testAnotherTokenAsksForTheUsersAtOnce(): void {
		$owners = $this->owners($this->answer(403, '{"detail":"You do not have permission to perform this action."}'));
		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));

		$this->storedToken = 'OTHER_TEST_VALUE';
		$owners = $this->owners($this->users(self::USERS));

		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(2, $this->requests);
	}

	/**
	 * After the administrator gave the account View for Users, a save of the settings with
	 * the same token, or occ paperless_unified_search:check, ends the rest of the refused
	 * list at once (forget()), and so do changed usernames, which the cache would keep for
	 * ten minutes. The users of another token stay.
	 */
	public function testForgettingEndsTheRestOfARefusedListAtOnce(): void {
		$owners = $this->owners($this->answer(403, '{"detail":"You do not have permission to perform this action."}'), $this->users(self::USERS), $this->users([['id' => 5, 'username' => 'sam']]));
		$this->cache()->set('users-other', [5 => 'alex']);

		self::assertSame([], $owners->of([['id' => 1, 'owner' => 5]]));
		$owners->forget();
		self::assertSame([1 => 'jamie'], $owners->of([['id' => 1, 'owner' => 5]]));
		$owners->forget();
		self::assertSame([1 => 'sam'], $owners->of([['id' => 1, 'owner' => 5]]));
		self::assertCount(3, $this->requests);
		self::assertSame([5 => 'alex'], $this->cache()->get('users-other'));
	}

	/**
	 * A cache that fails while it forgets only leaves the users until they expire: the save
	 * of the settings and the check go on.
	 */
	public function testACacheThatFailsToForgetLeavesTheUsers(): void {
		$cache = $this->createStub(ICache::class);
		$cache->method('remove')->willThrowException(new RuntimeException('Redis is gone'));
		$this->cache = null;
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$this->ownersWith([], cacheFactory: $cacheFactory)->forget();

		$context = ['app' => AppConstants::APP_ID, 'errorType' => RuntimeException::class];
		self::assertSame([
			['warning', 'The tags of Paperless could not be removed from the cache, so they may count as they were for up to ten minutes more ({errorType})', $context],
			['warning', 'The users of Paperless could not be removed from the cache, so a refusal of them may count for up to ten minutes more ({errorType})', $context],
		], $this->logged);
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
		self::assertSame(['warning', 'The owners of Paperless documents could not be matched with users of Nextcloud ({errorType})', ['app' => AppConstants::APP_ID, 'errorType' => RuntimeException::class]], $this->logged[0]);
		// occ paperless_unified_search:check learns that the users are unknown.
		self::assertNull($owners->accountsOf([['id' => 1, 'owner' => 5]]));
	}

	/**
	 * The answer of Paperless with these users, as /api/users/ lists them.
	 *
	 * @param list<array{id: int, username: string}> $users
	 */
	private function users(array $users): IResponse {
		return $this->answer(200, (string)json_encode(['count' => count($users), 'next' => null, 'previous' => null, 'results' => $users]));
	}

	/**
	 * The answer of Paperless with these tags, as /api/tags/ lists them.
	 *
	 * @param list<array{id: int, name: string}> $tags
	 */
	private function tags(array $tags): IResponse {
		return $this->answer(200, (string)json_encode(['count' => count($tags), 'next' => null, 'previous' => null, 'results' => $tags]));
	}

	private function account(string $id): IUser {
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn($id);

		return $account;
	}

	private function cache(): MemoryCache {
		return $this->cache ??= new MemoryCache(fn (): int => $this->now);
	}

	private function owners(IResponse|Throwable ...$answers): DocumentOwners {
		return $this->ownersWith($answers);
	}

	/**
	 * @param list<IResponse|Throwable> $answers
	 * @param list<string> $configAccounts the accounts that the settings find
	 */
	private function ownersWith(array $answers, ?IUserManager $accounts = null, ?IGroupManager $groups = null, ?ICacheFactory $cacheFactory = null, array $configAccounts = ['archive']): DocumentOwners {
		// The settings find the archive account among the users of the test, whatever $accounts finds.
		$configService = $this->configService($this->accounts(...$configAccounts));
		$accounts ??= $this->accounts('archive', 'jamie', 'sam', 'alex', 'jörg', 'ÄNNE');
		if ($cacheFactory === null) {
			$cacheFactory = $this->createStub(ICacheFactory::class);
			$cacheFactory->method('createDistributed')->willReturn($this->cache());
		}
		$logger = $this->createStub(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}

		$paperlessApi = $this->paperlessApi($configService, $this->paperless(...$answers));

		return new DocumentOwners(
			$paperlessApi,
			$configService,
			$accounts,
			$groups ?? $this->createStub(IGroupManager::class),
			$cacheFactory,
			$logger,
			new UserTags($configService, $paperlessApi, $cacheFactory, $logger),
		);
	}
}
