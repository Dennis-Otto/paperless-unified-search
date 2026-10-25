<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\SetupCheck;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * The state of the search in the overview of the administration settings and in
 * occ setupchecks: a warning when the search shows no Paperless documents for a reason
 * that an administrator can correct, or when the account of the API token may do more or
 * less than the search needs, with a link to the settings of the app, and else the
 * Paperless that it is connected to. It reads only what the app stored, and sends no
 * request to Paperless, so that the overview opens as fast as without it; the last answer
 * of Paperless named the version, the last test of the connection what the account of the
 * token may do, and the history of the problems of the search tells whether searches
 * work.
 */
final class PaperlessSetupCheck implements ISetupCheck {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private SearchDiagnostics $diagnostics,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IDateTimeFormatter $dateTimeFormatter,
		private SettingsMessages $messages,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Paperless Unified Search');
	}

	public function run(): SetupResult {
		if ($this->configService->isTokenUnreadable()) {
			return $this->warning($this->l10n->t('The stored Paperless API token can\'t be decrypted, as after a move or a restore of Nextcloud with another secret in config.php, so the search shows no Paperless documents. Enter the token again in {section}.'));
		}

		// Without a connection, the app has nothing to say: it isn't in use yet.
		if (!$this->configService->isConfigured()) {
			return SetupResult::success($this->l10n->t('Paperless Unified Search is not connected to Paperless.'));
		}

		$setting = $this->configService->getArchiveOwnerSetting();
		$named = $setting !== '' ? $setting : $this->configService->getSyncAccount();
		if ($named === '') {
			return $this->warning($this->l10n->t('No archive account is set, so the search shows no Paperless documents. Enter the account that owns the synchronized files in {section}.'));
		}

		$archiveOwner = $this->configService->getArchiveOwner();
		if ($archiveOwner === '') {
			return $this->warning($this->l10n->t('The archive account %s does not exist, so the search shows no Paperless documents. Enter the account that owns the synchronized files in {section}.', [$named]));
		}

		$history = $this->diagnostics->getHistory();
		if ($history !== null && $history->lastFailureAt !== null && $history->awaitsRecovery()) {
			$failedAt = $this->dateTimeFormatter->formatDateTime($history->lastFailureAt);
			// The newest failure; retries that came after it don't end it.
			$failure = array_values(array_filter($history->events, static fn (SearchEvent $event): bool => $event->kind === SearchEvent::KIND_FAILED))[0] ?? null;

			return $this->warning($failure?->step === SearchEvent::STEP_FILES
				? $this->l10n->t('A search failed on %s while looking for the files of the documents in Nextcloud, and none has worked since. Search problems in {section} shows what failed.', [$failedAt])
				: $this->l10n->t('A search failed on %s while asking Paperless, and none has worked since. Search problems in {section} shows what failed.', [$failedAt]));
		}

		$tokenWarnings = $this->configService->getTokenWarnings();
		if ($tokenWarnings !== []) {
			// The search works, so these come after every reason that it shows nothing.
			return $this->warning($this->l10n->t('%s Then test the connection again in {section}.', [
				implode(' ', array_map($this->messages->forTokenWarning(...), $tokenWarnings)),
			]));
		}

		$url = $this->configService->getUrl();
		$version = $this->configService->getPaperlessVersion();

		return SetupResult::success($version === null
			? $this->l10n->t('Connected to Paperless at %1$s, with the archive account %2$s.', [$url, $archiveOwner])
			: $this->l10n->t('Connected to Paperless %1$s (API %2$s) at %3$s, with the archive account %4$s.', [$version->version, $version->apiVersion, $url, $archiveOwner]));
	}

	/**
	 * A warning whose {section} links to the settings of the app.
	 */
	private function warning(string $description): SetupResult {
		return SetupResult::warning($description, null, [
			'section' => [
				'type' => 'highlight',
				'id' => AppConstants::APP_ID,
				'name' => $this->l10n->t('the settings of Paperless Unified Search'),
				'link' => $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => AppConstants::APP_ID]),
			],
		]);
	}
}
