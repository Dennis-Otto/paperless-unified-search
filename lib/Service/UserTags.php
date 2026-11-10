<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tags of Paperless that some users of Nextcloud may see, from the table Tags that a user
 * may see of Paperless Sync (its decision 0038, and decision 0015 here): one line for each
 * such user, with their user ID of Nextcloud, an equals sign and the names of the tags,
 * separated by commas, such as katja = Katja, Household. Such a user meets only the documents
 * with at least one of their tags, whatever the case of the names, everywhere the search
 * shows a document: a user without a line, or with a line without tags, meets every document
 * that a file of theirs stands for, as before.
 *
 * Paperless Sync keeps the lines, as it decides who gets which documents, and writes them as
 * user = Tag, Tag, with the ID of the user as Nextcloud spells it; a line that it would refuse
 * to save counts for nobody here either. The names become the IDs of the tags of Paperless
 * with these names, from /api/tags/, which the cache of Nextcloud keeps for ten minutes. While
 * they are unknown, as for an account that may not view tags or a Paperless that doesn't
 * answer, a user with tags meets no document at all: never more than the lines allow.
 */
final class UserTags {
	/** The seconds that the cache keeps the tags of Paperless, and a list that it refused. */
	private const TTL = 600;

	/** The seconds that the list rests after a request without answer. */
	private const UNANSWERED_TTL = 60;

	private ?ICache $cache = null;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The names of the tags that each user with a line may see, by their user IDs, as
	 * Paperless Sync stores the lines now.
	 *
	 * @return array<string, non-empty-list<string>>
	 */
	public function lines(): array {
		return self::parse($this->configService->getSyncUserTags());
	}

	/**
	 * The names of the tags that each user with a line may see, by their user IDs, as the
	 * lines write them: each name once, whatever its case, and of two lines of one user, which
	 * Paperless Sync refuses to save, the later one, as it reads them. A line without an
	 * equals sign, a user or a tag counts for nobody.
	 *
	 * @return array<string, non-empty-list<string>>
	 */
	public static function parse(string $value): array {
		$lines = [];
		foreach (preg_split('/\R/u', $value) ?: [] as $line) {
			$parts = explode('=', $line, 2);
			$user = trim($parts[0]);
			$names = count($parts) === 2 ? self::namesIn($parts[1]) : [];
			if ($user !== '' && $names !== []) {
				$lines[$user] = $names;
			}
		}

		return $lines;
	}

	/** Whether the user may see only the documents with some tags. */
	public function restricts(string $userId): bool {
		return isset($this->lines()[$userId]);
	}

	/**
	 * The names of the tags that the user may see, or none for a user without a line, who
	 * may see every document.
	 *
	 * @return list<string>
	 */
	public function namesOf(string $userId): array {
		return $this->lines()[$userId] ?? [];
	}

	/**
	 * The IDs of the tags of Paperless that the user may see: null for a user without tags,
	 * who may see every document, and for a user with tags the ID of every tag of Paperless
	 * with one of their names, whatever the case, or none, so that they meet no document,
	 * while Paperless has none of them or its tags are unknown.
	 *
	 * @return ?list<int>
	 */
	public function tagIdsOf(string $userId): ?array {
		$names = $this->lines()[$userId] ?? null;
		if ($names === null) {
			return null;
		}

		$wanted = array_map('mb_strtolower', $names);
		$ids = [];
		foreach ($this->paperlessTags() ?? [] as $id => $name) {
			if (in_array(mb_strtolower($name), $wanted, true)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The IDs of the documents among these that the user may see, in their order: every one
	 * for a user without tags, and for a user with tags those of the documents whose tags in
	 * Paperless, their field tags, hold at least one of theirs. An ID without its document
	 * holds none: the tags that Paperless sends decide, never a file or its name.
	 *
	 * @param list<int> $documentIds the IDs as the caller reads them from the documents
	 * @param array<array-key, array<array-key, mixed>> $documents as Paperless sends them,
	 *                                                             with their fields id and tags
	 * @return list<int>
	 */
	public function visibleIds(string $userId, array $documentIds, array $documents): array {
		$tagIds = $this->tagIdsOf($userId);
		if ($tagIds === null) {
			return $documentIds;
		}

		$tagged = [];
		foreach ($documents as $document) {
			/** @psalm-suppress MixedAssignment The ID is checked before use. */
			$id = $document['id'] ?? null;
			if (is_numeric($id) && self::carries($document, $tagIds)) {
				$tagged[] = (int)$id;
			}
		}

		return array_values(array_filter($documentIds, static fn (int $id): bool => in_array($id, $tagged, true)));
	}

	/**
	 * Whether the tags of a document hold one of these IDs, or any document for null. A
	 * document without the field tags, or with tags that are no IDs, holds none.
	 *
	 * @param array<array-key, mixed> $document
	 * @param ?list<int> $tagIds
	 */
	public static function carries(array $document, ?array $tagIds): bool {
		if ($tagIds === null) {
			return true;
		}

		/** @psalm-suppress MixedAssignment Each tag is checked below. */
		$tags = $document['tags'] ?? null;
		if (!is_array($tags)) {
			return false;
		}

		/** @psalm-suppress MixedAssignment Each tag is compared as it is. */
		foreach ($tags as $tag) {
			if (is_int($tag) && in_array($tag, $tagIds, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forgets the tags of Paperless that the cache keeps for the server and the token of the
	 * settings, and that Paperless refused them, so that the next search asks for them again:
	 * after a save of the settings, and before occ paperless_unified_search:check. A cache
	 * that fails only leaves them until they expire.
	 */
	public function forget(): void {
		try {
			$server = $this->server();
			$this->getCache()->remove(self::tagsKey($server));
			$this->getCache()->remove(self::restingKey($server));
		} catch (Throwable $error) {
			$this->logger->warning('The tags of Paperless could not be removed from the cache, so they may count as they were for up to ten minutes more ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);
		}
	}

	/**
	 * The names of the tags of Paperless by their IDs, from the cache or with one request of
	 * each page of the list, or null while they are unknown: while the list rests, after
	 * Paperless refused it to the token, which may not view tags, for ten minutes, and after a
	 * request without answer or a cache that fails for a minute.
	 *
	 * @return ?array<int, string>
	 */
	private function paperlessTags(): ?array {
		try {
			$server = $this->server();
			$cached = self::tagsIn($this->getCache()->get(self::tagsKey($server)));
			if ($cached !== null) {
				return $cached;
			}
			if ($this->getCache()->get(self::restingKey($server)) === true) {
				return null;
			}

			try {
				$tags = $this->paperlessApi->findTags();
			} catch (Throwable $error) {
				$refused = $error instanceof PaperlessRequestException && $error->reason === RequestFailure::Unauthorized;
				$this->getCache()->set(self::restingKey($server), true, $refused ? self::TTL : self::UNANSWERED_TTL);
				$this->logger->warning('Paperless gave no tags, so the users with tags meet no document ({error})', [
					'app' => AppConstants::APP_ID,
					'error' => $error instanceof PaperlessRequestException ? $error->getMessage() : $error::class,
				]);

				return null;
			}

			$this->getCache()->set(self::tagsKey($server), $tags, self::TTL);

			return $tags;
		} catch (Throwable $error) {
			// Such as a cache that fails: never more than the lines allow.
			$this->logger->warning('The tags of Paperless could not be read, so the users with tags meet no document ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);

			return null;
		}
	}

	/**
	 * The names in the tags of a line, separated by commas, each once, whatever its case, as
	 * Paperless Sync reads them.
	 *
	 * @return list<string>
	 */
	private static function namesIn(string $list): array {
		$names = [];
		foreach (explode(',', $list) as $name) {
			$name = trim($name);
			if ($name !== '') {
				$names[mb_strtolower($name)] ??= $name;
			}
		}

		return array_values($names);
	}

	/**
	 * The names of tags that the cache holds, or null for anything else.
	 *
	 * @return ?array<int, string>
	 */
	private static function tagsIn(mixed $cached): ?array {
		if (!is_array($cached)) {
			return null;
		}

		$tags = [];
		/** @psalm-suppress MixedAssignment Each name is checked below. */
		foreach ($cached as $id => $name) {
			if (!is_int($id) || !is_string($name)) {
				return null;
			}
			$tags[$id] = $name;
		}

		return $tags;
	}

	/**
	 * The key of the server and the token of the settings in the cache, as for the users of
	 * Paperless (DocumentOwners): another token may view other tags.
	 */
	private function server(): string {
		return hash('sha256', $this->configService->getUrl() . ' ' . $this->configService->getToken());
	}

	private function getCache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed(AppConstants::APP_ID);
	}

	private static function tagsKey(string $server): string {
		return 'tags-' . $server;
	}

	private static function restingKey(string $server): string {
		return 'tags-resting-' . $server;
	}
}
