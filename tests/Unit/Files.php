<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;

/**
 * The files of several users of a Nextcloud, for the tests that look for the files of
 * documents as different users: each user sees the files at the paths of their own
 * folders, those that others shared with them as well.
 */
trait Files {
	use FileCache;

	/** @var array<string, list<Node>> the files and folders that each user sees, by user ID */
	private array $visible = [];

	/**
	 * A file at a path in the folders of a user, such as /jamie/files/Invoice [P412].pdf,
	 * which that user sees and the account $owner owns: the user's own file, or a share,
	 * which the user may only read, or also change when $writable.
	 */
	private function file(string $path, ?string $owner = 'archive', bool $writable = false): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getPath')->willReturn($path);
		$file->method('getId')->willReturn(crc32($path));
		$file->method('isUpdateable')->willReturn($writable);
		// A folder of no account, where a file that the user adds is no file of the archive.
		$file->method('getParent')->willReturn($this->createStub(Folder::class));
		if ($owner === null) {
			$file->method('getOwner')->willReturn(null);
		} else {
			$account = $this->createStub(IUser::class);
			$account->method('getUID')->willReturn($owner);
			$file->method('getOwner')->willReturn($account);
		}
		$this->visible[explode('/', $path)[1]][] = $file;

		return $file;
	}

	/**
	 * A folder that a user sees, which the search of the file cache finds by its name too.
	 */
	private function folder(string $path): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getPath')->willReturn($path);
		$this->visible[explode('/', $path)[1]][] = $folder;

		return $folder;
	}

	/**
	 * The root of the files of Nextcloud, with the folder of each user.
	 */
	private function rootFolder(): IRootFolder {
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (string $uid): Folder => $this->userFolder($this->visible[$uid] ?? [], $uid));

		return $root;
	}

	private function user(string $uid): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}
}
