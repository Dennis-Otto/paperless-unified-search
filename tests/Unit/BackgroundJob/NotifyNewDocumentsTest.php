<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\BackgroundJob;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\BackgroundJob\NotifyNewDocuments;
use OCA\PaperlessUnifiedSearch\Service\NewDocumentAlerts;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SimpleXMLElement;

/**
 * The background job of the notifications, which runs every five minutes, one run at a
 * time, and leaves the work to NewDocumentAlerts.
 */
final class NotifyNewDocumentsTest extends TestCase {
	use AppServices;
	use UserPreferences;

	private ?ITimeFactory $clock = null;

	public function testItRunsEveryFiveMinutesOneRunAtATime(): void {
		$job = $this->job();

		$interval = (new ReflectionClass($job))->getParentClass();
		self::assertNotFalse($interval);
		self::assertSame(300, $interval->getProperty('interval')->getValue($job));
		self::assertFalse($job->getAllowParallelRuns());
		self::assertSame($this->clock, (new ReflectionProperty(Job::class, 'time'))->getValue($job));
	}

	public function testARunAsksTheAlerts(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts_progress'] = ['server' => 'gone'];

		(new ReflectionMethod(NotifyNewDocuments::class, 'run'))->invoke($this->job(), null);

		// Nobody asks for anything, so the alerts forgot the progress.
		self::assertArrayNotHasKey(AppConstants::APP_ID . '.new_document_alerts_progress', $this->settings);
	}

	public function testTheAppInfoRegistersTheJob(): void {
		$info = new SimpleXMLElement((string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));

		self::assertSame([NotifyNewDocuments::class], array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false)));
	}

	private function job(): NotifyNewDocuments {
		$accounts = $this->createStub(IUserManager::class);
		$configService = $this->configService($accounts);
		$this->clock = $this->createStub(ITimeFactory::class);

		return new NotifyNewDocuments(
			$this->clock,
			new NewDocumentAlerts(
				$configService,
				new SavedSearches($this->userConfig()),
				$this->paperlessApi($configService, $this->paperless()),
				new NextcloudFileLocator($this->createStub(IRootFolder::class), $this->createStub(LoggerInterface::class)),
				$accounts,
				$this->createStub(IManager::class),
				$this->appConfig(),
				$this->createStub(ITimeFactory::class),
				$this->createStub(LoggerInterface::class),
			),
		);
	}
}
