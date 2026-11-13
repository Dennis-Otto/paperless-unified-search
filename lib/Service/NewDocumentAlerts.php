<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\AlertChoice;
use OCA\PaperlessUnifiedSearch\Model\AlertProgress;
use OCA\PaperlessUnifiedSearch\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One run of the job of the notifications about new documents. Paperless knows nothing of
 * the files and the users of Nextcloud, and a new document has no file yet until Paperless
 * Sync or a similar tool writes it, minutes or hours later. So a run:
 *
 * 1. asks Paperless once which documents are new, by their IDs, and keeps them waiting;
 * 2. looks for the files of the waiting documents in the archive account, which costs
 *    Paperless nothing, and goes no further while none has a file;
 * 3. asks Paperless once per distinct saved search which of those documents it finds;
 * 4. sends each user one notification about those that they asked for and can open.
 *
 * So with nothing new, Paperless gets one short request and nothing else. A user who asks
 * for the first time starts from what is new from then on, as does the first run after the
 * administrators allowed the notifications or changed the Paperless server. Failures go to
 * the log, not into the history of the problems of the search, and each one makes the job
 * wait longer before it asks Paperless again.
 */
final class NewDocumentAlerts {
	/** The setting that keeps the progress, a lazy value: only the job reads it. */
	public const PROGRESS = 'new_document_alerts_progress';

	/**
	 * How many new documents one run takes at most, as many as Paperless sends in one request
	 * of this app: of a larger import, only the newest count, which no notification lists
	 * in full anyway.
	 */
	public const BATCH = 100;

	/** How many documents wait at most for their files, the newest ones. */
	public const MOST_WAITING = 200;

	/**
	 * The seconds that a document waits for its file, after which it counts as one that
	 * never gets a file, such as one that the rules of Paperless Sync leave out.
	 */
	public const LONGEST_WAIT = 2 * 86400;

	/**
	 * How far before the last answer of Paperless the next request looks, in seconds. The
	 * IDs say which documents are new; the day only keeps the answer short, and a day of
	 * margin covers the time zones of the two servers, which needn't be the same.
	 */
	private const MARGIN = 86400;

	/**
	 * How far the moment of the last answer of Paperless has to move before a run that
	 * changed nothing else stores it, in seconds. The next request asks from the day before
	 * it (MARGIN), so a moment an hour older asks for the same documents; and every write
	 * of the configuration of the apps empties the cache of it for all of Nextcloud, which
	 * a run every five minutes would do 288 times a day.
	 */
	private const CHECKED_AT_STEP = 3600;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private SavedSearches $savedSearches,
		private PaperlessApiService $paperlessApi,
		private NextcloudFileLocator $fileLocator,
		private IUserManager $userManager,
		private IManager $notifications,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private DocumentOwners $documentOwners,
		private UserTags $userTags,
	) {
	}

	public function run(): void {
		$archiveOwner = $this->configService->getArchiveOwner();
		$choices = $this->configService->isNewDocumentAlertsAllowed() && $this->configService->isConfigured() && $archiveOwner !== ''
			? $this->savedSearches->ofEveryone()
			: [];
		if ($choices === []) {
			// Whoever asks next starts from then on, not from the documents of this pause. A
			// deletion empties the cache of the configuration of all apps even without the
			// key, so only a progress that is there goes.
			if ($this->appConfig->hasKey(AppConstants::APP_ID, self::PROGRESS, lazy: true)) {
				$this->appConfig->deleteKey(AppConstants::APP_ID, self::PROGRESS);
			}

			return;
		}

		$now = $this->timeFactory->getTime();
		$server = hash('sha256', $this->configService->getUrl());
		$stored = AlertProgress::fromStored($this->appConfig->getValueArray(AppConstants::APP_ID, self::PROGRESS, [], lazy: true), $server);
		$progress = $stored ?? new AlertProgress($server, null, $now);
		if ($progress->restingUntil > $now) {
			return;
		}

		try {
			if ($progress->lastSeen === null) {
				$this->store(new AlertProgress($server, $this->highestId(), $now));

				return;
			}

			$progress = $this->withNewDocuments($progress, $progress->lastSeen, $now);
			$waiting = $this->documentsOf($progress);
			$documentUsers = $this->documentOwners->of($waiting);
			$files = $this->filesOfTheArchive($progress, $archiveOwner, $documentUsers);
			$found = $files === [] ? [] : $this->findTerms($choices, array_keys($files));
			$documents = self::arrived($waiting, $files);
		} catch (Throwable $error) {
			$this->rest($progress, $now, $error);

			return;
		}

		// Stored before the first notification goes out: a run that dies halfway costs a few
		// users a notification, which is better than one that repeats for everyone. A run
		// without news stores nothing.
		$next = new AlertProgress(
			$server,
			$progress->lastSeen,
			$progress->checkedAt,
			array_diff_key($progress->waiting, $files),
		);
		if ($next->differsFrom($stored, self::CHECKED_AT_STEP)) {
			$this->store($next);
		}
		if ($files === []) {
			return;
		}

		foreach ($choices as $userId => $choice) {
			// PHP keeps the ID of a user like 12345 as a number in the keys of the array.
			$this->notifyUser((string)$userId, self::wantedOf($choice, array_keys($files), $found), $progress, $archiveOwner, $documents);
		}
	}

	/**
	 * How far the IDs of the server go now: what it holds counts as seen, so the first run
	 * notifies nobody about the archive of years. One document tells.
	 */
	private function highestId(): int {
		/** @psalm-suppress MixedAssignment The ID is checked before use. */
		$id = $this->paperlessApi->findNewestDocuments(1, null)[0]['id'] ?? null;

		return is_int($id) ? $id : 0;
	}

	/**
	 * The progress with the documents that Paperless added since the last run in waiting,
	 * and without those that waited too long.
	 */
	private function withNewDocuments(AlertProgress $progress, int $lastSeen, int $now): AlertProgress {
		$documents = $this->paperlessApi->findNewestDocuments(self::BATCH, gmdate('Y-m-d', $progress->checkedAt - self::MARGIN));

		$highest = $lastSeen;
		$new = [];
		foreach ($documents as $document) {
			/** @psalm-suppress MixedAssignment The ID is checked before use. */
			$id = $document['id'] ?? null;
			if (is_int($id) && $id > $lastSeen) {
				/** @psalm-suppress MixedAssignment The title is checked before use. */
				$title = $document['title'] ?? null;
				/** @psalm-suppress MixedAssignment The owner is checked before use. */
				$owner = $document['owner'] ?? null;
				$new[$id] = ['title' => is_string($title) ? PlainText::oneLine($title) : '', 'since' => $now] + (is_int($owner) ? ['owner' => $owner] : []);
				$highest = max($highest, $id);
			}
		}
		if (count($new) === self::BATCH) {
			$this->logger->info('Paperless added {count} or more documents between two runs of the notifications about new documents: older ones get none', [
				'app' => AppConstants::APP_ID,
				'count' => self::BATCH,
			]);
		}

		$waiting = array_filter(
			$new + $progress->waiting,
			static fn (array $document): bool => $now - $document['since'] < self::LONGEST_WAIT,
		);
		krsort($waiting);

		return new AlertProgress(
			$progress->server,
			$highest,
			$now,
			array_slice($waiting, 0, self::MOST_WAITING, true),
			$progress->failures,
		);
	}

	/**
	 * The waiting documents with the IDs of their owners in Paperless, as DocumentOwners
	 * reads them.
	 *
	 * @return list<array{id: int, owner: ?int}>
	 */
	private static function ownersOf(AlertProgress $progress): array {
		$documents = [];
		foreach ($progress->waiting as $id => $document) {
			$documents[] = ['id' => $id, 'owner' => $document['owner'] ?? null];
		}

		return $documents;
	}

	/**
	 * The waiting documents with the IDs of their owners: as the job first saw them, or,
	 * while some users may see only the documents with some tags (decision 0015), as
	 * Paperless has them now, with their tags, which may have come or gone since, in one
	 * request. Their tags decide both where the file of each one arrives, since Paperless
	 * Sync writes a document of an owner with tags into that owner's own folder only when
	 * it carries one of them, and which user each run below may tell about it.
	 *
	 * @return list<array<array-key, mixed>>
	 * @throws Throwable when Paperless doesn't send them
	 */
	private function documentsOf(AlertProgress $progress): array {
		$documentIds = array_keys($progress->waiting);
		if ($documentIds === [] || $this->userTags->lines() === []) {
			return self::ownersOf($progress);
		}

		return $this->paperlessApi->findDocumentTags($documentIds);
	}

	/**
	 * Those of the documents whose files have arrived, which each user's run below goes
	 * through.
	 *
	 * @param list<array<array-key, mixed>> $documents
	 * @param array<int, File> $files the files that have arrived, by document
	 * @return list<array<array-key, mixed>>
	 */
	private static function arrived(array $documents, array $files): array {
		$arrived = [];
		foreach ($documents as $document) {
			/** @psalm-suppress MixedAssignment Checked before use. */
			$id = $document['id'] ?? null;
			if (is_int($id) && isset($files[$id])) {
				$arrived[] = $document;
			}
		}

		return $arrived;
	}

	/**
	 * The files of the waiting documents in the archive account, or in the files of the
	 * user whom Paperless Sync gives their owner (decision 0013), highest ID first, of those
	 * that have one by now. Who else can open them, each user's run below finds out.
	 *
	 * @param array<int, string> $documentUsers the user of the owner, by the ID of the document
	 * @return array<int, File>
	 */
	private function filesOfTheArchive(AlertProgress $progress, string $archiveOwner, array $documentUsers): array {
		$archive = $this->userManager->get($archiveOwner);
		if ($archive === null) {
			return [];
		}

		$files = $this->fileLocator->findAllForUser($archive, array_keys($progress->waiting), $archiveOwner, $documentUsers)->files;
		foreach (array_unique($documentUsers) as $holder) {
			$account = $this->userManager->get($holder);
			$held = array_values(array_diff(array_keys($documentUsers, $holder, true), array_keys($files)));
			if ($account !== null && $held !== []) {
				$files += $this->fileLocator->findAllForUser($account, $held, $archiveOwner, $documentUsers)->files;
			}
		}
		krsort($files);

		return $files;
	}

	/**
	 * Which of the documents each distinct saved search finds, with one request per search.
	 * A search that Paperless refuses finds nothing, and the log names the users whose
	 * searches it refused, without the searches: the words of a user stay out of the log.
	 * The personal settings check a new search as it is saved; one saved before that check,
	 * or before Paperless read it differently, finds its users this way. A search that
	 * fails, such as a wildcard too expensive for Paperless to answer in time, finds nothing
	 * as well, and the log names its users in the same way: one user's search stops nobody
	 * else's notifications, also when it is the only search of the instance or every search
	 * of this run fails (#414). Whether Paperless is down, the requests before decide, which
	 * hold no words of a user and have already answered in this run, for the new documents
	 * and their tags: a failure of those rests the job.
	 *
	 * @param array<array-key, AlertChoice> $choices
	 * @param non-empty-list<int> $documentIds
	 * @return array<string, list<int>> the IDs of the documents that each term finds
	 */
	private function findTerms(array $choices, array $documentIds): array {
		$found = [];
		$refused = [];
		$failed = [];
		$failure = null;
		foreach ($choices as $choice) {
			foreach ($choice->everyDocument ? [] : $choice->terms as $term) {
				if (isset($found[$term])) {
					continue;
				}

				try {
					$found[$term] = $this->paperlessApi->findMatchingDocuments($term, $documentIds);
				} catch (PaperlessQueryRejected) {
					$found[$term] = [];
					$refused[$term] = true;
				} catch (Throwable $error) {
					$found[$term] = [];
					$failed[$term] = true;
					$failure = $error;
				}
			}
		}
		if ($failure !== null) {
			$this->logger->warning('{count} saved search(es) of the notifications about new Paperless documents failed ({errorType}), of the users {users}: they find nothing in this run', [
				'app' => AppConstants::APP_ID,
				'count' => count($failed),
				'errorType' => $failure::class,
				'users' => implode(', ', self::usersOf($choices, $failed)),
			]);
		}
		if ($refused !== []) {
			$this->logger->info('Paperless refused {count} saved search(es) of the notifications about new documents, of the users {users}: they find nothing until the users correct them in their personal settings', [
				'app' => AppConstants::APP_ID,
				'count' => count($refused),
				'users' => implode(', ', self::usersOf($choices, $refused)),
			]);
		}

		return $found;
	}

	/**
	 * The users whose searches in use hold one of these terms, as the log names them.
	 *
	 * @param array<array-key, AlertChoice> $choices by the ID of the user
	 * @param array<string, true> $terms
	 * @return list<string>
	 */
	private static function usersOf(array $choices, array $terms): array {
		$users = [];
		foreach ($choices as $userId => $choice) {
			if (!$choice->everyDocument && array_intersect_key(array_flip($choice->terms), $terms) !== []) {
				$users[] = (string)$userId;
			}
		}

		return $users;
	}

	/**
	 * The documents that a user asked for, in their order.
	 *
	 * @param list<int> $documentIds
	 * @param array<string, list<int>> $found the IDs of the documents that each term finds
	 * @return list<int>
	 */
	private static function wantedOf(AlertChoice $choice, array $documentIds, array $found): array {
		if ($choice->everyDocument) {
			return $documentIds;
		}

		$wanted = [];
		foreach ($choice->terms as $term) {
			$wanted = [...$wanted, ...$found[$term]];
		}

		return array_values(array_intersect($documentIds, $wanted));
	}

	/**
	 * One notification about the documents whose files the user can open, and none without
	 * any; for a user with tags only about those with one of them. A user whose files fail
	 * to answer costs only their own notification.
	 *
	 * @param list<int> $documentIds
	 * @param list<array<array-key, mixed>> $documents the documents with files, with their
	 *                                                 owners and tags
	 */
	private function notifyUser(string $userId, array $documentIds, AlertProgress $progress, string $archiveOwner, array $documents): void {
		try {
			$user = $this->userManager->get($userId);
			if ($user === null || !$user->isEnabled()) {
				return;
			}

			[$visibleIds, $documentUsers] = $this->documentOwners->forUser($user, $documentIds, $documents);
			$files = $this->fileLocator->findAllForUser($user, $visibleIds, $archiveOwner, $documentUsers)->files;
			if ($files !== []) {
				$this->notify($user, $files, $progress);
			}
		} catch (Throwable $error) {
			$this->logger->warning('A user got no notification about new Paperless documents ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);
		}
	}

	/**
	 * @param array<int, File> $files the files that the user can open, by document, highest ID first
	 */
	private function notify(IUser $user, array $files, AlertProgress $progress): void {
		$documents = [];
		foreach (array_slice($files, 0, Notifier::MOST_LISTED, true) as $id => $file) {
			$title = $progress->waiting[$id]['title'] ?? '';
			$documents[] = [
				'title' => $title !== '' ? $title : $file->getName(),
				'fileId' => $file->getId(),
				'path' => $this->fileLocator->getPathForUser($user, $file),
			];
		}

		$notification = $this->notifications->createNotification();
		$notification
			->setApp(AppConstants::APP_ID)
			->setUser($user->getUID())
			->setDateTime($this->timeFactory->getDateTime())
			->setObject(Notifier::OBJECT_TYPE, (string)array_key_first($files))
			->setSubject(Notifier::SUBJECT, ['count' => count($files), 'documents' => $documents]);
		$this->notifications->notify($notification);
	}

	/**
	 * Keeps what the run has seen, and rests for longer with each failure in a row: while
	 * Paperless is down, the job asks less and less often, and at least once an hour.
	 */
	private function rest(AlertProgress $progress, int $now, Throwable $error): void {
		$failures = $progress->failures + 1;
		$seconds = match ($failures) {
			1 => 600,
			2 => 1200,
			3 => 2400,
			default => 3600,
		};
		$this->store(new AlertProgress($progress->server, $progress->lastSeen, $progress->checkedAt, $progress->waiting, $failures, $now + $seconds));
		$this->logger->warning('The notifications about new Paperless documents try again in {seconds} seconds ({error})', [
			'app' => AppConstants::APP_ID,
			'seconds' => $seconds,
			// The message of a failed request may hold its query, with the words of a user.
			'error' => $error instanceof PaperlessRequestException ? $error->getMessage() : $error::class,
		]);
	}

	private function store(AlertProgress $progress): void {
		$this->appConfig->setValueArray(AppConstants::APP_ID, self::PROGRESS, $progress->toStored(), lazy: true);
	}
}
