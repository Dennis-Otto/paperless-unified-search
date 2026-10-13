<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\FoundFiles;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchBinaryOperator;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchComparison;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchQuery;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

final class NextcloudFileLocator {
	/**
	 * The methods of the app's own classes of a query of the file cache, in
	 * lib/Search/Files, by the interface of Nextcloud that each of them implements.
	 */
	public const IMPLEMENTED_METHODS = [
		ISearchQuery::class => ['getSearchOperation', 'getLimit', 'getOffset', 'getOrder', 'getUser', 'limitToHome', 'getSelectFields'],
		ISearchBinaryOperator::class => ['getType', 'getArguments', 'getQueryHint', 'setQueryHint'],
		ISearchComparison::class => ['getType', 'getField', 'getExtra', 'getValue', 'getQueryHint', 'setQueryHint'],
	];

	/** Whether the app's classes still implement every method of their interfaces, once known. */
	private ?bool $queryable = null;

	/**
	 * @param array<class-string, list<string>> $implementedMethods the methods of the app's
	 *                                                              classes by interface, as IMPLEMENTED_METHODS
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private array $implementedMethods = self::IMPLEMENTED_METHODS,
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
	 * @return FoundFiles the files in the order of the IDs, without a document that has no
	 *                    such file, and the error of the first file that failed to answer
	 */
	public function findAllForUser(IUser $user, array $documentIds, string $archiveOwner): FoundFiles {
		/** @var array<int, string> $markers by document ID */
		$markers = [];
		foreach ($documentIds as $documentId) {
			if ($documentId > 0) {
				$markers[$documentId] = '[P' . $documentId . ']';
			}
		}
		if ($markers === [] || $archiveOwner === '') {
			return new FoundFiles([]);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $this->canQueryTheFileCache()
			? $this->searchAll($userFolder, $user, array_values($markers))
			: $this->searchEach($userFolder, array_values($markers));

		/** @var array<int, array{string, File}> $found the path and the file of each document, by document ID */
		$found = [];
		$skipped = 0;
		$firstError = null;
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
				$firstError ??= $error;
			}
		}

		if ($firstError !== null) {
			$this->logger->warning('Paperless unified search skipped {count} file(s) it could not read ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => $skipped,
				'errorType' => $firstError::class,
			]);
		}

		$files = [];
		foreach (array_keys($markers) as $documentId) {
			if (isset($found[$documentId])) {
				$files[$documentId] = $found[$documentId][1];
			}
		}

		return new FoundFiles($files, $firstError);
	}

	/**
	 * The files whose names carry one of the markers, in one search of the file cache.
	 *
	 * @param non-empty-list<string> $markers
	 * @return array<array-key, Node>
	 */
	private function searchAll(Folder $userFolder, IUser $user, array $markers): array {
		// A marker holds digits only, so it holds no wildcard of LIKE either.
		$conditions = array_map(
			static fn (string $marker): SearchComparison => new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%' . $marker . '%'),
			$markers,
		);

		return $userFolder->search(new SearchQuery(new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, $conditions), $user));
	}

	/**
	 * The files whose names carry one of the markers, in a search of the file cache for
	 * each of them, by text, for which Nextcloud builds the query with its own classes.
	 *
	 * @param non-empty-list<string> $markers
	 * @return list<Node>
	 */
	private function searchEach(Folder $userFolder, array $markers): array {
		$nodes = [];
		foreach ($markers as $marker) {
			$nodes = [...$nodes, ...array_values($userFolder->search($marker))];
		}

		return $nodes;
	}

	/**
	 * Whether the app's classes in lib/Search/Files still implement every method of the
	 * interfaces of Nextcloud. Nextcloud adds methods to them even in a maintenance
	 * release, and PHP refuses a class that lacks one with a fatal error that no catch
	 * sees. So the locator compares the interfaces, without loading the app's classes,
	 * and otherwise searches for each document on its own.
	 */
	private function canQueryTheFileCache(): bool {
		if ($this->queryable !== null) {
			return $this->queryable;
		}

		$missing = [];
		foreach ($this->implementedMethods as $interface => $methods) {
			if (!interface_exists($interface)) {
				$missing[] = $interface;
				continue;
			}
			foreach (array_diff(get_class_methods($interface), $methods) as $method) {
				$missing[] = $interface . '::' . $method;
			}
		}

		$this->queryable = $missing === [];
		if ($missing !== []) {
			$this->logger->warning('Paperless unified search looks for the files of each document on its own: Nextcloud asks its query of the file cache for {methods}', [
				'app' => AppConstants::APP_ID,
				'methods' => implode(', ', $missing),
			]);
		}

		return $this->queryable;
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
