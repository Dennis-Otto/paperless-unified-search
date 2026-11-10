<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\DocumentOwners;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\UserTags;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The users of Nextcloud whom Paperless Sync gives the owners of documents, for the tests of
 * the services that find the files of documents, and the tags that some users may see: as
 * Nextcloud builds them, with a cache in memory of its own unless a test passes one, and
 * without groups.
 */
trait Owners {
	private function documentOwners(PaperlessApiService $paperlessApi, ConfigService $configService, IUserManager $accounts, ?ICacheFactory $cacheFactory = null): DocumentOwners {
		$cacheFactory ??= $this->memoryCacheFactory();

		return new DocumentOwners(
			$paperlessApi,
			$configService,
			$accounts,
			$this->createStub(IGroupManager::class),
			$cacheFactory,
			$this->createStub(LoggerInterface::class),
			$this->userTags($paperlessApi, $configService, $cacheFactory),
		);
	}

	private function userTags(PaperlessApiService $paperlessApi, ConfigService $configService, ?ICacheFactory $cacheFactory = null): UserTags {
		return new UserTags($configService, $paperlessApi, $cacheFactory ?? $this->memoryCacheFactory(), $this->createStub(LoggerInterface::class));
	}

	private function memoryCacheFactory(): ICacheFactory {
		$cache = new MemoryCache(static fn (): int => 0);
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return $cacheFactory;
	}
}
