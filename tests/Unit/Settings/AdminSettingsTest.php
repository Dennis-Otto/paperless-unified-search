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
use OCA\PaperlessUnifiedSearch\Settings\AdminSettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {
	public function testTheFormShowsThePublicConfigAndTheRoutesOfTheSettings(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://paperless.example.com');
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
		], $params['config']->jsonSerialize());
		self::assertStringNotContainsString('TEST_VALUE', json_encode($params, JSON_THROW_ON_ERROR));
	}

	public function testTheFormIsAmongTheAdditionalSettings(): void {
		$settings = new AdminSettings(
			new ConfigService($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class)),
			$this->createStub(IURLGenerator::class),
		);

		self::assertSame('additional', $settings->getSection());
		self::assertSame(50, $settings->getPriority());
	}
}
