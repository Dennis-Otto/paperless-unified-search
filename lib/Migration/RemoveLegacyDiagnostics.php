<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Migration;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Forgets the last failed search and the last recovery that version 0.3.0 kept in the
 * configuration of the app, which the history of the problems of the search replaced in
 * 0.4.0. Nextcloud runs the step after every update of the app and on occ
 * maintenance:repair; once the keys are gone, it finds nothing to do.
 */
final class RemoveLegacyDiagnostics implements IRepairStep {
	private const KEYS = ['last_failure', 'last_recovery'];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $config,
	) {
	}

	public function getName(): string {
		return 'Remove the last failed search that Paperless Unified Search 0.3.0 kept';
	}

	public function run(IOutput $output): void {
		foreach (self::KEYS as $key) {
			// Version 0.3.0 stored both as lazy values, which hasKey() finds only when asked
			// for every value, lazy or not.
			if ($this->config->hasKey(AppConstants::APP_ID, $key, null)) {
				$this->config->deleteKey(AppConstants::APP_ID, $key);
				$output->info('Removed ' . $key . ', which the history of the problems of the search replaced');
			}
		}
	}
}
