<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDateTimeFormatter;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

final class AdminSettings implements ISettings {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private IURLGenerator $urlGenerator,
		private SearchDiagnostics $diagnostics,
		private IDateTimeFormatter $dateTimeFormatter,
	) {
	}

	public function getForm(): TemplateResponse {
		$failure = $this->diagnostics->getLastFailure();
		$recoveredAt = $failure === null ? null : $this->diagnostics->getRecoveredAt();

		return new TemplateResponse(AppConstants::APP_ID, 'settings', [
			'config' => $this->configService->getPublicConfig(),
			'saveUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.save'),
			'resetUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.reset'),
			'failure' => $failure,
			'failureTime' => $failure === null ? '' : $this->formatTime($failure->time),
			'recoveryTime' => $recoveredAt === null ? '' : $this->formatTime($recoveredAt),
		]);
	}

	public function getSection(): string {
		return AppConstants::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}

	private function formatTime(int $timestamp): string {
		return $this->dateTimeFormatter->formatDateTime($timestamp, 'short', 'medium');
	}
}
