<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Model\Explanation;
use OCA\PaperlessUnifiedSearch\Model\Verdict;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\SearchExplainer;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Owners;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Why a document shows to jamie or not, when the account archive owns the synchronized
 * files and has shared some of them with jamie.
 */
final class SearchExplainerTest extends TestCase {
	use Accounts;
	use AppServices;
	use Files;
	use Owners;

	public function testNamesTheReasonOfEachDocument(): void {
		$this->file('/archive/files/Paperless/Electricity [P412].pdf');
		$this->file('/jamie/files/Electricity [P412].pdf');
		$this->file('/archive/files/Paperless/Insurance [P389].pdf');
		$this->file('/jamie/files/Copy [P371].pdf', 'sam');

		[$explanations, $error] = $this->explainer()->explain($this->user('jamie'), [412, 389, 371, 360], 'archive');

		self::assertSame([
			['id' => 412, 'verdict' => 'shown', 'path' => '/Electricity [P412].pdf', 'owner' => 'archive'],
			['id' => 389, 'verdict' => 'not_shared', 'path' => '/Paperless/Insurance [P389].pdf', 'owner' => 'archive'],
			['id' => 371, 'verdict' => 'not_owned', 'path' => '/Copy [P371].pdf', 'owner' => 'sam'],
			['id' => 360, 'verdict' => 'no_file', 'path' => '', 'owner' => ''],
		], self::serialized($explanations));
		self::assertNull($error);
		// One search of the file cache for each step: jamie's files of the archive account,
		// the archive account's own files, and jamie's files of any owner.
		self::assertCount(3, $this->searches);
	}

	/**
	 * When Paperless Sync gives each owner an archive (decision 0013), jamie's own file of a
	 * document of jamie's shows; sam's file of one of sam's isn't shared with jamie, or only
	 * with write permission; and jamie's file renamed after a document of sam belongs to
	 * another user than its owner, whose user has none.
	 */
	public function testNamesTheFilesOfTheUserOfTheOwnerOfEachDocument(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->file('/jamie/files/Paperless/Letter [P412].pdf', 'jamie');
		$this->file('/sam/files/Paperless/Insurance [P389].pdf', 'sam');
		$this->file('/jamie/files/Renamed [P371].pdf', 'jamie');
		$this->file('/jamie/files/Inbox/Shared [P360].pdf', 'sam', writable: true);
		$documents = [['id' => 412, 'owner' => 5], ['id' => 389, 'owner' => 6], ['id' => 371, 'owner' => 6], ['id' => 360, 'owner' => 6], ['id' => 350, 'owner' => 6], ['id' => 340, 'owner' => null]];

		[$explanations, $error] = $this->explainer($this->users())->explain($this->user('jamie'), [412, 389, 371, 360, 350, 340], 'archive', $documents);

		self::assertSame([
			['id' => 412, 'verdict' => 'shown', 'path' => '/Paperless/Letter [P412].pdf', 'owner' => 'jamie'],
			['id' => 389, 'verdict' => 'not_shared', 'path' => '/Paperless/Insurance [P389].pdf', 'owner' => 'sam'],
			['id' => 371, 'verdict' => 'other_owner', 'path' => '/Renamed [P371].pdf', 'owner' => 'jamie'],
			['id' => 360, 'verdict' => 'writable', 'path' => '/Inbox/Shared [P360].pdf', 'owner' => 'sam'],
			['id' => 350, 'verdict' => 'no_file', 'path' => '', 'owner' => ''],
			['id' => 340, 'verdict' => 'no_file', 'path' => '', 'owner' => ''],
		], self::serialized($explanations));
		self::assertNull($error);
	}

	/**
	 * The verdicts of the search count the files of the owners too, and so does the user of
	 * an owner who looks for the documents of another.
	 */
	public function testTheVerdictsCountTheFilesOfTheUserOfTheOwner(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->file('/jamie/files/Letter [P412].pdf', 'jamie');
		$this->file('/sam/files/Letter [P412].pdf', 'jamie');
		$this->file('/sam/files/Renamed [P371].pdf', 'sam');
		$documents = [['id' => 412, 'owner' => 5], ['id' => 371, 'owner' => 5]];

		[$jamie] = $this->explainer($this->users())->verdicts($this->user('jamie'), [412, 371], 'archive', $documents);
		[$sam] = $this->explainer($this->users())->explain($this->user('sam'), [412, 371], 'archive', $documents);

		self::assertSame(['shown', 'hidden'], array_map(static fn (Explanation $explanation): string => $explanation->verdict->value, $jamie));
		self::assertSame(['shown', 'other_owner'], array_map(static fn (Explanation $explanation): string => $explanation->verdict->value, $sam));
	}

	/**
	 * A file of the archive account that isn't shared is what to correct, also when the
	 * user has a copy of it of their own.
	 */
	public function testAnArchivedFileWinsOverACopyOfTheUser(): void {
		$this->file('/archive/files/Paperless/Insurance [P389].pdf');
		$this->file('/jamie/files/Insurance [P389].pdf', 'jamie');

		[$explanations] = $this->explainer()->explain($this->user('jamie'), [389], 'archive');

		self::assertSame(Verdict::NotShared, $explanations[0]->verdict);
	}

	/**
	 * A file of the archive account that the user may change, or in a folder where the
	 * user may add files, stands for no document: the share is what to correct, before
	 * the file of the archive account in its own folders, in the same search as the
	 * files that show.
	 */
	public function testAFileThatTheUserMayWriteIsSharedWithWritePermission(): void {
		$this->file('/archive/files/Paperless/Insurance [P389].pdf');
		$this->file('/jamie/files/Upload/Insurance [P389].pdf', writable: true);
		$this->file('/jamie/files/Electricity [P412].pdf');

		[$explanations, $error] = $this->explainer()->explain($this->user('jamie'), [412, 389], 'archive');

		self::assertSame([
			['id' => 412, 'verdict' => 'shown', 'path' => '/Electricity [P412].pdf', 'owner' => 'archive'],
			['id' => 389, 'verdict' => 'writable', 'path' => '/Upload/Insurance [P389].pdf', 'owner' => 'archive'],
		], self::serialized($explanations));
		self::assertNull($error);
		self::assertCount(1, $this->searches);

		[$verdicts] = $this->explainer()->verdicts($this->user('jamie'), [412, 389], 'archive');

		self::assertSame(['shown', 'hidden'], array_map(static fn (Explanation $explanation): string => $explanation->verdict->value, $verdicts));
	}

	/**
	 * The archive account sees all of its files itself: what it doesn't see has no file.
	 */
	public function testTheArchiveAccountItselfSeesEveryFileOfItsOwn(): void {
		$this->file('/archive/files/Paperless/Insurance [P389].pdf');

		[$explanations] = $this->explainer()->explain($this->user('archive'), [389, 360], 'archive');

		self::assertSame(['shown', 'no_file'], array_map(static fn (Explanation $explanation): string => $explanation->verdict->value, $explanations));
		self::assertCount(2, $this->searches);
	}

	public function testExplainsEachDocumentOnceAndOnlyThoseWithAnId(): void {
		[$explanations] = $this->explainer()->explain($this->user('jamie'), [7, 0, -3, 7], 'archive');

		self::assertSame([7], array_map(static fn (Explanation $explanation): int => $explanation->documentId, $explanations));
	}

	public function testWithoutAnArchiveAccountOnlyFilesOfOthersRemain(): void {
		$this->file('/jamie/files/Copy [P371].pdf', null);

		foreach (['', 'gone'] as $archiveOwner) {
			[$explanations] = $this->explainer()->explain($this->user('jamie'), [371, 360], $archiveOwner);

			self::assertSame([
				['id' => 371, 'verdict' => 'not_owned', 'path' => '/Copy [P371].pdf', 'owner' => ''],
				['id' => 360, 'verdict' => 'no_file', 'path' => '', 'owner' => ''],
			], self::serialized($explanations));
		}
	}

	/**
	 * A file that fails to answer leaves its document without a file, which the verdict
	 * can't tell from one that has none: the error says that it may be one of them.
	 */
	public function testPassesOnTheErrorOfAFileThatFailsToAnswer(): void {
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Letter [P7].pdf');
		$broken->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));
		$this->visible['archive'] = [$broken];

		[$explanations, $error] = $this->explainer()->explain($this->user('jamie'), [7], 'archive');

		self::assertSame(Verdict::NoFile, $explanations[0]->verdict);
		self::assertInstanceOf(RuntimeException::class, $error);
	}

	/**
	 * A file whose path or owner fails to answer once it was found still explains its
	 * document, without them.
	 */
	public function testAFileWithoutPathOrOwnerStillExplainsItsDocument(): void {
		$owner = $this->user('archive');
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn('Letter [P7].pdf');
		$file->method('getPath')->willReturnCallback(self::onlyOnce('/jamie/files/Letter [P7].pdf'));
		$file->method('getOwner')->willReturnCallback(self::onlyOnce($owner));
		$file->method('getParent')->willReturn($this->createStub(Folder::class));
		$this->visible['jamie'] = [$file];

		[$explanations] = $this->explainer()->explain($this->user('jamie'), [7], 'archive');

		self::assertSame(['id' => 7, 'verdict' => 'shown', 'path' => '', 'owner' => ''], $explanations[0]->jsonSerialize());
	}

	/**
	 * An answer that a file gives once, and fails to give after that.
	 *
	 * @template T
	 * @param T $answer
	 * @return callable(): T
	 */
	private static function onlyOnce(mixed $answer): callable {
		$calls = 0;

		return static function () use ($answer, &$calls): mixed {
			if (++$calls > 1) {
				throw new RuntimeException('Storage unavailable');
			}

			return $answer;
		};
	}

	/**
	 * Without --explain, occ shows what the search decides, in one search of the file cache.
	 */
	public function testTheVerdictsOfTheSearchTakeOneSearch(): void {
		$this->file('/jamie/files/Electricity [P412].pdf');
		$this->file('/archive/files/Paperless/Insurance [P389].pdf');

		[$explanations, $error] = $this->explainer()->verdicts($this->user('jamie'), [412, 389, 0], 'archive');

		self::assertSame([
			['id' => 412, 'verdict' => 'shown', 'path' => '/Electricity [P412].pdf', 'owner' => 'archive'],
			['id' => 389, 'verdict' => 'hidden', 'path' => '', 'owner' => ''],
		], self::serialized($explanations));
		self::assertNull($error);
		self::assertCount(1, $this->searches);
	}

	/**
	 * The users of Paperless: jamie and sam.
	 */
	private function users(): IResponse {
		return $this->answer(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie'], ['id' => 6, 'username' => 'sam']]]));
	}

	/**
	 * The explainer, with a Paperless that answers the request for its users with $users.
	 */
	private function explainer(?IResponse $users = null): SearchExplainer {
		$accounts = $this->accounts('archive', 'jamie', 'sam', 'alex');
		$configService = $this->configService($accounts);
		$paperlessApi = $this->paperlessApi($configService, $this->paperless(...($users === null ? [] : [$users])));

		return new SearchExplainer(
			new NextcloudFileLocator($this->rootFolder(), $this->createStub(LoggerInterface::class)),
			$accounts,
			$this->documentOwners($paperlessApi, $configService, $accounts),
		);
	}

	/**
	 * @param list<Explanation> $explanations
	 * @return list<array{id: int, verdict: string, path: string, owner: string}>
	 */
	private static function serialized(array $explanations): array {
		return array_map(static fn (Explanation $explanation): array => $explanation->jsonSerialize(), $explanations);
	}
}
