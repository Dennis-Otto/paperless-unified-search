<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The documents that Paperless added last, which the dashboard lists. They are the same
 * for every user, since every request to Paperless carries the token of the settings: the
 * distributed cache of Nextcloud keeps each page of them for a minute, for all users, and
 * each user sees those whose file they can open. A Paperless that sent none rests for a
 * minute as well, so that no dashboard waits for it again meanwhile.
 */
final class RecentDocuments {
	/**
	 * How many documents Paperless sends on a page, newest first: as many as on a page of
	 * its own, of which a user may open only a few.
	 */
	public const COUNT = 25;

	/**
	 * How many pages of the newest documents the dashboard of a user asks for at most, while
	 * fewer of them than it shows have a file that the user can open: the 100 newest.
	 */
	public const MOST_PAGES = 4;

	/** The seconds that the cache keeps the documents, and a Paperless that sent none. */
	private const TTL = 60;

	private ?ICache $cache = null;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PaperlessApiService $paperlessApi,
		private ConfigService $configService,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A page of the newest documents of Paperless, newest first, with the fields that the
	 * dashboard shows and whether Paperless has older ones on a further page, or null when
	 * Paperless didn't send it, now or within the last minute: of all documents, or with the
	 * IDs of some tags only of those with at least one of them, for the users who may see
	 * only those (decision 0015), which the cache keeps apart, as it keeps each page.
	 *
	 * @param ?list<int> $tagIds
	 * @param int $page the page, from 1, with as many documents as COUNT
	 * @return ?array{documents: list<array<array-key, mixed>>, more: bool}
	 */
	public function find(?array $tagIds = null, int $page = 1): ?array {
		return $this->page(
			($tagIds === null ? '' : '-tags-' . implode('-', $tagIds)) . ($page === 1 ? '' : '-page-' . $page),
			$tagIds === null ? [] : [PaperlessApiService::TAGS_ANY => implode(',', $tagIds)],
			$page,
		);
	}

	/**
	 * The first page of the newest documents that these users of Paperless own, as find()
	 * has it: for the dashboard of a user whose own files stand for their documents (decision
	 * 0013), once the newest documents of all hold too few that the user can open.
	 *
	 * @param list<int> $ownerIds
	 * @return ?array{documents: list<array<array-key, mixed>>, more: bool}
	 */
	public function findOwnedBy(array $ownerIds): ?array {
		return $this->page('-owners-' . implode('-', $ownerIds), [PaperlessApiService::OWNERS_ANY => implode(',', $ownerIds)], 1);
	}

	/**
	 * A page of the newest documents with these filters, from the cache or from Paperless,
	 * under the key of the server and $variant.
	 *
	 * @param array<string, string> $filters
	 * @return ?array{documents: list<array<array-key, mixed>>, more: bool}
	 */
	private function page(string $variant, array $filters, int $page): ?array {
		$server = hash('sha256', $this->configService->getUrl()) . $variant;
		$cached = self::pageOf($this->getCache()->get(self::documentsKey($server)));
		if ($cached !== null) {
			return $cached;
		}
		if ($this->getCache()->get(self::restingKey($server)) === true) {
			return null;
		}

		try {
			$found = $this->paperlessApi->findRecentDocuments(self::COUNT, $filters, $page);
		} catch (Throwable $error) {
			// Paperless refuses a page past the last one with 404. A page before it, cached a
			// little earlier, can still name a further page when documents went meanwhile:
			// that is the end of the list, not a Paperless that can't be reached.
			if ($page > 1 && $error instanceof PaperlessRequestException && $error->status === 404) {
				$documents = ['documents' => [], 'more' => false];
				$this->getCache()->set(self::documentsKey($server), $documents, self::TTL);

				return $documents;
			}
			$this->getCache()->set(self::restingKey($server), true, self::TTL);
			$this->logger->warning('Paperless sent the dashboard no newest documents ({error})', [
				'app' => AppConstants::APP_ID,
				'error' => $error instanceof PaperlessRequestException ? $error->getMessage() : $error::class,
			]);

			return null;
		}

		$documents = ['documents' => $found['results'], 'more' => $found['next'] !== null];
		$this->getCache()->set(self::documentsKey($server), $documents, self::TTL);

		return $documents;
	}

	/**
	 * The page that the cache holds, or null for anything else, such as nothing or the list
	 * of documents alone that an older version of the app kept.
	 *
	 * @return ?array{documents: list<array<array-key, mixed>>, more: bool}
	 */
	private static function pageOf(mixed $cached): ?array {
		if (!is_array($cached) || !isset($cached['more'], $cached['documents']) || !is_bool($cached['more']) || !is_array($cached['documents']) || !array_is_list($cached['documents'])) {
			return null;
		}

		$documents = [];
		/** @psalm-suppress MixedAssignment Every document is checked before use. */
		foreach ($cached['documents'] as $document) {
			if (!is_array($document)) {
				return null;
			}
			$documents[] = $document;
		}

		return ['documents' => $documents, 'more' => $cached['more']];
	}

	private function getCache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed(AppConstants::APP_ID);
	}

	private static function documentsKey(string $server): string {
		return 'recent-documents-' . $server;
	}

	private static function restingKey(string $server): string {
		return 'recent-documents-resting-' . $server;
	}
}
