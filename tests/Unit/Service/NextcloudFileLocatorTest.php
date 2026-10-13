<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Tests\Unit\FileCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
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

		$files = $this->locator([$unrelated, $letter, $invoice])->findAllForUser($this->user(), [123, 999, 7], self::ARCHIVE);

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

		$files = $this->locator($nodes)->findAllForUser($this->user(), $documentIds, self::ARCHIVE);

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
			self::assertSame([123 => $first], $this->locator($nodes)->findAllForUser($this->user(), [123], self::ARCHIVE));
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

		self::assertSame([123 => $archived], $this->locator([$forged, $archived])->findAllForUser($this->user(), [123], self::ARCHIVE));
		self::assertSame([], $this->locator([$forged])->findAllForUser($this->user(), [123], self::ARCHIVE));
	}

	public function testIgnoresFoldersWithTheMarker(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn('Scans [P123]');
		$folder->method('getPath')->willReturn('/dennis/files/Scans [P123]');

		self::assertSame([], $this->locator([$folder])->findAllForUser($this->user(), [123], self::ARCHIVE));
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

		self::assertSame([], $locator->findAllForUser($this->user(), $documentIds, $archiveOwner));
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
		$alsoBroken->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));
		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search skipped {count} file(s) it could not read ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 2,
				'errorType' => RuntimeException::class,
			]);

		$files = $this->locator([$broken, $invoice, $alsoBroken], $logger)->findAllForUser($this->user(), [7, 123, 8], self::ARCHIVE);

		self::assertSame([123 => $invoice], $files);
	}

	public function testFilesThatWorkLogNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method(self::anything());

		$invoice = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');

		self::assertSame([123 => $invoice], $this->locator([$invoice], $logger)->findAllForUser($this->user(), [123], self::ARCHIVE));
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
		$root->method('getUserFolder')->with('dennis')->willReturn($userFolder);

		$file = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/Invoice [P123].pdf');

		self::assertSame($path, (new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)))->getPathForUser($this->user(), $file));
	}

	/**
	 * The locator of the user dennis, who sees these files and folders.
	 *
	 * @param list<Node> $nodes
	 */
	private function locator(array $nodes, ?LoggerInterface $logger = null): NextcloudFileLocator {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('dennis')->willReturn($this->userFolder($nodes));

		return new NextcloudFileLocator($root, $logger ?? $this->createStub(LoggerInterface::class));
	}

	private function user(): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		return $user;
	}

	private function file(string $name, string $path, ?string $owner = self::ARCHIVE): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);
		if ($owner !== null) {
			$account = $this->createStub(IUser::class);
			$account->method('getUID')->willReturn($owner);
			$file->method('getOwner')->willReturn($account);
		} else {
			$file->method('getOwner')->willReturn(null);
		}

		return $file;
	}
}
