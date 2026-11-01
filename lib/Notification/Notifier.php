<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Notification;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * The words of the notifications about new documents, in the language of the user who
 * reads them, as NewDocumentAlerts leaves them: how many documents there are, and the
 * title of the newest ones, each a link to its file, which the web and the apps for phones
 * open as they open any file. The notification holds what the user could open as it went
 * out, so reading it costs no look into the files.
 */
final class Notifier implements INotifier {
	public const SUBJECT = 'new_documents';

	public const OBJECT_TYPE = 'paperless_documents';

	/** How many documents a notification names, each with its link. */
	public const MOST_LISTED = 3;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return AppConstants::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(AppConstants::APP_ID)->t('Paperless Unified Search');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== AppConstants::APP_ID || $notification->getSubject() !== self::SUBJECT) {
			throw new UnknownNotificationException();
		}

		$parameters = $notification->getSubjectParameters();
		$documents = self::documentsOf($parameters['documents'] ?? null);
		$count = $parameters['count'] ?? null;
		if ($documents === [] || !is_int($count)) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(AppConstants::APP_ID, $languageCode);
		// A dark icon, as the notifications expect it, which their dark theme inverts.
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(AppConstants::APP_ID, 'app-dark.svg')));

		if ($count === 1) {
			$document = $documents[0];
			$subject = $l->t('New in Paperless: {document}');
			$notification
				->setParsedSubject(str_replace('{document}', $document['title'], $subject))
				->setRichSubject($subject, ['document' => $this->richFile($document)])
				->setLink($this->linkOf($document));

			return $notification;
		}

		$rich = [];
		$names = [];
		foreach ($documents as $index => $document) {
			$rich['document' . $index] = $this->richFile($document);
			$names['{document' . $index . '}'] = $document['title'];
		}
		$more = $count - count($documents);
		$message = implode(', ', array_keys($names)) . ($more > 0 ? ' ' . $l->n('and %n more', 'and %n more', $more) : '');
		$notification
			->setParsedSubject($l->n('%n new document in Paperless', '%n new documents in Paperless', $count))
			->setParsedMessage(strtr($message, $names))
			->setRichMessage($message, $rich);

		return $notification;
	}

	/**
	 * The documents of a notification, without one that it holds wrongly.
	 *
	 * @return list<array{title: string, fileId: int, path: string}>
	 */
	private static function documentsOf(mixed $stored): array {
		$documents = [];
		/** @psalm-suppress MixedAssignment Every document is checked before use. */
		foreach (is_array($stored) ? $stored : [] as $document) {
			/** @psalm-suppress MixedAssignment All three are checked before use. */
			['title' => $title, 'fileId' => $fileId, 'path' => $path] = (is_array($document) ? $document : []) + ['title' => null, 'fileId' => null, 'path' => null];
			if (is_string($title) && is_int($fileId) && is_string($path)) {
				$documents[] = ['title' => $title, 'fileId' => $fileId, 'path' => $path];
			}
		}

		return $documents;
	}

	/**
	 * The file of a document as Nextcloud's rich text names one, which each client shows as
	 * a link and opens in its own way.
	 *
	 * @param array{title: string, fileId: int, path: string} $document
	 * @return array{type: string, id: string, name: string, path: string, link: string}
	 */
	private function richFile(array $document): array {
		return [
			'type' => 'file',
			'id' => (string)$document['fileId'],
			'name' => $document['title'],
			'path' => ltrim($document['path'], '/'),
			'link' => $this->linkOf($document),
		];
	}

	/**
	 * @param array{title: string, fileId: int, path: string} $document
	 */
	private function linkOf(array $document): string {
		return $this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $document['fileId']]);
	}
}
