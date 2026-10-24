<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * The choice of a user to include Paperless in every search of their own, without the
 * switch Search connected services, in the section Paperless of the personal settings.
 */
final class PersonalSettings implements ISettings {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private AlwaysSearch $alwaysSearch,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID();

		return new TemplateResponse(AppConstants::APP_ID, 'personal', [
			'alwaysSearch' => $userId !== null && $this->alwaysSearch->isChosenBy($userId),
			'trusted' => $this->configService->isAlwaysSearchEnabled(),
			'saveUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.personalSettings.save'),
		]);
	}

	/**
	 * The section of the app while the administrators let users choose and the search
	 * shows Paperless documents, as the dashboard widget offers itself: with Paperless
	 * connected and an archive account. Otherwise none, which hides the setting, and
	 * Nextcloud hides the section without it.
	 */
	public function getSection(): ?string {
		$shown = $this->configService->isPersonalAlwaysSearchAllowed()
			&& $this->configService->isConfigured()
			&& $this->configService->getArchiveOwner() !== '';

		return $shown ? AppConstants::APP_ID : null;
	}

	public function getPriority(): int {
		return 50;
	}
}
