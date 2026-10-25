<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use DateTime;
use OCA\PaperlessUnifiedSearch\Service\ArchiveShares;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * The shares of the files and folders of the archive account archive, as Nextcloud keeps
 * them, for the tests of what the app says about them.
 */
trait Shares {
	/** The time of the tests, as the clock of Nextcloud tells it. */
	private const NOW = 1791500000;

	/** @var array<int, list<IShare>> the shares of each type in Nextcloud */
	private array $shares = [];

	/** @var list<int> the types of the shares that the app asked for, in its order */
	private array $askedForShares = [];

	/** @var list<string> the accounts whose shares the app asked for, once each */
	private array $askedForSharesOf = [];

	/**
	 * A share of the path in the folders of its owner, such as /Paperless, with the
	 * recipient, read-only unless $permissions say otherwise.
	 */
	private function share(int $type, string $recipient, string $path, int $permissions = Constants::PERMISSION_READ, string $owner = 'archive', ?int $expires = null, ?string $displayName = null): IShare {
		$node = $this->createStub(Folder::class);
		$node->method('getPath')->willReturn('/' . $owner . '/files' . $path);
		$node->method('getName')->willReturn(basename($path));

		$share = $this->createStub(IShare::class);
		$share->method('getShareType')->willReturn($type);
		$share->method('getSharedWith')->willReturn($recipient);
		$share->method('getSharedWithDisplayName')->willReturn($displayName);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getShareOwner')->willReturn($owner);
		$share->method('getExpirationDate')->willReturn($expires === null ? null : new DateTime('@' . $expires));
		$share->method('getNode')->willReturn($node);
		$this->shares[$type][] = $share;

		return $share;
	}

	/**
	 * The manager of the shares of Nextcloud, which answers the app with the shares of a
	 * type, as asked for: of every file of the account, also the shares that others passed
	 * on, all of them, also the expired ones.
	 */
	private function shareManager(): IManager {
		$manager = $this->createStub(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(function (string $userId, int $type, ?Node $path = null, bool $reshares = false, int $limit = 50, int $offset = 0, bool $onlyValid = true): array {
			TestCase::assertSame([null, true, -1, 0, false], [$path, $reshares, $limit, $offset, $onlyValid]);
			$this->askedForShares[] = $type;
			if (!in_array($userId, $this->askedForSharesOf, true)) {
				$this->askedForSharesOf[] = $userId;
			}

			return $this->shares[$type] ?? [];
		});

		return $manager;
	}

	/**
	 * What the app says about the shares of the archive account, with the texts as written,
	 * or with those of $l10n, and the shares of $manager or else those of share().
	 */
	private function archiveShares(?IL10N $l10n = null, ?IManager $manager = null): ArchiveShares {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);

		return new ArchiveShares($manager ?? $this->shareManager(), $clock, $l10n ?? $this->writtenTexts());
	}

	/**
	 * The texts of the app as written, in the singular or the plural, which is what occ
	 * shows in English.
	 */
	private function writtenTexts(string $prefix = ''): IL10N {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $prefix . vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static fn (string $singular, string $plural, int $count, array $parameters = []): string => $prefix . vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $parameters));

		return $l10n;
	}
}
