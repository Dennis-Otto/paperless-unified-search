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

	private function file(string $name, string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);

		return $file;
	}
}
