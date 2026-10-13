<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchBinaryOperator;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchComparison;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchQuery;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

final class NextcloudFileLocator {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The files of Paperless documents that the user can open, by the ID of their document:
	 * for each document a file of the archive account whose name carries its marker
	 * [P<ID>], of several such files the one with the first path. The marker alone proves
	 * nothing, since anyone can give a file such a name; the files of the archive account
	 * reach other users only through its shares.
	 *
	 * A search of the file cache compares the names of all files that the user can see,
	 * which no index serves, so one search looks for the markers of all documents at once.
	 *
	 * @param list<int> $documentIds
	 * @return array<int, File> in the order of the IDs; a document without such a file is left out
	 */
	public function findAllForUser(IUser $user, array $documentIds, string $archiveOwner): array {
		/** @var array<int, string> $markers by document ID */
		$markers = [];
		foreach ($documentIds as $documentId) {
			if ($documentId > 0) {
				$markers[$documentId] = '[P' . $documentId . ']';
			}
		}
		if ($markers === [] || $archiveOwner === '') {
			return [];
		}

		// A marker holds digits only, so it holds no wildcard of LIKE either.
		$conditions = array_map(
			static fn (string $marker): SearchComparison => new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%' . $marker . '%'),
			array_values($markers),
		);
		$nodes = $this->rootFolder->getUserFolder($user->getUID())->search(
			new SearchQuery(new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, $conditions), $user),
		);

		/** @var array<int, array{string, File}> $found the path and the file of each document, by document ID */
		$found = [];
		$skipped = 0;
		$errorType = '';
		foreach ($nodes as $node) {
			try {
				// The file cache compares the names regardless of their case, and a name may
				// carry the markers of several documents, so each marker counts on its own.
				if (!$node instanceof File
					|| preg_match_all('/\[P(\d+)\]/', $node->getName(), $matches) < 1
					|| $node->getOwner()?->getUID() !== $archiveOwner) {
					continue;
				}

				$path = $node->getPath();
				foreach ($matches[1] as $digits) {
					$documentId = (int)$digits;
					// Only the exact marker: [P012] is none of document 12.
					if ((string)$documentId === $digits && isset($markers[$documentId])
						&& (!isset($found[$documentId]) || strcmp($path, $found[$documentId][0]) < 0)) {
						$found[$documentId] = [$path, $node];
					}
				}
			} catch (Throwable $error) {
				// A file that fails to answer costs only its own document, not the search.
				$skipped++;
				$errorType = $error::class;
			}
		}

		if ($skipped > 0) {
			$this->logger->warning('Paperless unified search skipped {count} file(s) it could not read ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => $skipped,
				'errorType' => $errorType,
			]);
		}

		$files = [];
		foreach (array_keys($markers) as $documentId) {
			if (isset($found[$documentId])) {
				$files[$documentId] = $found[$documentId][1];
			}
		}

		return $files;
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
