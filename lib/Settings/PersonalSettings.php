<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\AlertChoice;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * The section Paperless of the personal settings: the choice of a user to include Paperless
 * in every search of their own, without the switch Search connected services, and what they
 * want to be notified about, each as far as the administrators let users choose it.
 */
final class PersonalSettings implements ISettings {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private AlwaysSearch $alwaysSearch,
		private SavedSearches $savedSearches,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID();
		$alerts = $userId !== null ? $this->savedSearches->of($userId) : new AlertChoice();

		return new TemplateResponse(AppConstants::APP_ID, 'personal', [
			'alwaysSearchAllowed' => $this->configService->isPersonalAlwaysSearchAllowed(),
			'alwaysSearch' => $userId !== null && $this->alwaysSearch->isChosenBy($userId),
			'trusted' => $this->configService->isAlwaysSearchEnabled(),
			'saveUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.personalSettings.save'),
			'alertsAllowed' => $this->configService->isNewDocumentAlertsAllowed(),
			'everyDocument' => $alerts->everyDocument,
			'terms' => implode("\n", $alerts->terms),
			'mostTerms' => SavedSearches::MOST_TERMS,
			'alertsUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.personalSettings.saveAlerts'),
		]);
	}

	/**
	 * The section of the app while the administrators let users choose either and the
	 * search shows Paperless documents, as the dashboard widget offers itself: with Paperless
	 * connected and an archive account. Otherwise none, which hides the setting, and
	 * Nextcloud hides the section without it.
	 */
	public function getSection(): ?string {
		$shown = ($this->configService->isPersonalAlwaysSearchAllowed() || $this->configService->isNewDocumentAlertsAllowed())
			&& $this->configService->isConfigured()
			&& $this->configService->getArchiveOwner() !== '';

		return $shown ? AppConstants::APP_ID : null;
	}

	public function getPriority(): int {
		return 50;
	}
}
