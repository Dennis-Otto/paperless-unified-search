<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
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
		return new TemplateResponse(AppConstants::APP_ID, 'settings', [
			'config' => $this->configService->getPublicConfig(),
			'saveUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.save'),
			'resetUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.reset'),
			'clearDiagnosticsUrl' => $this->urlGenerator->linkToRoute(AppConstants::APP_ID . '.settings.clearDiagnostics'),
			'history' => $this->getHistory(),
		]);
	}

	public function getSection(): string {
		return AppConstants::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}

	/**
	 * The history of the diagnostics as the page shows it, with formatted times, or null
	 * when it holds no problem.
	 *
	 * @return ?array{since: string, failed: int, retried: int, lastFailure: string, recovery: string, events: list<array{time: string, kind: string, step: string, error: string, durationMs: int}>}
	 */
	private function getHistory(): ?array {
		$history = $this->diagnostics->getHistory();
		if ($history === null || $history->events === []) {
			return null;
		}

		return [
			'since' => $this->formatTime($history->since),
			'failed' => $history->failed,
			'retried' => $history->retried,
			'lastFailure' => $history->lastFailureAt === null ? '' : $this->formatTime($history->lastFailureAt),
			'recovery' => $history->recoveredAt === null ? '' : $this->formatTime($history->recoveredAt),
			'events' => array_map(fn (SearchEvent $event): array => [
				'time' => $this->formatTime($event->time),
				'kind' => $event->kind,
				'step' => $event->step,
				'error' => $event->message === '' ? $event->error : $event->error . ': ' . $event->message,
				'durationMs' => $event->durationMs,
			], $history->events),
		];
	}

	private function formatTime(int $timestamp): string {
		return $this->dateTimeFormatter->formatDateTime($timestamp, 'short', 'medium');
	}
}
