<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchBinaryOperator;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchComparison;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchQuery;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Tests\Unit\FileCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class NextcloudFileLocatorTest extends TestCase {
	use FileCache;

	private const ARCHIVE = 'archive';

	/**
	 * A search of the file cache scans the names of all files that the user can see, which
	 * no index serves: a search per document cost a scan per result.
	 */
	public function testOneSearchFindsTheFilesOfAllDocuments(): void {
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');
		$letter = $this->file('Letter [P7].pdf', '/dennis/files/Paperless/Letter [P7].pdf');
		$unrelated = $this->file('Holiday.jpg', '/dennis/files/Photos/Holiday.jpg');

		$files = $this->locator([$unrelated, $letter, $invoice])->findAllForUser($this->user(), [123, 999, 7], self::ARCHIVE)->files;

		self::assertSame([123 => $invoice, 7 => $letter], $files);
		self::assertCount(1, $this->searches);
	}

	/**
	 * Nextcloud's public API has the interfaces of a query of the file cache only, so the
	 * app brings its own classes, which must answer as Nextcloud's own do.
	 */
	public function testTheSearchAsksForTheMarkersOfTheDocumentsAcrossAllFolders(): void {
		$user = $this->user();

		$this->locator([])->findAllForUser($user, [123, 7, 123, 0, -1], self::ARCHIVE);

		self::assertCount(1, $this->searches);
		$query = $this->searches[0];
		self::assertSame(0, $query->getLimit());
		self::assertSame(0, $query->getOffset());
		self::assertSame([], $query->getOrder());
		self::assertSame($user, $query->getUser());
		self::assertFalse($query->limitToHome());
		self::assertSame([], $query->getSelectFields());

		$operation = $query->getSearchOperation();
		self::assertInstanceOf(ISearchBinaryOperator::class, $operation);
		self::assertSame(ISearchBinaryOperator::OPERATOR_OR, $operation->getType());
		$conditions = [];
		foreach ($operation->getArguments() as $argument) {
			self::assertInstanceOf(ISearchComparison::class, $argument);
			$conditions[] = [$argument->getField(), $argument->getType(), $argument->getValue(), $argument->getExtra()];
		}
		self::assertSame([
			['name', ISearchComparison::COMPARE_LIKE, '%[P123]%', ''],
			['name', ISearchComparison::COMPARE_LIKE, '%[P7]%', ''],
		], $conditions);
	}

	/**
	 * @return array<string, array{list<int>, list<string>, array<int, string>}>
	 */
	public static function markers(): array {
		return [
			'a longer ID' => [[1], ['Invoice [P12].pdf', 'Invoice [P112].pdf'], []],
			'a shorter ID' => [[12], ['Invoice [P1].pdf', 'Invoice [P2].pdf'], []],
			'both IDs' => [[1, 12], ['Invoice [P12].pdf', 'Invoice [P1].pdf'], [1 => 'Invoice [P1].pdf', 12 => 'Invoice [P12].pdf']],
			'a zero in front' => [[12, 7], ['Invoice [P012] [P7].pdf'], [7 => 'Invoice [P012] [P7].pdf']],
			'the markers of two documents' => [[12, 7], ['Invoice [P12] [P7].pdf'], [12 => 'Invoice [P12] [P7].pdf', 7 => 'Invoice [P12] [P7].pdf']],
			'a marker in small letters' => [[123], ['Invoice [p123].pdf'], []],
			'no marker' => [[123], ['Invoice P123.pdf', 'Invoice [P 123].pdf'], []],
		];
	}

	/**
	 * Only the exact marker of a document stands for it.
	 *
	 * @param list<int> $documentIds
	 * @param list<string> $names
	 * @param array<int, string> $expected the name of the file of each document found
	 */
	#[DataProvider('markers')]
	public function testOnlyTheExactMarkerStandsForADocument(array $documentIds, array $names, array $expected): void {
		$nodes = array_map(fn (string $name): File => $this->file($name, '/dennis/files/Paperless/' . $name), $names);

		$files = $this->locator($nodes)->findAllForUser($this->user(), $documentIds, self::ARCHIVE)->files;

		self::assertSame($expected, array_map(static fn (File $file): string => $file->getName(), $files));
	}

	/**
	 * Of several files of a document, the one with the first path stands for it, in
	 * whatever order the file cache finds them.
	 */
	public function testTheFileWithTheFirstPathStandsForTheDocument(): void {
		$first = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/2026/Invoice [P123].pdf');
		$second = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');
		$third = $this->file('Invoice copy [P123].pdf', '/dennis/files/Shared/Invoice copy [P123].pdf');

		foreach ([[$second, $first, $third], [$third, $second, $first], [$first, $third, $second]] as $nodes) {
			self::assertSame([123 => $first], $this->locator($nodes)->findAllForUser($this->user(), [123], self::ARCHIVE)->files);
		}
	}

	/**
	 * @return array<string, array{?string}>
	 */
	public static function otherOwners(): array {
		return [
			'the searching user' => ['dennis'],
			'another account' => ['mallory'],
			'no owner' => [null],
		];
	}

	/**
	 * Anyone can name a file with the marker of a document; only the files of the archive
	 * account stand for it.
	 */
	#[DataProvider('otherOwners')]
	public function testIgnoresFilesThatTheArchiveAccountDoesNotOwn(?string $owner): void {
		$forged = $this->file('Copy [P123].txt', '/dennis/files/Copy [P123].txt', $owner);
		$archived = $this->file('Invoice [P123].pdf', '/dennis/files/Shared/Invoice [P123].pdf');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method(self::anything());

		self::assertSame([123 => $archived], $this->locator([$forged, $archived], $logger)->findAllForUser($this->user(), [123], self::ARCHIVE)->files);
		$found = $this->locator([$forged], $logger)->findAllForUser($this->user(), [123], self::ARCHIVE);
		self::assertSame([], $found->files);
		// A file of no account, as on some external storages, is no file that failed.
		self::assertNull($found->error);
	}

	public function testIgnoresFoldersWithTheMarker(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn('Scans [P123]');
		$folder->method('getPath')->willReturn('/dennis/files/Scans [P123]');

		self::assertSame([], $this->locator([$folder])->findAllForUser($this->user(), [123], self::ARCHIVE)->files);
	}

	/**
	 * Whoever may change a file of the archive account, or add one to its folder, can give
	 * it the marker of any document: such a file proves nothing to that user.
	 *
	 * @return array<string, array{bool, string, bool}>
	 */
	public static function writableFiles(): array {
		return [
			'a file that the user may change' => [true, self::ARCHIVE, false],
			'a file in a folder of the archive account where the user may add files' => [false, self::ARCHIVE, true],
			'both' => [true, self::ARCHIVE, true],
			'a file that the user may change, shared on its own' => [true, 'dennis', true],
		];
	}

	#[DataProvider('writableFiles')]
	public function testAFileThatTheUserMayWriteStandsForNoDocument(bool $updateable, string $folderOwner, bool $creatable): void {
		$forged = $this->file('Copy [P123].pdf', '/dennis/files/Upload/Copy [P123].pdf', updateable: $updateable, folder: $this->parentFolder($folderOwner, $creatable));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method(self::anything());

		$found = $this->locator([$forged], $logger)->findAllForUser($this->user(), [123], self::ARCHIVE);

		self::assertSame([], $found->files);
		self::assertNull($found->error);
		self::assertSame([123 => $forged], $found->writable);
	}

	/**
	 * A file that the user may only read counts, also as a file shared on its own, which
	 * lies in a folder of the user, where every file that the user adds is the user's own.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function readOnlyFiles(): array {
		return [
			'a folder shared read-only' => [self::ARCHIVE, false],
			'a file shared on its own' => ['dennis', true],
			'a file in a folder of no account' => ['', true],
		];
	}

	#[DataProvider('readOnlyFiles')]
	public function testAFileThatTheUserMayOnlyReadStandsForItsDocument(string $folderOwner, bool $creatable): void {
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf', folder: $this->parentFolder($folderOwner, $creatable));

		self::assertSame([123 => $invoice], $this->locator([$invoice])->findAllForUser($this->user(), [123], self::ARCHIVE)->files);
		self::assertSame([], $this->locator([$invoice])->findAllForUser($this->user(), [123], self::ARCHIVE)->writable);
	}

	/**
	 * A share that a recipient passed on with fewer permissions reaches the user read-only,
	 * and its file counts, even when the user may change another file of the document that
	 * comes first.
	 */
	public function testAReadOnlyReshareCountsBeforeAWritableFileOfTheDocument(): void {
		$writable = $this->file('Invoice [P123].pdf', '/dennis/files/Inbox/Invoice [P123].pdf', updateable: true);
		$reshared = $this->file('Invoice [P123].pdf', '/dennis/files/From sam/Invoice [P123].pdf');

		$found = $this->locator([$writable, $reshared])->findAllForUser($this->user(), [123], self::ARCHIVE);

		self::assertSame([123 => $reshared], $found->files);
		self::assertSame([], $found->writable);
	}

	/**
	 * The user may reach one file through two shares, a folder shared read-only and a
	 * folder in it shared with write permission: the file that the user may write counts
	 * through neither, whichever comes first.
	 */
	public function testAFileThatTheUserMayWriteThroughAnotherShareStandsForNoDocument(): void {
		$writable = $this->file('Forged [P999].pdf', '/dennis/files/Inbox/Forged [P999].pdf', updateable: true, id: 42);
		$readOnly = $this->file('Forged [P999].pdf', '/dennis/files/Paperless/Inbox/Forged [P999].pdf', id: 42);

		foreach ([[$writable, $readOnly], [$readOnly, $writable]] as $nodes) {
			$found = $this->locator($nodes)->findAllForUser($this->user(), [999], self::ARCHIVE);

			self::assertSame([], $found->files);
			self::assertSame([999 => $writable], $found->writable);
		}
	}

	/**
	 * A file whose ID fails to answer, once the user may write another file, counts for
	 * neither and costs only itself: another file of its document still counts, and the
	 * log counts it with the files that failed before, whose error comes first.
	 */
	public function testAFileWhoseIdFailsCostsOnlyItself(): void {
		$writable = $this->file('Letter [P7].pdf', '/dennis/files/Inbox/Letter [P7].pdf', updateable: true);
		$failsFirst = $this->createStub(File::class);
		$failsFirst->method('getName')->willReturn('Receipt [P8].pdf');
		$failsFirst->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Invoice [P123].pdf');
		$broken->method('getPath')->willReturn('/dennis/files/Paperless/Invoice [P123].pdf');
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn(self::ARCHIVE);
		$broken->method('getOwner')->willReturn($account);
		$broken->method('getParent')->willReturn($this->parentFolder(self::ARCHIVE, false));
		$broken->method('getId')->willThrowException(new RuntimeException('ID unavailable'));
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Zzz/Invoice [P123].pdf');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search skipped {count} file(s) it could not read ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 2,
				'errorType' => RuntimeException::class,
			]);

		$found = $this->locator([$failsFirst, $broken, $invoice, $writable], $logger)->findAllForUser($this->user(), [7, 8, 123], self::ARCHIVE);

		self::assertSame([123 => $invoice], $found->files);
		self::assertSame([7 => $writable], $found->writable);
		self::assertInstanceOf(RuntimeException::class, $found->error);
		self::assertSame('Storage unavailable', $found->error->getMessage());
	}

	/**
	 * A file shared on its own bears the name that the recipient gives it, even one who may
	 * only read it, so the markers come from the name that the archive account gave it.
	 *
	 * @return array<string, array{?string, array<int, string>}>
	 */
	public static function namesOfTheArchive(): array {
		return [
			'the name of the archive' => ['Invoice [P5].pdf', [5 => 'Invoice [P5] [P999].pdf']],
			'a file that the archive no longer has' => [null, []],
		];
	}

	/**
	 * @param array<int, string> $expected the name of the file found for each document
	 */
	#[DataProvider('namesOfTheArchive')]
	public function testAFileSharedOnItsOwnCountsByTheNameThatTheArchiveGaveIt(?string $nameOfTheArchive, array $expected): void {
		$renamed = $this->file('Invoice [P5] [P999].pdf', '/dennis/files/Invoice [P5] [P999].pdf', id: 42, shareRoot: true);
		$archiveFolder = $this->createStub(Folder::class);
		if ($nameOfTheArchive === null) {
			$archiveFolder->method('getFirstNodeById')->willReturn(null);
		} else {
			$original = $this->file($nameOfTheArchive, '/archive/files/Paperless/' . $nameOfTheArchive, id: 42);
			$archiveFolder->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?Node => $id === 42 ? $original : null);
		}
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (string $uid): Folder => $uid === self::ARCHIVE ? $archiveFolder : $this->userFolder([$renamed]));

		$found = (new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)))->findAllForUser($this->user(), [5, 999], self::ARCHIVE);

		self::assertSame($expected, array_map(static fn (File $file): string => $file->getName(), $found->files));
		self::assertSame([], $found->writable);
		self::assertNull($found->error);
	}

	/**
	 * The archive account writes its own files, and finds them all.
	 */
	public function testTheArchiveAccountFindsTheFilesThatItMayWrite(): void {
		$invoice = $this->file('Invoice [P123].pdf', '/archive/files/Paperless/Invoice [P123].pdf', updateable: true, folder: $this->parentFolder(self::ARCHIVE, true));
		$archive = $this->user(self::ARCHIVE);

		$found = $this->locator([$invoice], uid: self::ARCHIVE)->findAllForUser($archive, [123], self::ARCHIVE);

		self::assertSame([123 => $invoice], $found->files);
		self::assertSame([], $found->writable);
	}

	/**
	 * The search asks the folder of the files once for all of them.
	 */
	public function testAsksEachFolderOnceWhetherTheUserMayAddFiles(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects(self::once())->method('isCreatable')->willReturn(false);
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn(self::ARCHIVE);
		$folder->method('getOwner')->willReturn($account);
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf', folder: $folder);
		$letter = $this->file('Letter [P7].pdf', '/dennis/files/Paperless/Letter [P7].pdf', folder: $folder);

		self::assertSame([123 => $invoice, 7 => $letter], $this->locator([$letter, $invoice])->findAllForUser($this->user(), [123, 7], self::ARCHIVE)->files);
	}

	/**
	 * A file whose permissions fail to answer counts for neither, and costs only its own
	 * document.
	 */
	public function testAFileWhosePermissionsFailCostsOnlyItsOwnDocument(): void {
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Letter [P7].pdf');
		$broken->method('getPath')->willReturn('/dennis/files/Paperless/Letter [P7].pdf');
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn(self::ARCHIVE);
		$broken->method('getOwner')->willReturn($account);
		$broken->method('getParent')->willThrowException(new RuntimeException('Storage unavailable'));
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');

		$found = $this->locator([$broken, $invoice])->findAllForUser($this->user(), [7, 123], self::ARCHIVE);

		self::assertSame([123 => $invoice], $found->files);
		self::assertSame([], $found->writable);
		self::assertInstanceOf(RuntimeException::class, $found->error);
	}

	/**
	 * The file that the user may only read stands for the document; one that the user may
	 * write is left out also of the writable ones, and of several the first path counts.
	 */
	public function testAWritableFileOfADocumentWithAReadOnlyOneIsNoneOfTheWritableOnes(): void {
		$readOnly = $this->file('Invoice [P123].pdf', '/dennis/files/Zzz/Invoice [P123].pdf');
		$writable = $this->file('Invoice [P123].pdf', '/dennis/files/Inbox/Invoice [P123].pdf', updateable: true);
		$second = $this->file('Letter [P7].pdf', '/dennis/files/Upload/Letter [P7].pdf', updateable: true);
		$first = $this->file('Letter [P7].pdf', '/dennis/files/Inbox/Letter [P7].pdf', updateable: true);

		foreach ([[$writable, $readOnly, $second, $first], [$readOnly, $writable, $first, $second]] as $nodes) {
			$found = $this->locator($nodes)->findAllForUser($this->user(), [7, 123], self::ARCHIVE);

			self::assertSame([123 => $readOnly], $found->files);
			self::assertSame([7 => $first], $found->writable);
		}
	}

	/**
	 * occ looks for the files of any owner, whatever the user may do with them.
	 */
	public function testFilesOfAnyOwnerAreFoundWhateverTheUserMayDo(): void {
		$writable = $this->file('Copy [P123].pdf', '/dennis/files/Copy [P123].pdf', 'mallory', updateable: true);

		$found = $this->locator([$writable])->findAnyForUser($this->user(), [123]);

		self::assertSame([123 => $writable], $found->files);
		self::assertSame([], $found->writable);
	}

	/**
	 * @return array<string, array{list<int>, string}>
	 */
	public static function searchesWithoutAnswer(): array {
		return [
			'no documents' => [[], self::ARCHIVE],
			'document IDs below one' => [[0, -123], self::ARCHIVE],
			'no archive account' => [[123], ''],
		];
	}

	/**
	 * @param list<int> $documentIds
	 */
	#[DataProvider('searchesWithoutAnswer')]
	public function testDoesNotSearchWithoutADocumentOrAnArchiveAccount(array $documentIds, string $archiveOwner): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::never())->method('getUserFolder');

		$locator = new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class));

		self::assertSame([], $locator->findAllForUser($this->user(), $documentIds, $archiveOwner)->files);
	}

	/**
	 * A file that fails to answer costs only its own document, and the log says so once,
	 * without the names of the files.
	 */
	public function testAFileThatFailsCostsOnlyItsOwnDocument(): void {
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Letter [P7].pdf');
		$broken->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));
		$alsoBroken = $this->createStub(File::class);
		$alsoBroken->method('getName')->willReturn('Receipt [P8].pdf');
		$alsoBroken->method('getOwner')->willThrowException(new RuntimeException('Another storage unavailable'));
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search skipped {count} file(s) it could not read ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 2,
				'errorType' => RuntimeException::class,
			]);

		$found = $this->locator([$broken, $invoice, $alsoBroken], $logger)->findAllForUser($this->user(), [7, 123, 8], self::ARCHIVE);

		self::assertSame([123 => $invoice], $found->files);
		// The provider notes in the history when such files leave the search empty.
		self::assertInstanceOf(RuntimeException::class, $found->error);
		self::assertSame('Storage unavailable', $found->error->getMessage());
	}

	public function testFilesThatWorkLogNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method(self::anything());

		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');

		$found = $this->locator([$invoice], $logger)->findAllForUser($this->user(), [123], self::ARCHIVE);

		self::assertSame([123 => $invoice], $found->files);
		self::assertNull($found->error);
	}

	/**
	 * The list of the methods that the locator compares with the interfaces of Nextcloud
	 * is the one of the app's classes, so that the locator knows when they lack one.
	 */
	public function testKnowsTheMethodsOfTheClassesOfTheApp(): void {
		$classes = [
			ISearchQuery::class => SearchQuery::class,
			ISearchBinaryOperator::class => SearchBinaryOperator::class,
			ISearchComparison::class => SearchComparison::class,
		];

		self::assertSame(array_keys($classes), array_keys(NextcloudFileLocator::IMPLEMENTED_METHODS));
		foreach ($classes as $interface => $class) {
			$methods = array_values(array_diff(get_class_methods($class), ['__construct', '__toString']));
			self::assertEqualsCanonicalizing($methods, NextcloudFileLocator::IMPLEMENTED_METHODS[$interface], $class);
			self::assertSame([], array_diff(get_class_methods($interface), $methods), $interface);
		}
	}

	/**
	 * @return array<string, array{array<class-string, list<string>>, string}>
	 */
	public static function interfacesThatGrew(): array {
		$methods = NextcloudFileLocator::IMPLEMENTED_METHODS;

		return [
			'a new method' => [
				[...$methods, ISearchQuery::class => array_values(array_diff($methods[ISearchQuery::class], ['getSelectFields']))],
				ISearchQuery::class . '::getSelectFields',
			],
			'a new interface' => [
				[...$methods, 'OCP\\Files\\Search\\ISearchNothing' => []],
				'OCP\\Files\\Search\\ISearchNothing',
			],
			'a new interface and a new method' => [
				['OCP\\Files\\Search\\ISearchNothing' => [], ...$methods, ISearchQuery::class => array_values(array_diff($methods[ISearchQuery::class], ['getSelectFields']))],
				'OCP\\Files\\Search\\ISearchNothing, ' . ISearchQuery::class . '::getSelectFields',
			],
		];
	}

	/**
	 * Nextcloud adds methods to the interfaces of a query of the file cache even in a
	 * maintenance release, and PHP refuses to load a class that lacks one with a fatal
	 * error that no catch sees. So when the app's classes lack a method, the locator
	 * doesn't load them and searches for each document on its own, by text, as before.
	 *
	 * @param array<class-string, list<string>> $implementedMethods
	 */
	#[DataProvider('interfacesThatGrew')]
	public function testSearchesForEachDocumentWhenNextcloudAsksForMoreThanTheAppKnows(array $implementedMethods, string $missing): void {
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');
		$letter = $this->file('Letter [P7] [P123].pdf', '/dennis/files/Paperless/A/Letter [P7] [P123].pdf');
		$texts = [];
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('search')->willReturnCallback(static function (string|ISearchQuery $query) use (&$texts, $invoice, $letter): array {
			self::assertIsString($query, 'The app loaded its own classes of a query.');
			$texts[] = $query;

			return array_values(array_filter([$invoice, $letter], static fn (File $file): bool => str_contains($file->getName(), $query)));
		});
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search looks for the files of each document on its own: Nextcloud asks its query of the file cache for {methods}', [
				'app' => AppConstants::APP_ID,
				'methods' => $missing,
			]);
		$locator = new NextcloudFileLocator($root, $logger, $implementedMethods);

		self::assertSame([123 => $letter, 7 => $letter], $locator->findAllForUser($this->user(), [123, 7], self::ARCHIVE)->files);
		self::assertSame([123 => $letter], $locator->findAllForUser($this->user(), [123], self::ARCHIVE)->files);
		self::assertSame(['[P123]', '[P7]', '[P123]'], $texts);
	}

	/**
	 * occ tells a file of another account from none: such a file stands for no document,
	 * but the user sees its marker.
	 */
	public function testFindsTheFilesWithTheMarkersOfAnyOwnerForOcc(): void {
		$forged = $this->file('Copy [P123].txt', '/dennis/files/Copy [P123].txt', 'mallory');
		$ownerless = $this->file('Letter [P7].pdf', '/dennis/files/Letter [P7].pdf', null);
		$later = $this->file('Copy [P123].txt', '/dennis/files/Zzz/Copy [P123].txt', 'dennis');

		$found = $this->locator([$later, $forged, $ownerless])->findAnyForUser($this->user(), [123, 7, 8]);

		self::assertSame([123 => $forged, 7 => $ownerless], $found->files);
		self::assertNull($found->error);
		self::assertCount(1, $this->searches);
	}

	/**
	 * occ compares the documents of Paperless with the files of the archive account: only
	 * those in its own folders count, as for the search, in one search of the file cache.
	 */
	public function testFindsTheDocumentsOfTheFilesOfTheArchiveAccount(): void {
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Broken [P9].pdf');
		$broken->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn('Scans [P5]');
		$nodes = [
			$this->file('Invoice [P12].pdf', '/dennis/files/Invoice [P12].pdf', 'dennis'),
			$this->file('Two [P3] [P1].pdf', '/dennis/files/Two [P3] [P1].pdf', 'dennis'),
			$this->file('Again [P3].pdf', '/dennis/files/Again [P3].pdf', 'dennis'),
			$this->file('Zero [P012].pdf', '/dennis/files/Zero [P012].pdf', 'dennis'),
			$this->file('Shared [P7].pdf', '/dennis/files/Shared [P7].pdf', 'mallory'),
			$this->file('Holiday.jpg', '/dennis/files/Holiday.jpg', 'dennis'),
			$folder,
			$broken,
		];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search skipped {count} file(s) it could not read ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 1,
				'errorType' => RuntimeException::class,
			]);

		self::assertSame([1, 3, 12], $this->locator($nodes, $logger)->findArchivedDocumentIds($this->user()));

		self::assertCount(1, $this->searches);
		$condition = $this->searches[0]->getSearchOperation();
		self::assertInstanceOf(ISearchComparison::class, $condition);
		self::assertSame(['name', ISearchComparison::COMPARE_LIKE, '%[P%'], [$condition->getField(), $condition->getType(), $condition->getValue()]);
	}

	public function testFindsTheDocumentsOfTheArchiveAccountByTextWhenNextcloudAsksForMore(): void {
		$invoice = $this->file('Invoice [P12].pdf', '/dennis/files/Invoice [P12].pdf', 'dennis');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects(self::once())->method('search')->with('[P')->willReturn([$invoice]);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$methods = NextcloudFileLocator::IMPLEMENTED_METHODS;
		$methods[ISearchQuery::class] = array_values(array_diff($methods[ISearchQuery::class], ['getSelectFields']));

		$locator = new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class), $methods);

		self::assertSame([12], $locator->findArchivedDocumentIds($this->user()));
	}

	/**
	 * A search for each document on its own finds the files of every document.
	 */
	public function testASearchForEachDocumentFindsTheFilesOfEveryDocument(): void {
		$receipt = $this->file('Receipt [P8].pdf', '/dennis/files/Paperless/Receipt [P8].pdf');
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');
		$letter = $this->file('Letter [P7].pdf', '/dennis/files/Paperless/Letter [P7].pdf');
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('search')->willReturnCallback(static function (string $text) use ($receipt, $invoice, $letter): array {
			// The file cache may answer with the nodes by their IDs.
			$found = [];
			foreach ([4711 => $receipt, 4712 => $invoice, 4713 => $letter] as $id => $file) {
				if (str_contains($file->getName(), $text)) {
					$found[$id] = $file;
				}
			}

			return $found;
		});
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$implementedMethods = [...NextcloudFileLocator::IMPLEMENTED_METHODS, 'OCP\\Files\\Search\\ISearchNothing' => []];
		$locator = new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class), $implementedMethods);

		$found = $locator->findAllForUser($this->user(), [8, 123, 7], self::ARCHIVE);

		self::assertSame([8 => $receipt, 123 => $invoice, 7 => $letter], $found->files);
		self::assertNull($found->error);
	}

	/**
	 * @return array<string, array{?string, string}>
	 */
	public static function relativePaths(): array {
		return [
			'path in the user folder' => ['/Paperless/Invoice [P123].pdf', '/Paperless/Invoice [P123].pdf'],
			'path without a leading slash' => ['Paperless/Invoice [P123].pdf', '/Paperless/Invoice [P123].pdf'],
			'no path in the user folder' => [null, '/Invoice [P123].pdf'],
			'empty path' => ['', '/Invoice [P123].pdf'],
		];
	}

	#[DataProvider('relativePaths')]
	public function testThePathIsRelativeToTheUserFolder(?string $relativePath, string $path): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects(self::once())
			->method('getRelativePath')
			->with('/dennis/files/Paperless/Invoice [P123].pdf')
			->willReturn($relativePath);

		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::once())->method('getUserFolder')->with('dennis')->willReturn($userFolder);

		$file = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');

		self::assertSame($path, (new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)))->getPathForUser($this->user(), $file));
	}

	/**
	 * The locator of the user dennis, or of another user, who sees these files and folders.
	 *
	 * @param list<Node> $nodes
	 */
	private function locator(array $nodes, ?LoggerInterface $logger = null, string $uid = 'dennis'): NextcloudFileLocator {
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $folderOf) use ($nodes, $uid): Folder {
			self::assertSame($uid, $folderOf);

			return $this->userFolder($nodes, $uid);
		});

		return new NextcloudFileLocator($root, $logger ?? $this->createStub(LoggerInterface::class));
	}

	private function user(string $uid = 'dennis'): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	/**
	 * A file that the user may only read, unless $updateable, in a folder of the archive
	 * account where the user may add no file, unless $folder is another one.
	 */
	private function file(string $name, string $path, ?string $owner = self::ARCHIVE, bool $updateable = false, ?Folder $folder = null, ?int $id = null, bool $shareRoot = false): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);
		// Another path is another file, unless the file is the same through another share.
		$file->method('getId')->willReturn($id ?? crc32($path));
		// The root of a share, a file shared on its own, has no path within the share.
		$file->method('getInternalPath')->willReturn($shareRoot ? '' : basename($path));
		$file->method('isUpdateable')->willReturn($updateable);
		$file->method('getParent')->willReturn($folder ?? $this->parentFolder(self::ARCHIVE, false));
		if ($owner !== null) {
			$account = $this->createStub(IUser::class);
			$account->method('getUID')->willReturn($owner);
			$file->method('getOwner')->willReturn($account);
		} else {
			$file->method('getOwner')->willReturn(null);
		}

		return $file;
	}

	/**
	 * The folder of a file, which the account $owner owns, or no account for '', and where
	 * the user may add files when $creatable.
	 */
	private function parentFolder(string $owner, bool $creatable): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('isCreatable')->willReturn($creatable);
		if ($owner === '') {
			$folder->method('getOwner')->willReturn(null);
		} else {
			$account = $this->createStub(IUser::class);
			$account->method('getUID')->willReturn($owner);
			$folder->method('getOwner')->willReturn($account);
		}

		return $folder;
	}
}
