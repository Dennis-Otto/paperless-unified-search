<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Model\ArchiveShare;
use OCA\PaperlessUnifiedSearch\Model\ShareOverview;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Shares;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * With whom the archive account archive shares its files and folders, which decides who
 * finds which document, and which of its shares let their recipients give a file the
 * marker of any document.
 */
final class ArchiveSharesTest extends TestCase {
	use Shares;

	/** Every permission of a share of a folder: read, update, create, delete and share. */
	private const ALL = Constants::PERMISSION_ALL;

	public function testListsTheSharesByPathKindAndRecipient(): void {
		$this->share(IShare::TYPE_LINK, 'token', '/Paperless');
		$this->share(IShare::TYPE_GROUP, 'staff', '/Paperless');
		$this->share(IShare::TYPE_USER, 'sam', '/Paperless', self::ALL);
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless');
		$this->share(IShare::TYPE_CIRCLE, 'c1rcl3', '/Paperless/2026', displayName: 'Accounting');
		$this->share(IShare::TYPE_ROOM, 'r00m', '/Inbox', Constants::PERMISSION_CREATE, displayName: '');
		$this->share(IShare::TYPE_EMAIL, 'jamie@example.com', '/Inbox/Receipt [P7].pdf');

		$overview = $this->archiveShares()->of('archive');

		self::assertSame([
			['other', 'r00m', '/Inbox', Constants::PERMISSION_CREATE],
			['link', '', '/Inbox/Receipt [P7].pdf', Constants::PERMISSION_READ],
			['user', 'jamie', '/Paperless', Constants::PERMISSION_READ],
			['user', 'sam', '/Paperless', self::ALL],
			['group', 'staff', '/Paperless', Constants::PERMISSION_READ],
			['link', '', '/Paperless', Constants::PERMISSION_READ],
			['team', 'Accounting', '/Paperless/2026', Constants::PERMISSION_READ],
		], self::listed($overview));
		self::assertNull($overview->error);
	}

	/**
	 * Every app that shares files has a type of its own, and the list asks for each.
	 */
	public function testAsksForTheSharesOfEveryKind(): void {
		$this->archiveShares()->of('archive');

		self::assertSame([
			IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_CIRCLE, IShare::TYPE_ROOM, IShare::TYPE_DECK,
			IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP, IShare::TYPE_LINK, IShare::TYPE_EMAIL,
		], $this->askedForShares);
		self::assertSame(['archive'], $this->askedForSharesOf);
	}

	/**
	 * A share of another account's file that the archive account passed on decides
	 * nothing about the archive; an expired share and one of a file that no longer exists
	 * grant nothing.
	 */
	public function testLeavesOutSharesOfOthersExpiredOnesAndThoseOfDeletedFiles(): void {
		$this->share(IShare::TYPE_USER, 'jamie', '/Shared by sam', owner: 'sam');
		$this->share(IShare::TYPE_USER, 'jamie', '/Expired', expires: self::NOW);
		$this->share(IShare::TYPE_USER, 'jamie', '/Expires', expires: self::NOW + 1);
		$deleted = $this->createStub(IShare::class);
		$deleted->method('getShareOwner')->willReturn('archive');
		$deleted->method('getNode')->willThrowException(new NotFoundException('Gone'));
		$this->shares[IShare::TYPE_USER][] = $deleted;

		$overview = $this->archiveShares()->of('archive');

		self::assertSame([['user', 'jamie', '/Expires', Constants::PERMISSION_READ]], self::listed($overview));
		self::assertNull($overview->error);
	}

	/**
	 * A share of a file outside the folders of the archive account, as Nextcloud may keep
	 * one, goes by the name of the file.
	 */
	public function testAShareOutsideTheFoldersOfTheArchiveGoesByItsName(): void {
		$node = $this->createStub(Folder::class);
		$node->method('getPath')->willReturn('/archive/Paperless');
		$node->method('getName')->willReturn('Paperless');
		$share = $this->createStub(IShare::class);
		$share->method('getShareOwner')->willReturn('archive');
		$share->method('getSharedWith')->willReturn('jamie');
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getNode')->willReturn($node);
		$this->shares[IShare::TYPE_USER][] = $share;

		self::assertSame([['user', 'jamie', '/Paperless', Constants::PERMISSION_READ]], self::listed($this->archiveShares()->of('archive')));
	}

	/**
	 * A kind of shares whose app fails costs only its own shares, and a share that fails
	 * only itself; the list says that it may lack some.
	 */
	public function testAShareThatFailsCostsOnlyItself(): void {
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless');
		$broken = $this->createStub(IShare::class);
		$broken->method('getShareOwner')->willReturn('archive');
		$broken->method('getNode')->willThrowException(new RuntimeException('Storage unavailable'));
		$this->shares[IShare::TYPE_GROUP][] = $broken;
		$this->share(IShare::TYPE_LINK, 'token', '/Paperless');
		$alsoBroken = $this->createStub(IShare::class);
		$alsoBroken->method('getShareOwner')->willReturn('archive');
		$alsoBroken->method('getNode')->willThrowException(new RuntimeException('Another storage unavailable'));
		$this->shares[IShare::TYPE_LINK][] = $alsoBroken;
		$manager = $this->createStub(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(fn (string $userId, int $type): array => $type === IShare::TYPE_CIRCLE
			? throw new RuntimeException('Teams failed')
			: $this->shares[$type] ?? []);

		$overview = $this->archiveShares(manager: $manager)->of('archive');

		self::assertSame([['user', 'jamie', '/Paperless', Constants::PERMISSION_READ], ['link', '', '/Paperless', Constants::PERMISSION_READ]], self::listed($overview));
		self::assertInstanceOf(RuntimeException::class, $overview->error);
		self::assertSame('Storage unavailable', $overview->error->getMessage());
	}

	public function testWithoutAnArchiveAccountThereAreNoShares(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('getSharesBy');

		$overview = $this->archiveShares(manager: $manager)->of('');

		self::assertSame([], $overview->shares);
		self::assertNull($overview->error);
	}

	/**
	 * @return array<string, array{int, list<string>}>
	 */
	public static function permissions(): array {
		return [
			'read-only' => [Constants::PERMISSION_READ, []],
			'read and share' => [Constants::PERMISSION_READ | Constants::PERMISSION_SHARE, []],
			'everything' => [Constants::PERMISSION_ALL, ['create', 'update', 'delete']],
			'a file drop' => [Constants::PERMISSION_CREATE, ['create']],
			'changes' => [Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, ['update']],
			'deletions' => [Constants::PERMISSION_READ | Constants::PERMISSION_DELETE, ['delete']],
		];
	}

	/**
	 * @param list<string> $changes
	 */
	#[DataProvider('permissions')]
	public function testAShareAllowsChangesWithAnyPermissionButToReadAndShare(int $permissions, array $changes): void {
		$share = new ArchiveShare(ArchiveShare::KIND_USER, 'jamie', '/Paperless', $permissions);

		self::assertSame($changes, $share->changes());
		self::assertSame($changes !== [], $share->allowsChanges());
	}

	/**
	 * @return array<string, array{list<array{int, int}>, string}>
	 */
	public static function summaries(): array {
		return [
			'no share' => [[], 'The archive account shares none of its files, so only it finds its documents.'],
			'one of each, read-only' => [
				[[IShare::TYPE_LINK, 1], [IShare::TYPE_ROOM, 1], [IShare::TYPE_CIRCLE, 1], [IShare::TYPE_GROUP, 1], [IShare::TYPE_USER, 1]],
				'Shared read-only: 1 user, 1 group, 1 team, 1 other share, 1 public link.',
			],
			'several of each, read-only' => [
				[[IShare::TYPE_USER, 1], [IShare::TYPE_USER, 1], [IShare::TYPE_GROUP, 1], [IShare::TYPE_GROUP, 1], [IShare::TYPE_CIRCLE, 1], [IShare::TYPE_CIRCLE, 1], [IShare::TYPE_DECK, 1], [IShare::TYPE_REMOTE, 1], [IShare::TYPE_LINK, 1], [IShare::TYPE_EMAIL, 1]],
				'Shared read-only: 2 users, 2 groups, 2 teams, 2 other shares, 2 public links.',
			],
			'one that allows changes' => [[[IShare::TYPE_GROUP, 1], [IShare::TYPE_USER, Constants::PERMISSION_ALL]], 'Shared: 1 user, 1 group.'],
		];
	}

	/**
	 * The summary counts the recipients, each once however many files they receive, and
	 * each public link.
	 */
	public function testCountsEachRecipientOnceAndEachPublicLink(): void {
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless');
		$this->share(IShare::TYPE_USER, 'jamie', '/Inbox');
		$this->share(IShare::TYPE_GROUP, 'jamie', '/Paperless');
		$this->share(IShare::TYPE_LINK, 'token', '/Paperless');
		$this->share(IShare::TYPE_LINK, 'other token', '/Inbox');
		$archiveShares = $this->archiveShares();

		self::assertSame('Shared read-only: 1 user, 1 group, 2 public links.', $archiveShares->summarize($archiveShares->of('archive')));
	}

	/**
	 * @param list<array{int, int}> $shares the type and the permissions of each share
	 */
	#[DataProvider('summaries')]
	public function testSummarizesTheShares(array $shares, string $summary): void {
		foreach ($shares as $index => [$type, $permissions]) {
			$this->share($type, 'recipient' . $index, '/Paperless', $permissions);
		}
		$archiveShares = $this->archiveShares();

		self::assertSame($summary, $archiveShares->summarize($archiveShares->of('archive')));
	}

	public function testTheSummarySaysWhenTheListMayLackShares(): void {
		$overview = new ShareOverview([new ArchiveShare(ArchiveShare::KIND_USER, 'jamie', '/Paperless', Constants::PERMISSION_READ)], new RuntimeException('Teams failed'));

		self::assertSame('Shared read-only: 1 user. Not every share could be read, so the list may lack some.', $this->archiveShares()->summarize($overview));
	}

	/**
	 * Each share that allows changes says what goes wrong and how to correct it, in the
	 * order of the shares, and the summary counts in the language of the administrator.
	 */
	public function testWarnsOfEachShareThatAllowsChanges(): void {
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless');
		$this->share(IShare::TYPE_USER, 'sam', '/Paperless', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$this->share(IShare::TYPE_GROUP, 'staff', '/Paperless', Constants::PERMISSION_READ | Constants::PERMISSION_CREATE);
		$this->share(IShare::TYPE_CIRCLE, 'c1rcl3', '/Paperless', Constants::PERMISSION_READ | Constants::PERMISSION_DELETE, displayName: 'Accounting');
		$this->share(IShare::TYPE_ROOM, 'r00m', '/Paperless', self::ALL, displayName: 'Office');
		$this->share(IShare::TYPE_LINK, 'token', '/Inbox', Constants::PERMISSION_CREATE);
		$archiveShares = $this->archiveShares($this->writtenTexts('T:'));
		$overview = $archiveShares->of('archive');

		self::assertSame([
			'T:/Inbox has a public link that allows uploads or changes, so whoever has the link can add a file with the marker of any document. Make the link read-only.',
			'T:/Paperless is shared with the user sam with write permission, so the search shows sam none of the documents in it. Share it read-only.',
			'T:/Paperless is shared with the group staff with write permission, so the search shows its members none of the documents in it. Share it read-only.',
			'T:/Paperless is shared with the team Accounting with write permission, so the search shows its members none of the documents in it. Share it read-only.',
			'T:/Paperless is shared with Office with write permission, so the search shows its recipients none of the documents in it. Share it read-only.',
		], $archiveShares->warnings($overview));
		self::assertSame('T:Shared: T:2 users, T:1 group, T:1 team, T:1 other share, T:1 public link.', $archiveShares->summarize($overview));
	}

	/**
	 * @return list<array{string, string, string, int}>
	 */
	private static function listed(ShareOverview $overview): array {
		return array_map(static fn (ArchiveShare $share): array => [$share->kind, $share->recipient, $share->path, $share->permissions], $overview->shares);
	}
}
