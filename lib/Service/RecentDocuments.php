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
 * distributed cache of Nextcloud keeps them for a minute, for all users, and each user
 * sees those whose file they can open. A Paperless that sent none rests for a minute as
 * well, so that no dashboard waits for it again meanwhile.
 */
final class RecentDocuments {
	/**
	 * How many documents Paperless sends, newest first: a page as large as its own, of
	 * which a user may open only a few.
	 */
	public const COUNT = 25;

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
	 * The newest documents of Paperless, newest first, with the fields that the dashboard
	 * shows, or null when Paperless didn't send them, now or within the last minute.
	 *
	 * @return ?list<array<array-key, mixed>>
	 */
	public function find(): ?array {
		$server = hash('sha256', $this->configService->getUrl());
		$cached = self::documentsOf($this->getCache()->get(self::documentsKey($server)));
		if ($cached !== null) {
			return $cached;
		}
		if ($this->getCache()->get(self::restingKey($server)) === true) {
			return null;
		}

		try {
			$documents = $this->paperlessApi->findRecentDocuments(self::COUNT);
		} catch (Throwable $error) {
			$this->getCache()->set(self::restingKey($server), true, self::TTL);
			$this->logger->warning('Paperless sent the dashboard no newest documents ({error})', [
				'app' => AppConstants::APP_ID,
				'error' => $error instanceof PaperlessRequestException ? $error->getMessage() : $error::class,
			]);

			return null;
		}

		$this->getCache()->set(self::documentsKey($server), $documents, self::TTL);

		return $documents;
	}

	/**
	 * The documents that the cache holds, or null for anything else, such as nothing.
	 *
	 * @return ?list<array<array-key, mixed>>
	 */
	private static function documentsOf(mixed $cached): ?array {
		if (!is_array($cached) || !array_is_list($cached)) {
			return null;
		}

		$documents = [];
		/** @psalm-suppress MixedAssignment Every document is checked before use. */
		foreach ($cached as $document) {
			if (!is_array($document)) {
				return null;
			}
			$documents[] = $document;
		}

		return $documents;
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
