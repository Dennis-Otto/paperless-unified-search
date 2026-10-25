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
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Why a document shows to jamie or not, when the account archive owns the synchronized
 * files and has shared some of them with jamie.
 */
final class SearchExplainerTest extends TestCase {
	use Accounts;
	use Files;

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

	private function explainer(): SearchExplainer {
		return new SearchExplainer(
			new NextcloudFileLocator($this->rootFolder(), $this->createStub(LoggerInterface::class)),
			$this->accounts('archive', 'jamie', 'sam'),
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
