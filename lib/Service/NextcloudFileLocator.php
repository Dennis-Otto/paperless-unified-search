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

	/** How the marker of a document begins in the name of its file, as in [P412]. */
	private const MARKER_START = '[P';

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
	 * A user other than the archive account counts only the files that the user may only
	 * read: whoever may change a file of the archive account, or add one to its folder,
	 * through a share with write permission, can give it the marker of any document. The
	 * archive account writes its own files, and counts them all.
	 *
	 * A search of the file cache compares the names of all files that the user can see,
	 * which no index serves, so one search looks for the markers of all documents at once.
	 *
	 * @param list<int> $documentIds
	 * @return FoundFiles the files in the order of the IDs, without a document that has no
	 *                    such file, the error of the first file that failed to answer, and
	 *                    the files that the user may write of the documents without a file
	 */
	public function findAllForUser(IUser $user, array $documentIds, string $archiveOwner): FoundFiles {
		if ($archiveOwner === '') {
			return new FoundFiles([]);
		}

		return $this->find($user, $documentIds, $archiveOwner, $user->getUID() !== $archiveOwner);
	}

	/**
	 * The files with the markers of the documents among those that the user can see,
	 * whoever owns them, so that occ can tell a file of another account from none. The
	 * search never uses them: such a file stands for no document.
	 *
	 * @param list<int> $documentIds
	 * @return FoundFiles as findAllForUser() returns them, of any owner
	 */
	public function findAnyForUser(IUser $user, array $documentIds): FoundFiles {
		return $this->find($user, $documentIds, null, false);
	}

	/**
	 * The IDs of the documents that a file of the archive account stands for, among the
	 * files in its own folders, smallest first: for occ, which compares them with the
	 * documents of Paperless. Files that fail to answer are left out, as in the search.
	 *
	 * @return list<int>
	 */
	public function findArchivedDocumentIds(IUser $archiveAccount): array {
		$userFolder = $this->rootFolder->getUserFolder($archiveAccount->getUID());
		$nodes = $this->canQueryTheFileCache()
			? $userFolder->search(new SearchQuery(new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%' . self::MARKER_START . '%'), $archiveAccount))
			: $userFolder->search(self::MARKER_START);

		$documentIds = [];
		$skipped = 0;
		$firstError = null;
		foreach ($nodes as $node) {
			try {
				if ($node instanceof File && $node->getOwner()?->getUID() === $archiveAccount->getUID()) {
					foreach (self::documentIdsIn($node->getName()) as $documentId) {
						$documentIds[$documentId] = true;
					}
				}
			} catch (Throwable $error) {
				$skipped++;
				$firstError ??= $error;
			}
		}
		$this->logSkipped($skipped, $firstError);

		$documentIds = array_keys($documentIds);
		sort($documentIds);

		return $documentIds;
	}

	/**
	 * The files with the markers of the documents among those that the user can see, of the
	 * owner, or of any owner for null, and for $readOnly only those that the user may only
	 * read, with the others apart.
	 *
	 * @param list<int> $documentIds
	 */
	private function find(IUser $user, array $documentIds, ?string $owner, bool $readOnly): FoundFiles {
		/** @var array<int, string> $markers by document ID */
		$markers = [];
		foreach ($documentIds as $documentId) {
			if ($documentId > 0) {
				$markers[$documentId] = self::MARKER_START . $documentId . ']';
			}
		}
		if ($markers === []) {
			return new FoundFiles([]);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $this->canQueryTheFileCache()
			? $this->searchAll($userFolder, $user, array_values($markers))
			: $this->searchEach($userFolder, array_values($markers));

		/** @var array<int, list<array{string, File}>> $candidates the path and the file of each file of each document, by document ID */
		$candidates = [];
		/** @var array<int, true> $writableIds the IDs of the files that the user may write through any of their paths */
		$writableIds = [];
		/** @var array<string, bool> $openFolders whether the user may add files to each folder so far, by its path */
		$openFolders = [];
		$skipped = 0;
		$firstError = null;
		foreach ($nodes as $node) {
			try {
				// The file cache compares the names regardless of their case, and a name may
				// carry the markers of several documents, so each marker counts on its own.
				if (!$node instanceof File) {
					continue;
				}
				$documentIdsOfTheName = self::documentIdsIn($node->getName());
				if ($documentIdsOfTheName === [] || ($owner !== null && $node->getOwner()?->getUID() !== $owner)) {
					continue;
				}
				if ($readOnly && self::isTheRootOfAShare($node)) {
					$documentIdsOfTheName = self::documentIdsIn($this->nameOfTheOwner($node, (string)$owner));
				}

				$path = $node->getPath();

				// The user may reach one file through several shares: whoever may write it
				// through one of them gives it its name, which counts through none.
				if ($readOnly && self::mayWrite($node, $path, $owner, $openFolders)) {
					$writableIds[$node->getId()] = true;
				}
				foreach ($documentIdsOfTheName as $documentId) {
					if (isset($markers[$documentId])) {
						$candidates[$documentId][] = [$path, $node];
					}
				}
			} catch (Throwable $error) {
				// A file that fails to answer costs only its own document, not the search.
				$skipped++;
				$firstError ??= $error;
			}
		}

		$files = [];
		$writableFiles = [];
		foreach (array_keys($markers) as $documentId) {
			$found = null;
			$writable = null;
			foreach ($candidates[$documentId] ?? [] as $candidate) {
				try {
					$mayWrite = $writableIds !== [] && ($writableIds[$candidate[1]->getId()] ?? false);
				} catch (Throwable $error) {
					$skipped++;
					$firstError ??= $error;
					continue;
				}
				if ($mayWrite) {
					$writable = self::theFirst($writable, $candidate);
				} else {
					$found = self::theFirst($found, $candidate);
				}
			}
			if ($found !== null) {
				$files[$documentId] = $found[1];
			} elseif ($writable !== null) {
				$writableFiles[$documentId] = $writable[1];
			}
		}

		$this->logSkipped($skipped, $firstError);

		return new FoundFiles($files, $firstError, $writableFiles);
	}

	/**
	 * Of the path and file found so far and another one, the one with the earlier path.
	 *
	 * @param array{string, File}|null $first
	 * @param array{string, File} $candidate
	 * @return array{string, File}
	 */
	private static function theFirst(?array $first, array $candidate): array {
		return $first === null || strcmp($candidate[0], $first[0]) < 0 ? $candidate : $first;
	}

	/**
	 * Whether the file is the root of its share, a file shared on its own, which has no
	 * path within the share.
	 */
	private static function isTheRootOfAShare(File $file): bool {
		return $file->getInternalPath() === '';
	}

	/**
	 * The name that the owner gave the file. A file shared on its own is the root of its
	 * share, which bears the name that the recipient gives it, even a recipient who may
	 * only read it, so its name in the folders of the owner counts instead.
	 */
	private function nameOfTheOwner(File $file, string $owner): string {
		return $this->rootFolder->getUserFolder($owner)->getFirstNodeById($file->getId())?->getName() ?? '';
	}

	/**
	 * The IDs of the documents whose markers a name carries, each once: only the exact
	 * marker, so [P012] is none of document 12, and [p12] none either.
	 *
	 * @return array<int, int> the IDs, each by itself
	 */
	private static function documentIdsIn(string $name): array {
		preg_match_all('/\[P(\d+)\]/', $name, $matches);

		$documentIds = [];
		foreach ($matches[1] ?? [] as $digits) {
			$documentId = (int)$digits;
			if ((string)$documentId === $digits) {
				$documentIds[$documentId] = $documentId;
			}
		}

		return $documentIds;
	}

	/**
	 * Whether the user may change the file, or add a file to its folder of the owner, and
	 * so give a file of the owner any name. A file shared on its own lies in a folder of the
	 * user, where a file that the user adds is the user's own; the name that the user gives
	 * the share itself counts for nothing (see nameOfTheOwner()).
	 *
	 * @param array<string, bool> $openFolders whether the user may add files to each
	 *                                         folder so far, by its path, which this adds to
	 */
	private static function mayWrite(File $file, string $path, ?string $owner, array &$openFolders): bool {
		if ($file->isUpdateable()) {
			return true;
		}

		$folderPath = dirname($path);
		if (!isset($openFolders[$folderPath])) {
			$folder = $file->getParent();
			$openFolders[$folderPath] = $folder->getOwner()?->getUID() === $owner && $folder->isCreatable();
		}

		return $openFolders[$folderPath];
	}

	private function logSkipped(int $skipped, ?Throwable $firstError): void {
		if ($firstError === null) {
			return;
		}

		$this->logger->warning('Paperless unified search skipped {count} file(s) it could not read ({errorType})', [
			'app' => AppConstants::APP_ID,
			'count' => $skipped,
			'errorType' => $firstError::class,
		]);
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
