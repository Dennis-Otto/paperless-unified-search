<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Reference;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * "Paperless documents" in Nextcloud's smart picker, the menu that "/" opens in Talk,
 * Text, Collectives, Deck and Mail. The picker searches through the search provider of
 * the app, with the same rules of who sees which document, and inserts the link of the
 * chosen result: the Files link of its synchronized file. Nextcloud's own provider of
 * file links renders that link with the permissions of whoever reads it, so this
 * provider resolves no link itself.
 */
final class PaperlessReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
	public const ID = AppConstants::APP_ID . '-documents';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getTitle(): string {
		return $this->l10n->t('Paperless documents');
	}

	/**
	 * The place in the picker: the one of the search provider in Nextcloud's search.
	 */
	public function getOrder(): int {
		return 40;
	}

	/**
	 * A dark icon, as the picker expects it: its dark theme inverts the icons of the
	 * providers, which would turn the colors of the icon of the app into others.
	 */
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(AppConstants::APP_ID, 'picker-dark.svg'),
		);
	}

	/**
	 * @return list<string>
	 */
	public function getSupportedSearchProviderIds(): array {
		return [PaperlessSearchProvider::ID];
	}

	/**
	 * A result links to its file, which Nextcloud's provider of file links matches.
	 */
	public function matchReference(string $referenceText): bool {
		return false;
	}

	public function resolveReference(string $referenceText): ?IReference {
		return null;
	}

	public function getCachePrefix(string $referenceId): string {
		return '';
	}

	public function getCacheKey(string $referenceId): ?string {
		return null;
	}
}
