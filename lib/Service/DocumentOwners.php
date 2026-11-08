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
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The user of Nextcloud whose own files stand for a document besides those of the archive
 * account, when Paperless Sync gives each user the documents that their user of Paperless
 * owns (decision 0013): the user whom Paperless Sync gives the owner of the document, by its
 * rules. A user of Paperless gets the user of Nextcloud that its table Paperless users with
 * another Nextcloud ID names, or else the one with its ID, whatever the case; with a group
 * in its settings, only a member of it; and two users of Paperless with one user of
 * Nextcloud get neither, since Paperless Sync doesn't guess which one is meant.
 *
 * A file of such a user counts only for the documents of that user's owner: whoever renames
 * their own file after a document of another owner gives it a marker that counts for none.
 * The usernames of Paperless come from /api/users/, which the cache of Nextcloud keeps for
 * ten minutes, for every search of that time. Without them, as for an account that may not
 * view users, the search shows only the files of the archive account, as before, and the
 * list rests for a while, so that every search doesn't wait for it.
 */
final class DocumentOwners {
	/** The seconds that the cache keeps the users of Paperless, and a list that it refused. */
	private const TTL = 600;

	/** The seconds that the list rests after a request without answer. */
	private const UNANSWERED_TTL = 60;

	private ?ICache $cache = null;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PaperlessApiService $paperlessApi,
		private ConfigService $configService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The users of Nextcloud whose own files stand for these documents as well, by the IDs of
	 * the documents: only of those whose owner has such a user, and none at all unless
	 * Paperless Sync gives each owner an archive.
	 *
	 * @param iterable<array-key, array<array-key, mixed>> $documents as Paperless sends them,
	 *                                                                with their fields id and owner
	 * @return array<int, string> the ID of the user of Nextcloud, by the ID of the document
	 */
	public function of(iterable $documents): array {
		if (!$this->configService->hasArchivesOfEachOwner()) {
			return [];
		}

		/** @var array<int, int> $owners the ID of the owner in Paperless, by the ID of the document */
		$owners = [];
		foreach ($documents as $document) {
			/** @psalm-suppress MixedAssignment Both are checked before use. */
			$id = $document['id'] ?? null;
			/** @psalm-suppress MixedAssignment */
			$owner = $document['owner'] ?? null;
			if (is_int($id) && $id > 0 && is_int($owner) && $owner > 0) {
				$owners[$id] = $owner;
			}
		}
		if ($owners === []) {
			return [];
		}

		try {
			$paperlessUsers = $this->paperlessUsers();
			if ($paperlessUsers === null) {
				return [];
			}
			$users = $this->usersOf($paperlessUsers, array_unique($owners));
		} catch (Throwable $error) {
			// Such as a cache or a backend of users that fails: the files of the archive
			// account still count.
			$this->logger->warning('The owners of Paperless documents could not be matched with users of Nextcloud ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);

			return [];
		}

		$documentUsers = [];
		foreach ($owners as $id => $owner) {
			if (isset($users[$owner])) {
				$documentUsers[$id] = $users[$owner];
			}
		}

		return $documentUsers;
	}

	/**
	 * The usernames of the users of Paperless by their IDs, from the cache or with one request
	 * of each page of the list, or null while they are unknown: while the list rests, after
	 * Paperless refused it to the token, which may not view users, for ten minutes, and after a
	 * request without answer for a minute.
	 *
	 * @return ?array<int, string>
	 */
	private function paperlessUsers(): ?array {
		$server = hash('sha256', $this->configService->getUrl());
		$cached = self::usersIn($this->getCache()->get(self::usersKey($server)));
		if ($cached !== null) {
			return $cached;
		}
		if ($this->getCache()->get(self::restingKey($server)) === true) {
			return null;
		}

		try {
			$users = $this->paperlessApi->findUsers();
		} catch (PaperlessRequestException $failure) {
			$this->getCache()->set(self::restingKey($server), true, $failure->reason === RequestFailure::Unauthorized ? self::TTL : self::UNANSWERED_TTL);
			$this->logger->info('Paperless answered the request for its users without them, so only the files of the archive account stand for documents ({error})', [
				'app' => AppConstants::APP_ID,
				'error' => $failure->getMessage(),
			]);

			return null;
		} catch (Throwable $error) {
			$this->getCache()->set(self::restingKey($server), true, self::UNANSWERED_TTL);
			$this->logger->info('Paperless gave no users, so only the files of the archive account stand for documents ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);

			return null;
		}

		$this->getCache()->set(self::usersKey($server), $users, self::TTL);

		return $users;
	}

	/**
	 * The users of Nextcloud whom Paperless Sync gives the documents of these users of
	 * Paperless, by its rules, without those that get none: a user that Paperless didn't
	 * list, one without a user of Nextcloud, one outside the group, and two with the same
	 * user of Nextcloud, which Paperless Sync finds among all users of Paperless.
	 *
	 * @param array<int, string> $paperlessUsers the usernames of every user of Paperless, by ID
	 * @param array<int, int> $wanted the IDs of the users of Paperless to match
	 * @return array<int, string> the ID of the user of Nextcloud, by the ID of the user of Paperless
	 */
	private function usersOf(array $paperlessUsers, array $wanted): array {
		$table = self::tableOf($this->configService->getSyncUserMap());
		// How many users of Paperless name each user of Nextcloud, whatever its case: by the
		// table, or else by their own name.
		$named = [];
		foreach ($paperlessUsers as $name) {
			$user = mb_strtolower($table[mb_strtolower($name)] ?? $name);
			$named[$user] = ($named[$user] ?? 0) + 1;
		}

		$group = $this->configService->getSyncUserGroup();
		$users = [];
		foreach ($wanted as $paperlessId) {
			if (!isset($paperlessUsers[$paperlessId])) {
				continue;
			}
			$name = mb_strtolower($paperlessUsers[$paperlessId]);
			$user = $this->userManager->get($table[$name] ?? $paperlessUsers[$paperlessId]);
			// Nextcloud finds a user whatever the case of the ID: by the name, only the same
			// ID counts, as for Paperless Sync.
			if ($user === null || (!isset($table[$name]) && mb_strtolower($user->getUID()) !== $name)) {
				continue;
			}
			$uid = $user->getUID();
			if (($named[mb_strtolower($uid)] ?? 0) > 1 || ($group !== '' && !$this->groupManager->isInGroup($uid, $group))) {
				continue;
			}
			$users[$paperlessId] = $uid;
		}

		return $users;
	}

	/**
	 * The table Paperless users with another Nextcloud ID: the user of Nextcloud by the user
	 * of Paperless, in lower case, as Paperless Sync reads it. A line that is no such
	 * mapping, which Paperless Sync refuses to save, counts for nothing.
	 *
	 * @return array<string, string>
	 */
	private static function tableOf(string $map): array {
		$table = [];
		foreach (preg_split('/\R/u', $map) ?: [] as $line) {
			$parts = array_map('trim', explode('=', $line));
			if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
				$table[mb_strtolower($parts[0])] = $parts[1];
			}
		}

		return $table;
	}

	/**
	 * The usernames that the cache holds, or null for anything else.
	 *
	 * @return ?array<int, string>
	 */
	private static function usersIn(mixed $cached): ?array {
		if (!is_array($cached)) {
			return null;
		}

		$users = [];
		/** @psalm-suppress MixedAssignment Each name is checked below. */
		foreach ($cached as $id => $name) {
			if (!is_int($id) || !is_string($name)) {
				return null;
			}
			$users[$id] = $name;
		}

		return $users;
	}

	private function getCache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed(AppConstants::APP_ID);
	}

	private static function usersKey(string $server): string {
		return 'users-' . $server;
	}

	private static function restingKey(string $server): string {
		return 'users-resting-' . $server;
	}
}
