<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase {
	public function testPublicConfigNeverContainsToken(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->with(AppConstants::APP_ID, 'paperless_url', '')
			->willReturn('https://paperless.example.com');
		$config->method('getValueBool')
			->with(AppConstants::APP_ID, 'always_search', false)
			->willReturn(true);

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$service = new ConfigService($config, $credentials);
		$serialized = $service->getPublicConfig()->jsonSerialize();

		self::assertSame([
			'url' => 'https://paperless.example.com',
			'tokenConfigured' => true,
			'alwaysSearch' => true,
		], $serialized);
		self::assertStringNotContainsString('TEST_VALUE', json_encode($serialized, JSON_THROW_ON_ERROR));
	}

	public function testSaveNormalizesUrlAndStoresTokenInCredentialsManager(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('setValueString')
			->with(AppConstants::APP_ID, 'paperless_url', 'https://paperless.example.com');
		$config->expects(self::once())
			->method('setValueBool')
			->with(AppConstants::APP_ID, 'always_search', true);

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::once())
			->method('store')
			->with('', AppConstants::APP_ID . '.api-token', 'TEST_VALUE');

		$service = new ConfigService($config, $credentials);
		$result = $service->save(' https://paperless.example.com/// ', ' TEST_VALUE ', true);

		self::assertSame('https://paperless.example.com', $result->url);
		self::assertTrue($result->tokenConfigured);
		self::assertTrue($result->alwaysSearch);
	}

	public function testAlwaysSearchIsDisabledByDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueBool')
			->with(AppConstants::APP_ID, 'always_search', false)
			->willReturn(false);

		$service = new ConfigService($config, $this->createStub(ICredentialsManager::class));

		self::assertFalse($service->isAlwaysSearchEnabled());
	}

	public function testBlankCandidateKeepsExistingToken(): void {
		$config = $this->createStub(IAppConfig::class);
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('existing-token');

		$service = new ConfigService($config, $credentials);

		self::assertSame('existing-token', $service->resolveToken(''));
	}

	/**
	 * @dataProvider invalidUrlProvider
	 */
	public function testInvalidUrlsAreRejected(string $url): void {
		$service = new ConfigService(
			$this->createStub(IAppConfig::class),
			$this->createStub(ICredentialsManager::class),
		);

		$this->expectException(InvalidArgumentException::class);
		$service->normalizeUrl($url);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidUrlProvider(): iterable {
		yield 'empty' => [''];
		yield 'not a URL' => ['paperless'];
		yield 'unsupported protocol' => ['file:///etc/passwd'];
		yield 'embedded credentials' => ['https://user:pass@example.com'];
		yield 'query string' => ['https://example.com?token=TEST_VALUE'];
	}
}
