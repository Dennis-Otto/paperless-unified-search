<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Settings\AdminSection;
use OCA\PaperlessUnifiedSearch\Settings\AdminSettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {
	public function testTheFormShowsThePublicConfigAndTheRoutesOfTheSettings(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => match ($app . '.' . $key) {
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			'paperless_sync.target_user' => 'sync',
			default => '',
		});
		$config->method('getValueBool')->willReturn(true);

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::exactly(3))
			->method('linkToRoute')
			->willReturnCallback(static fn (string $routeName): string => match ($routeName) {
				AppConstants::APP_ID . '.settings.save' => '/apps/paperless_unified_search/settings#save',
				AppConstants::APP_ID . '.settings.reset' => '/apps/paperless_unified_search/settings#reset',
				AppConstants::APP_ID . '.settings.clearDiagnostics' => '/apps/paperless_unified_search/settings/diagnostics',
			});

		$form = $this->settings($config, $credentials, $urlGenerator)->getForm();

		self::assertSame(AppConstants::APP_ID, $form->getApp());
		self::assertSame('settings', $form->getTemplateName());
		self::assertSame(TemplateResponse::RENDER_AS_USER, $form->getRenderAs());

		$params = $form->getParams();
		self::assertSame('/apps/paperless_unified_search/settings#save', $params['saveUrl']);
		self::assertSame('/apps/paperless_unified_search/settings#reset', $params['resetUrl']);
		self::assertSame('/apps/paperless_unified_search/settings/diagnostics', $params['clearDiagnosticsUrl']);
		self::assertInstanceOf(PublicConfig::class, $params['config']);
		self::assertSame([
			'url' => 'https://paperless.example.com',
			'tokenConfigured' => true,
			'alwaysSearch' => true,
			'archiveOwner' => '',
			'syncAccount' => 'sync',
		], $params['config']->jsonSerialize());
		self::assertStringNotContainsString('TEST_VALUE', json_encode($params, JSON_THROW_ON_ERROR));
		self::assertNull($params['history']);
	}

	public function testTheFormShowsTheHistoryOfTheProblemsWithFormattedTimes(): void {
		$history = DiagnosticsHistory::start(1791400000)
			->with(new SearchEvent(1791500000, SearchEvent::KIND_FAILED, SearchEvent::STEP_FILES, 'RuntimeException', 'Storage unavailable', 12))
			->recovered(1791500060)
			->with(new SearchEvent(1791500100, SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS, 'ConnectException', '', 3001));
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturn($history->jsonSerialize());

		$params = $this->settings($config, $this->createStub(ICredentialsManager::class), $this->createStub(IURLGenerator::class))
			->getForm()
			->getParams();

		self::assertSame([
			'since' => 'at 1791400000',
			'failed' => 1,
			'retried' => 1,
			'lastFailure' => 'at 1791500000',
			'recovery' => 'at 1791500060',
			'events' => [
				['time' => 'at 1791500100', 'kind' => SearchEvent::KIND_RETRIED, 'step' => SearchEvent::STEP_PAPERLESS, 'error' => 'ConnectException', 'durationMs' => 3001],
				['time' => 'at 1791500000', 'kind' => SearchEvent::KIND_FAILED, 'step' => SearchEvent::STEP_FILES, 'error' => 'RuntimeException: Storage unavailable', 'durationMs' => 12],
			],
		], $params['history']);
	}

	public function testAHistoryOfRetriesOnlyHasNoLastFailureAndNoRecovery(): void {
		$history = DiagnosticsHistory::start(1791400000)
			->with(new SearchEvent(1791500100, SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS, 'ConnectException', 'cURL error 7', 3001));
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturn($history->jsonSerialize());

		$params = $this->settings($config, $this->createStub(ICredentialsManager::class), $this->createStub(IURLGenerator::class))
			->getForm()
			->getParams();

		self::assertIsArray($params['history']);
		self::assertSame('', $params['history']['lastFailure']);
		self::assertSame('', $params['history']['recovery']);
	}

	public function testAHistoryWithoutEventsShowsNoProblem(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturn(DiagnosticsHistory::start(1791400000)->jsonSerialize());

		$params = $this->settings($config, $this->createStub(ICredentialsManager::class), $this->createStub(IURLGenerator::class))
			->getForm()
			->getParams();

		self::assertNull($params['history']);
	}

	public function testTheFormHasASectionOfItsOwn(): void {
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->willReturnCallback(static fn (string $app, string $image): string => "/apps/{$app}/img/{$image}");
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')
			->willReturnCallback(static fn (string $text): string => $text === 'Paperless Unified Search' ? 'Paperless-Suche' : $text);
		$settings = $this->settings($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class), $urlGenerator);
		$section = new AdminSection($l10n, $urlGenerator);

		self::assertSame(AppConstants::APP_ID, $section->getID());
		self::assertSame($section->getID(), $settings->getSection());
		self::assertSame('Paperless-Suche', $section->getName());
		self::assertSame('/apps/paperless_unified_search/img/app.svg', $section->getIcon());
		self::assertSame(56, $section->getPriority());
		self::assertSame(50, $settings->getPriority());
	}

	private function settings(IAppConfig $config, ICredentialsManager $credentials, IURLGenerator $urlGenerator): AdminSettings {
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDateTime')
			->with(self::isType('int'), 'short', 'medium')
			->willReturnCallback(static fn (int $timestamp): string => 'at ' . $timestamp);

		return new AdminSettings(
			new ConfigService($config, $credentials),
			$urlGenerator,
			new SearchDiagnostics($config, $this->createStub(ITimeFactory::class)),
			$formatter,
		);
	}
}
