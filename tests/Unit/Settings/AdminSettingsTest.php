<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Settings\AdminSection;
use OCA\PaperlessUnifiedSearch\Settings\AdminSettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
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
		$urlGenerator->expects(self::exactly(2))
			->method('linkToRoute')
			->willReturnCallback(static fn (string $routeName): string => match ($routeName) {
				AppConstants::APP_ID . '.settings.save' => '/apps/paperless_unified_search/settings#save',
				AppConstants::APP_ID . '.settings.reset' => '/apps/paperless_unified_search/settings#reset',
			});

		$form = (new AdminSettings(new ConfigService($config, $credentials), $urlGenerator))->getForm();

		self::assertSame(AppConstants::APP_ID, $form->getApp());
		self::assertSame('settings', $form->getTemplateName());
		self::assertSame(TemplateResponse::RENDER_AS_USER, $form->getRenderAs());

		$params = $form->getParams();
		self::assertSame('/apps/paperless_unified_search/settings#save', $params['saveUrl']);
		self::assertSame('/apps/paperless_unified_search/settings#reset', $params['resetUrl']);
		self::assertInstanceOf(PublicConfig::class, $params['config']);
		self::assertSame([
			'url' => 'https://paperless.example.com',
			'tokenConfigured' => true,
			'alwaysSearch' => true,
			'archiveOwner' => '',
			'syncAccount' => 'sync',
		], $params['config']->jsonSerialize());
		self::assertStringNotContainsString('TEST_VALUE', json_encode($params, JSON_THROW_ON_ERROR));
	}

	public function testTheFormHasASectionOfItsOwn(): void {
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->willReturnCallback(static fn (string $app, string $image): string => "/apps/{$app}/img/{$image}");
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')
			->willReturnCallback(static fn (string $text): string => $text === 'Paperless Unified Search' ? 'Paperless-Suche' : $text);
		$settings = new AdminSettings(
			new ConfigService($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class)),
			$urlGenerator,
		);
		$section = new AdminSection($l10n, $urlGenerator);

		self::assertSame(AppConstants::APP_ID, $section->getID());
		self::assertSame($section->getID(), $settings->getSection());
		self::assertSame('Paperless-Suche', $section->getName());
		self::assertSame('/apps/paperless_unified_search/img/app.svg', $section->getIcon());
		self::assertSame(56, $section->getPriority());
		self::assertSame(50, $settings->getPriority());
	}
}
