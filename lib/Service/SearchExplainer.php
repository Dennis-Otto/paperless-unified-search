<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\Model\Explanation;
use OCA\PaperlessUnifiedSearch\Model\FoundFiles;
use OCA\PaperlessUnifiedSearch\Model\Verdict;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserManager;
use Throwable;

/**
 * Why a document that Paperless found shows to a user in the search or not, for
 * occ paperless_unified_search:search --explain. The search itself looks only for the
 * files of the archive account that the user can open, in one search of the file cache;
 * a document without one is left out without a trace. The explanation looks further, with
 * a search of the file cache each, and only for the documents that are still unexplained:
 *
 * 1. a file of the archive account that the user can open: the document shows;
 * 2. a file of the archive account in its own folders: it isn't shared with the user;
 * 3. a file with the marker that the user can see, of another account: it doesn't count;
 * 4. otherwise no file of the archive account carries the marker: it isn't synchronized.
 */
final class SearchExplainer {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private NextcloudFileLocator $fileLocator,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Whether each of these documents shows to the user, as the search decides it, in one
	 * search of the file cache: shown or hidden, in their order, each document once.
	 *
	 * @param list<int> $documentIds
	 * @return array{list<Explanation>, ?Throwable} the verdicts, and the error of the first
	 *                                              file that failed to answer
	 */
	public function verdicts(IUser $user, array $documentIds, string $archiveOwner): array {
		$explanations = $this->unexplainedOf($documentIds);
		$shown = $this->fileLocator->findAllForUser($user, array_keys($explanations), $archiveOwner);
		$this->explainWith($explanations, $shown, Verdict::Shown, $user);

		return [$this->listOf($explanations, Verdict::Hidden), $shown->error];
	}

	/**
	 * Why each of these documents shows to the user or not, in their order, each document
	 * once.
	 *
	 * @param list<int> $documentIds
	 * @return array{list<Explanation>, ?Throwable} the explanations, and the error of the
	 *                                              first file that failed to answer: a
	 *                                              document without a file may have had it
	 */
	public function explain(IUser $user, array $documentIds, string $archiveOwner): array {
		$explanations = $this->unexplainedOf($documentIds);
		$shown = $this->fileLocator->findAllForUser($user, $this->unexplained($explanations), $archiveOwner);
		$error = $shown->error;
		$this->explainWith($explanations, $shown, Verdict::Shown, $user);

		$archive = $archiveOwner !== '' ? $this->userManager->get($archiveOwner) : null;
		if ($archive !== null && $archive->getUID() !== $user->getUID()) {
			$archived = $this->fileLocator->findAllForUser($archive, $this->unexplained($explanations), $archiveOwner);
			$error ??= $archived->error;
			$this->explainWith($explanations, $archived, Verdict::NotShared, $archive);
		}

		$others = $this->fileLocator->findAnyForUser($user, $this->unexplained($explanations));
		$error ??= $others->error;
		$this->explainWith($explanations, $others, Verdict::NotOwned, $user);

		return [$this->listOf($explanations, Verdict::NoFile), $error];
	}

	/**
	 * The documents with an ID, each once, none explained yet.
	 *
	 * @param list<int> $documentIds
	 * @return array<int, ?Explanation> by document ID, null while unexplained
	 */
	private function unexplainedOf(array $documentIds): array {
		$explanations = [];
		foreach ($documentIds as $documentId) {
			if ($documentId > 0) {
				$explanations[$documentId] = null;
			}
		}

		return $explanations;
	}

	/**
	 * The explanations in the order of the documents, with the verdict for those that are
	 * still unexplained.
	 *
	 * @param array<int, ?Explanation> $explanations
	 * @return list<Explanation>
	 */
	private function listOf(array $explanations, Verdict $rest): array {
		$list = [];
		foreach ($explanations as $documentId => $explanation) {
			$list[] = $explanation ?? new Explanation($documentId, $rest);
		}

		return $list;
	}

	/**
	 * @param array<int, ?Explanation> $explanations
	 * @return list<int>
	 */
	private function unexplained(array $explanations): array {
		return array_keys(array_filter($explanations, static fn (?Explanation $explanation): bool => $explanation === null));
	}

	/**
	 * Explains the documents of these files with the verdict, with the path of each file
	 * in the folders of the account that found it.
	 *
	 * @param array<int, ?Explanation> $explanations
	 */
	private function explainWith(array &$explanations, FoundFiles $found, Verdict $verdict, IUser $account): void {
		foreach ($found->files as $documentId => $file) {
			$explanations[$documentId] = new Explanation(
				$documentId,
				$verdict,
				$this->pathOf($account, $file),
				$this->ownerOf($file),
			);
		}
	}

	private function pathOf(IUser $account, File $file): string {
		try {
			return $this->fileLocator->getPathForUser($account, $file);
		} catch (Throwable) {
			return '';
		}
	}

	private function ownerOf(File $file): string {
		try {
			return $file->getOwner()?->getUID() ?? '';
		} catch (Throwable) {
			return '';
		}
	}
}
