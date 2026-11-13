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
use OCA\PaperlessUnifiedSearch\Service\UserTags;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * The section Paperless of the personal settings: the choice of a user to include Paperless
 * in every search of their own, without the switch Search connected services, and what they
 * want to be notified about, each as far as the administrators let users choose it; and for
 * a user whom Paperless Sync lets see only the documents with some tags (decision 0015), which
 * tags those are, so that the user can tell the limit from a search that fails.
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
		$tags = $userId !== null ? $this->tagsOf($userId) : [];

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
			'tags' => $tags,
		]);
	}

	/**
	 * The section of the app while the search shows Paperless documents, as the dashboard
	 * widget offers itself: with Paperless connected and an archive account; and while the
	 * administrators let users choose either, or the user may see only the documents with
	 * some tags, which the section names. Otherwise none, which hides the setting, and
	 * Nextcloud hides the section without it.
	 */
	public function getSection(): ?string {
		$userId = $this->userSession->getUser()?->getUID();
		$shown = $this->configService->isConfigured()
			&& $this->configService->getArchiveOwner() !== ''
			&& ($this->configService->isPersonalAlwaysSearchAllowed()
				|| $this->configService->isNewDocumentAlertsAllowed()
				|| ($userId !== null && $this->tagsOf($userId) !== []));

		return $shown ? AppConstants::APP_ID : null;
	}

	/**
	 * The names of the tags of Paperless that the user may see, as Paperless Sync stores
	 * them, or none for a user who may see every document.
	 *
	 * @return list<string>
	 */
	private function tagsOf(string $userId): array {
		return UserTags::parse($this->configService->getSyncUserTags())[$userId] ?? [];
	}

	public function getPriority(): int {
		return 50;
	}
}
