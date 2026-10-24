<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The section Paperless of the personal settings. Nextcloud lists it only while it holds
 * a setting, which PersonalSettings shows only while the search shows Paperless documents.
 */
final class PersonalSection implements IIconSection {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return AppConstants::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Paperless');
	}

	/**
	 * After the sections of Nextcloud for the account, among those of the apps.
	 */
	public function getPriority(): int {
		return 55;
	}

	/**
	 * The icon as one dark shape, as Nextcloud expects it of a section, like the one of
	 * the administration settings.
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(AppConstants::APP_ID, 'app-dark.svg');
	}
}
