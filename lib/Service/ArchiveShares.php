<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\Model\ArchiveShare;
use OCA\PaperlessUnifiedSearch\Model\ShareOverview;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Throwable;

/**
 * With whom the archive account shares its files and folders, for the settings, occ and
 * the overview of the administration settings: the shares decide who finds which
 * document, and a share that allows changes lets its recipients give a file of the
 * archive account the marker of any document, so the search leaves out the files that a
 * user may write for that user. The list reads the database of Nextcloud only, and
 * changes nothing, also not the expired shares, which Nextcloud deletes by itself.
 */
final class ArchiveShares {
	/**
	 * The kinds of shares by their type in Nextcloud, of every app that may share files:
	 * Teams, Talk, Deck and the federation share through apps of their own, and a type
	 * whose app isn't enabled has no shares.
	 */
	private const KINDS = [
		IShare::TYPE_USER => ArchiveShare::KIND_USER,
		IShare::TYPE_GROUP => ArchiveShare::KIND_GROUP,
		IShare::TYPE_CIRCLE => ArchiveShare::KIND_TEAM,
		IShare::TYPE_ROOM => ArchiveShare::KIND_OTHER,
		IShare::TYPE_DECK => ArchiveShare::KIND_OTHER,
		IShare::TYPE_REMOTE => ArchiveShare::KIND_OTHER,
		IShare::TYPE_REMOTE_GROUP => ArchiveShare::KIND_OTHER,
		IShare::TYPE_LINK => ArchiveShare::KIND_LINK,
		IShare::TYPE_EMAIL => ArchiveShare::KIND_LINK,
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IManager $shareManager,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * The shares of the files and folders that the account owns, also those that its
	 * recipients passed on, by path, kind and recipient: none that expired, and none of a
	 * file that no longer exists.
	 */
	public function of(string $archiveOwner): ShareOverview {
		if ($archiveOwner === '') {
			return new ShareOverview([]);
		}

		$now = $this->timeFactory->getTime();
		$shares = [];
		$error = null;
		foreach (self::KINDS as $type => $kind) {
			try {
				// The shares that its recipients passed on as well, and the expired ones,
				// which the list leaves out instead of deleting them as Nextcloud would.
				$found = $this->shareManager->getSharesBy($archiveOwner, $type, null, true, -1, 0, false);
			} catch (Throwable $exception) {
				$error ??= $exception;
				continue;
			}

			foreach ($found as $share) {
				try {
					$expiration = $share->getExpirationDate();
					if ($share->getShareOwner() !== $archiveOwner || ($expiration !== null && $expiration->getTimestamp() <= $now)) {
						continue;
					}
					$path = self::pathOf($archiveOwner, $share);
					$shares[] = new ArchiveShare($kind, $kind === ArchiveShare::KIND_LINK ? '' : self::recipientOf($share), $path, $share->getPermissions());
				} catch (NotFoundException) {
					// A share of a file that no longer exists grants nothing.
				} catch (Throwable $exception) {
					$error ??= $exception;
				}
			}
		}

		usort($shares, static fn (ArchiveShare $first, ArchiveShare $second): int => [$first->path, array_search($first->kind, ShareOverview::KINDS, true), $first->recipient]
			<=> [$second->path, array_search($second->kind, ShareOverview::KINDS, true), $second->recipient]);

		return new ShareOverview($shares, $error);
	}

	/**
	 * How the archive account shares its files, in one sentence, such as "Shared read-only:
	 * 1 user, 2 groups."
	 */
	public function summarize(ShareOverview $overview): string {
		$parts = [];
		foreach ($overview->counts() as $kind => $count) {
			$parts[] = match ($kind) {
				ArchiveShare::KIND_USER => $this->l10n->n('%n user', '%n users', $count),
				ArchiveShare::KIND_GROUP => $this->l10n->n('%n group', '%n groups', $count),
				ArchiveShare::KIND_TEAM => $this->l10n->n('%n team', '%n teams', $count),
				ArchiveShare::KIND_OTHER => $this->l10n->n('%n other share', '%n other shares', $count),
				ArchiveShare::KIND_LINK => $this->l10n->n('%n public link', '%n public links', $count),
			};
		}

		$summary = match (true) {
			$parts === [] => $this->l10n->t('The archive account shares none of its files, so only it finds its documents.'),
			$overview->writable() === [] => $this->l10n->t('Shared read-only: %s.', [implode(', ', $parts)]),
			default => $this->l10n->t('Shared: %s.', [implode(', ', $parts)]),
		};

		return $overview->error === null
			? $summary
			: $summary . ' ' . $this->l10n->t('Not every share could be read, so the list may lack some.');
	}

	/**
	 * What is wrong with each share that allows changes, and what corrects it, in the order
	 * of the shares.
	 *
	 * @return list<string>
	 */
	public function warnings(ShareOverview $overview): array {
		return array_map(fn (ArchiveShare $share): string => match ($share->kind) {
			ArchiveShare::KIND_USER => $this->l10n->t('%1$s is shared with the user %2$s with write permission, so the search shows %2$s none of the documents in it. Share it read-only.', [$share->path, $share->recipient]),
			ArchiveShare::KIND_GROUP => $this->l10n->t('%1$s is shared with the group %2$s with write permission, so the search shows its members none of the documents in it. Share it read-only.', [$share->path, $share->recipient]),
			ArchiveShare::KIND_TEAM => $this->l10n->t('%1$s is shared with the team %2$s with write permission, so the search shows its members none of the documents in it. Share it read-only.', [$share->path, $share->recipient]),
			ArchiveShare::KIND_OTHER => $this->l10n->t('%1$s is shared with %2$s with write permission, so the search shows its recipients none of the documents in it. Share it read-only.', [$share->path, $share->recipient]),
			ArchiveShare::KIND_LINK => $this->l10n->t('%s has a public link that allows uploads or changes, so whoever has the link can add a file with the marker of any document. Make the link read-only.', [$share->path]),
		}, $overview->writable());
	}

	/**
	 * The recipient by the name that Nextcloud shows for it, such as the name of a team or
	 * a conversation, or else by its ID, as of a user or a group.
	 */
	private static function recipientOf(IShare $share): string {
		/** @var ?string $name Nextcloud leaves it null where it has none, unlike what its docblock says */
		$name = $share->getSharedWithDisplayName();

		return $name !== null && $name !== '' ? $name : $share->getSharedWith();
	}

	/**
	 * The path of the shared file or folder in the folders of the archive account, such as
	 * /Paperless for /archive/files/Paperless, or else its name.
	 *
	 * @throws NotFoundException when the file no longer exists
	 */
	private static function pathOf(string $archiveOwner, IShare $share): string {
		$node = $share->getNode();
		$path = $node->getPath();
		$folder = '/' . $archiveOwner . '/files/';

		return str_starts_with($path, $folder) ? substr($path, strlen($folder) - 1) : '/' . $node->getName();
	}
}
