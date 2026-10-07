<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NextcloudFileLocatorTest extends TestCase {
	public function testFindsExactMarkerAndUsesDeterministicPath(): void {
		$nearMatch = $this->file('Invoice [P1234].pdf', '/dennis/files/Paperless/z.pdf');
		$laterMatch = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/z.pdf');
		$firstMatch = $this->file('Invoice [P123].pdf', '/dennis/files/Paperless/a.pdf');

		$folder = $this->createMock(Folder::class);
		$folder->expects(self::once())
			->method('search')
			->with('[P123]')
			->willReturn([$nearMatch, $laterMatch, $firstMatch]);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('dennis')->willReturn($folder);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		$locator = new NextcloudFileLocator($root);

		self::assertSame($firstMatch, $locator->findForUser($user, 123));
	}

	public function testReturnsNullWhenUserCannotAccessMatchingFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('search')->willReturn([]);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('other-user');

		self::assertNull((new NextcloudFileLocator($root))->findForUser($user, 123));
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function invalidDocumentIds(): array {
		return [
			'zero' => [0],
			'negative' => [-123],
		];
	}

	#[DataProvider('invalidDocumentIds')]
	public function testDoesNotSearchForInvalidDocumentIds(int $documentId): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::never())->method('getUserFolder');

		self::assertNull((new NextcloudFileLocator($root))->findForUser($this->user(), $documentId));
	}

	public function testIgnoresFoldersWithTheMarker(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn('Scans [P123]');

		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('search')->willReturn([$folder]);

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		self::assertNull((new NextcloudFileLocator($root))->findForUser($this->user(), 123));
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

		self::assertSame($path, (new NextcloudFileLocator($root))->getPathForUser($this->user(), $file));
	}

	private function user(): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		return $user;
	}

	private function file(string $name, string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);

		return $file;
	}
}
