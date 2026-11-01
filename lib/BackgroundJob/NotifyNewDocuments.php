<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\BackgroundJob;

use OCA\PaperlessUnifiedSearch\Service\NewDocumentAlerts;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Every five minutes, the notifications about the documents that Paperless added since the
 * last run, which appinfo/info.xml registers. Two runs at once would notify twice.
 */
final class NotifyNewDocuments extends TimedJob {
	/** The seconds between two runs. */
	public const INTERVAL = 300;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		ITimeFactory $time,
		private NewDocumentAlerts $alerts,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param mixed $argument none, for a job that appinfo/info.xml registers
	 */
	protected function run($argument): void {
		$this->alerts->run();
	}
}
