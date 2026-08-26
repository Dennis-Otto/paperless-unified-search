<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;

final class NextcloudFileLocator {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	public function findForUser(IUser $user, int $paperlessDocumentId): ?File {
		if ($paperlessDocumentId < 1) {
			return null;
		}

		$marker = '[P' . $paperlessDocumentId . ']';
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$matches = [];

		foreach ($userFolder->search($marker) as $node) {
			if ($node instanceof File && str_contains($node->getName(), $marker)) {
				$matches[] = $node;
			}
		}

		if ($matches === []) {
			return null;
		}

		usort($matches, static fn (File $left, File $right): int => strcmp($left->getPath(), $right->getPath()));

		return $matches[0];
	}

	public function getPathForUser(IUser $user, File $file): string {
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$relativePath = $userFolder->getRelativePath($file->getPath());

		if ($relativePath === null || $relativePath === '') {
			return '/' . ltrim($file->getName(), '/');
		}

		return '/' . ltrim($relativePath, '/');
	}
}
