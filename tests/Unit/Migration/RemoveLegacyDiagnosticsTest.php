<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Migration;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Migration\RemoveLegacyDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class RemoveLegacyDiagnosticsTest extends TestCase {
	private const HISTORY = ['since' => 1791500000, 'failed' => 1, 'retried' => 0, 'lastFailureAt' => 1791500000, 'recoveredAt' => null, 'events' => []];

	/**
	 * Nextcloud runs the step after every update of the app, and on occ maintenance:repair,
	 * only when appinfo/info.xml names it.
	 */
	public function testTheAppInfoRunsTheStepAfterEveryUpdate(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertInstanceOf(SimpleXMLElement::class, $info);

		$steps = [];
		foreach ($info->{'repair-steps'}->{'post-migration'}->step as $step) {
			$steps[] = (string)$step;
		}

		self::assertSame([RemoveLegacyDiagnostics::class], $steps);
		self::assertTrue(is_subclass_of(RemoveLegacyDiagnostics::class, IRepairStep::class));
	}

	public function testTheNameSaysWhatTheStepRemoves(): void {
		self::assertSame(
			'Remove the last failed search that Paperless Unified Search 0.3.0 kept',
			(new RemoveLegacyDiagnostics(new MemoryAppConfig()))->getName(),
		);
	}

	/**
	 * Version 0.3.0 kept the last failure and the last recovery as lazy values, which the
	 * history replaced in 0.4.0; the step finds them and removes them, and leaves the
	 * settings and the history alone.
	 */
	public function testRemovesTheLastFailureAndTheLastRecoveryOfVersion030(): void {
		$config = (new MemoryAppConfig([
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.diagnostics_pending' => false,
			'paperless_sync.last_failure' => 'of another app',
		]))
			->seed(AppConstants::APP_ID, 'diagnostics', self::HISTORY, true)
			->seed(AppConstants::APP_ID, 'last_failure', ['time' => 1791500000, 'message' => 'Paperless returned HTTP 502.'], true)
			->seed(AppConstants::APP_ID, 'last_recovery', 1791500060, true);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))
			->method('info')
			->willReturnCallback(static function (string $message): void {
				self::assertContains($message, [
					'Removed last_failure, which the history of the problems of the search replaced',
					'Removed last_recovery, which the history of the problems of the search replaced',
				]);
			});

		(new RemoveLegacyDiagnostics($config))->run($output);

		self::assertSame([
			AppConstants::APP_ID . '.last_failure',
			AppConstants::APP_ID . '.last_recovery',
		], $config->calls('deleteKey'));
		self::assertSame([
			'paperless_url' => 'https://paperless.example.com',
			'diagnostics_pending' => false,
			'diagnostics' => self::HISTORY,
		], $config->storedValues(AppConstants::APP_ID));
		self::assertSame('of another app', $config->stored('paperless_sync', 'last_failure'));
	}

	/**
	 * A value that isn't lazy, as occ config:app:set leaves it, goes as well.
	 */
	public function testRemovesAKeyThatIsNotLazy(): void {
		$config = new MemoryAppConfig([AppConstants::APP_ID . '.last_recovery' => '1791500060']);

		(new RemoveLegacyDiagnostics($config))->run($this->createStub(IOutput::class));

		self::assertSame([AppConstants::APP_ID . '.last_recovery'], $config->calls('deleteKey'));
		self::assertSame([], $config->storedValues(AppConstants::APP_ID));
	}

	/**
	 * Every later update runs the step again: it finds nothing, removes nothing and says
	 * nothing.
	 */
	public function testASecondRunChangesNothing(): void {
		$config = (new MemoryAppConfig())
			->seed(AppConstants::APP_ID, 'last_failure', ['time' => 1791500000], true)
			->seed(AppConstants::APP_ID, 'last_recovery', 1791500060, true);
		$step = new RemoveLegacyDiagnostics($config);
		$step->run($this->createStub(IOutput::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method(self::anything());
		$step->run($output);

		self::assertSame([
			AppConstants::APP_ID . '.last_failure',
			AppConstants::APP_ID . '.last_recovery',
		], $config->calls('deleteKey'));
		self::assertSame([], $config->storedValues(AppConstants::APP_ID));
	}
}
