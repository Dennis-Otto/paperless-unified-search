<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Dashboard;

use DateTime;
use Exception;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\FilePictures;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessNames;
use OCA\PaperlessUnifiedSearch\Service\PlainText;
use OCA\PaperlessUnifiedSearch\Service\RecentDocuments;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Files\File;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "New in Paperless" on Nextcloud's dashboard, on the web and in the apps for
 * phones: the documents that Paperless added last, newest first, each with the preview
 * of its file, the day that Paperless added it, its correspondent and its type. As in
 * the search, a user sees only the documents whose file of the archive account they can
 * open. Nextcloud draws the widget from its items alone, so it needs no script.
 */
final class RecentDocumentsWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IConditionalWidget {
	public const ID = AppConstants::APP_ID . '-recent';

	/**
	 * The moment that Paperless added a document, as it sends it, such as
	 * 2026-08-27T14:00:00.123456+02:00.
	 */
	private const MOMENT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private ConfigService $configService,
		private RecentDocuments $recentDocuments,
		private NextcloudFileLocator $fileLocator,
		private PaperlessNames $names,
		private FilePictures $pictures,
		private IDateTimeFormatter $dateTimeFormatter,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getTitle(): string {
		return $this->l10n->t('New in Paperless');
	}

	/**
	 * The place among the widgets that a user adds: after Nextcloud's own of files.
	 */
	public function getOrder(): int {
		return 30;
	}

	/**
	 * The icon of files of Nextcloud, for a client that shows no icon by its URL;
	 * Nextcloud's own dashboard shows the one of getIconUrl().
	 */
	public function getIconClass(): string {
		return 'icon-files-dark';
	}

	/**
	 * A dark icon, as the dashboard expects it: its dark theme and the apps for phones
	 * invert it, which would turn the colors of the icon of the app into others.
	 */
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(AppConstants::APP_ID, 'app-dark.svg'),
		);
	}

	/**
	 * The app has no page of its own: a document opens in Files.
	 */
	public function getUrl(): ?string {
		return null;
	}

	public function load(): void {
	}

	/**
	 * Only where the search works: with a Paperless to ask and an archive account, whose
	 * files stand for its documents.
	 */
	public function isEnabled(): bool {
		return $this->configService->isConfigured() && $this->configService->getArchiveOwner() !== '';
	}

	/**
	 * More of the documents that others shared with the user, in Files; the archive
	 * account owns them all, so it gets no such link.
	 *
	 * @return list<WidgetButton>
	 */
	public function getWidgetButtons(string $userId): array {
		if ($userId === $this->configService->getArchiveOwner()) {
			return [];
		}

		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->urlGenerator->linkToRouteAbsolute('files.view.indexView', ['view' => 'sharingin']),
				$this->l10n->t('Files shared with you'),
			),
		];
	}

	/**
	 * The newest documents that the user can open, at most as many as the dashboard asks
	 * for, and with $since only those that Paperless added after that moment, the sinceId
	 * of an item. A file that fails to answer costs only its own item, and a Paperless
	 * that fails no more than an empty widget that says so.
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$user = $this->userManager->get($userId);
		$archiveOwner = $this->configService->getArchiveOwner();
		if ($user === null || $archiveOwner === '' || !$this->configService->isConfigured()) {
			return new WidgetItems();
		}

		try {
			$documents = $this->recentDocuments->find();
			$items = $documents === null ? null : $this->createItems($user, $archiveOwner, self::addedAfter($documents, $since), max(1, $limit));
		} catch (Throwable $error) {
			$this->logger->warning('The dashboard could not show the newest Paperless documents ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);
			$items = null;
		}

		if ($items === null) {
			return new WidgetItems([], $this->l10n->t('Paperless can\'t be reached right now.'));
		}

		return new WidgetItems(
			$items,
			$items === [] ? $this->l10n->t('None of the newest Paperless documents has a file that you can open.') : '',
		);
	}

	/**
	 * The items of the documents that the user can open, in their order, at most as many
	 * as wanted, with the names of their correspondents and types.
	 *
	 * @param list<array<array-key, mixed>> $documents
	 * @return list<WidgetItem>
	 */
	private function createItems(IUser $user, string $archiveOwner, array $documents, int $wanted): array {
		$ids = [];
		foreach ($documents as $document) {
			$id = self::idOf($document);
			if ($id !== null) {
				$ids[] = $id;
			}
		}
		$files = $this->fileLocator->findAllForUser($user, $ids, $archiveOwner)->files;

		/** @var list<array{array<array-key, mixed>, File}> $shown the documents with a file, and the file */
		$shown = [];
		foreach ($documents as $document) {
			$id = self::idOf($document);
			if ($id !== null && isset($files[$id]) && count($shown) < $wanted) {
				$shown[] = [$document, $files[$id]];
			}
		}
		$names = $this->names->of(array_column($shown, 0));

		$items = [];
		$leftOut = 0;
		$firstError = null;
		foreach ($shown as [$document, $file]) {
			try {
				$items[] = $this->createItem($document, $file, $names);
			} catch (Throwable $error) {
				$leftOut++;
				$firstError ??= $error;
			}
		}

		if ($firstError !== null) {
			$this->logger->warning('The dashboard left out {count} Paperless document(s) whose files failed to answer ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => $leftOut,
				'errorType' => $firstError::class,
			]);
		}

		return $items;
	}

	/**
	 * The item of a document: its title, a line with the day that Paperless added it, its
	 * correspondent and its type, such as "Aug 27, 2026 · ACME Energy · Invoice", the link
	 * of its file in Files, the preview of the file, and the moment that Paperless added
	 * it as its sinceId.
	 *
	 * @param array<array-key, mixed> $document
	 * @param array<string, array<int, string>> $names the names of correspondents and types, as PaperlessNames finds them
	 */
	private function createItem(array $document, File $file, array $names): WidgetItem {
		/** @psalm-suppress MixedAssignment The title is checked before use. */
		$title = $document['title'] ?? null;
		$title = is_string($title) ? PlainText::oneLine($title) : '';
		if ($title === '') {
			$title = $file->getName();
		}

		$facts = [];
		$added = self::addedOf($document);
		$moment = self::momentOf($added);
		if ($moment !== null) {
			$facts[] = $this->dateTimeFormatter->formatDate($moment, 'medium');
		}
		foreach (array_keys(PaperlessNames::FIELDS) as $field) {
			$id = PaperlessNames::idOf($document, $field);
			$name = $id === null ? '' : PlainText::oneLine($names[$field][$id] ?? '');
			if ($name !== '') {
				$facts[] = $name;
			}
		}

		return new WidgetItem(
			$title,
			PlainText::withoutMarkup(implode(' · ', $facts)),
			$this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $file->getId()]),
			$this->pictures->urlOf($file),
			$moment === null ? '' : $added,
		);
	}

	/**
	 * The documents that Paperless added after the moment $since, all of them without one;
	 * a moment that is none, such as an empty sinceId, leaves them all as well.
	 *
	 * @param list<array<array-key, mixed>> $documents
	 * @return list<array<array-key, mixed>>
	 */
	private static function addedAfter(array $documents, ?string $since): array {
		$after = self::momentOf($since ?? '');
		if ($after === null) {
			return $documents;
		}

		$later = [];
		foreach ($documents as $document) {
			$added = self::momentOf(self::addedOf($document));
			if ($added !== null && $added > $after) {
				$later[] = $document;
			}
		}

		return $later;
	}

	/**
	 * @param array<array-key, mixed> $document
	 */
	private static function idOf(array $document): ?int {
		/** @psalm-suppress MixedAssignment The ID is checked before use. */
		$id = $document['id'] ?? null;

		return is_int($id) ? $id : null;
	}

	/**
	 * The moment that Paperless added a document, as it sent it, or '' for none.
	 *
	 * @param array<array-key, mixed> $document
	 */
	private static function addedOf(array $document): string {
		/** @psalm-suppress MixedAssignment The moment is checked before use. */
		$added = $document['added'] ?? null;

		return is_string($added) ? $added : '';
	}

	/**
	 * A moment as Paperless writes it, in the timezone that it names, or null for any
	 * other text, also for a day that doesn't exist, such as 2026-02-30, which PHP would
	 * turn into March 2.
	 */
	private static function momentOf(string $text): ?DateTime {
		if (preg_match(self::MOMENT_PATTERN, $text) !== 1) {
			return null;
		}

		try {
			$moment = new DateTime($text);
		} catch (Exception) {
			return null;
		}

		return $moment->format('Y-m-d') === substr($text, 0, 10) ? $moment : null;
	}
}
