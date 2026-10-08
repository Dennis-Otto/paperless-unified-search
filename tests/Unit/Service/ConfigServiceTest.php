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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase {
	public function testPublicConfigNeverContainsToken(): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.archive_owner' => 'archive',
			'paperless_sync.target_user' => 'sync',
		]);
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
			'archiveOwner' => 'archive',
			'syncAccount' => 'sync',
		], $serialized);
		self::assertStringNotContainsString('TEST_VALUE', json_encode($serialized, JSON_THROW_ON_ERROR));
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function archiveAccounts(): array {
		return [
			'own setting wins' => [' archive ', 'sync', 'archive'],
			'account of Paperless Sync' => ['', ' sync ', 'sync'],
			'neither' => ['  ', '', ''],
		];
	}

	#[DataProvider('archiveAccounts')]
	public function testTheArchiveAccountFallsBackToThatOfPaperlessSync(string $setting, string $syncAccount, string $owner): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.archive_owner' => $setting,
			'paperless_sync.target_user' => $syncAccount,
		]);

		$service = new ConfigService($config, $this->createStub(ICredentialsManager::class));

		self::assertSame($owner, $service->getArchiveOwner());
	}

	/**
	 * @return array<string, array{string, ?string}>
	 */
	public static function savedArchiveAccounts(): array {
		return [
			'an account' => [' archive ', 'archive'],
			'blank' => ['  ', null],
		];
	}

	#[DataProvider('savedArchiveAccounts')]
	public function testSaveStoresOrForgetsTheArchiveAccount(string $archiveOwner, ?string $stored): void {
		$values = [];
		$deleted = [];
		$config = $this->settings(['paperless_sync.target_user' => 'sync']);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$values): bool {
				$values[$app . '.' . $key] = $value;

				return true;
			},
		);
		$config->method('deleteKey')->willReturnCallback(
			static function (string $app, string $key) use (&$deleted): void {
				$deleted[] = $app . '.' . $key;
			},
		);

		$result = (new ConfigService($config, $this->createStub(ICredentialsManager::class)))
			->save('https://paperless.example.com', 'TEST_VALUE', false, $archiveOwner);

		self::assertSame($stored, $values[AppConstants::APP_ID . '.archive_owner'] ?? null);
		self::assertSame($stored === null, in_array(AppConstants::APP_ID . '.archive_owner', $deleted, true));
		self::assertSame($stored ?? '', $result->archiveOwner);
		self::assertSame('sync', $result->syncAccount);
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

	public function testTheCandidateTokenIsTrimmedAndWinsOverTheStoredOne(): void {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('retrieve');

		$service = new ConfigService($this->createStub(IAppConfig::class), $credentials);

		self::assertSame('new-token', $service->resolveToken(' new-token '));
	}

	public function testATokenIsRequiredWhenNoneIsStored(): void {
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn(null);

		$service = new ConfigService($this->createStub(IAppConfig::class), $credentials);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');
		$service->resolveToken('  ');
	}

	/**
	 * @return array<string, array{string, mixed, bool}>
	 */
	public static function storedSettings(): array {
		return [
			'URL and token' => ['https://paperless.example.com', 'TEST_VALUE', true],
			'no URL' => ['', 'TEST_VALUE', false],
			'no token' => ['https://paperless.example.com', null, false],
			'empty token' => ['https://paperless.example.com', '', false],
			'token that is no text' => ['https://paperless.example.com', ['TEST_VALUE'], false],
		];
	}

	#[DataProvider('storedSettings')]
	public function testIsConfiguredWithAUrlAndAToken(string $url, mixed $token, bool $configured): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn($url);

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')
			->with('', AppConstants::APP_ID . '.api-token')
			->willReturn($token);

		$service = new ConfigService($config, $credentials);

		self::assertSame($configured, $service->isConfigured());
		self::assertSame(is_string($token) && $token !== '', $service->getPublicConfig()->tokenConfigured);
	}

	public function testSaveRejectsABlankTokenWithoutStoringAnything(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::never())->method('setValueString');
		$config->expects(self::never())->method('setValueBool');

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('store');

		$service = new ConfigService($config, $credentials);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');
		$service->save('https://paperless.example.com', '   ');
	}

	public function testResetDeletesTheUrlTheSwitchTheArchiveAccountAndTheToken(): void {
		$deletedKeys = [];
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::exactly(3))
			->method('deleteKey')
			->willReturnCallback(static function (string $app, string $key) use (&$deletedKeys): void {
				$deletedKeys[] = $app . '.' . $key;
			});

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::once())
			->method('delete')
			->with('', AppConstants::APP_ID . '.api-token');

		$result = (new ConfigService($config, $credentials))->reset();

		self::assertSame([
			AppConstants::APP_ID . '.paperless_url',
			AppConstants::APP_ID . '.always_search',
			AppConstants::APP_ID . '.archive_owner',
		], $deletedKeys);
		self::assertSame([
			'url' => '',
			'tokenConfigured' => false,
			'alwaysSearch' => false,
			'archiveOwner' => '',
			'syncAccount' => '',
		], $result->jsonSerialize());
	}

	/**
	 * A configuration that answers getValueString by app and key, with '' for any other.
	 *
	 * @param array<string, string> $values by "app.key"
	 */
	private function settings(array $values): IAppConfig&MockObject {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $values[$app . '.' . $key] ?? '',
		);

		return $config;
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function validUrls(): array {
		return [
			'HTTPS' => ['https://paperless.example.com', 'https://paperless.example.com'],
			'HTTP with a port and a path' => ['http://paperless.example.com:8000/paperless/', 'http://paperless.example.com:8000/paperless'],
			'protocol in capitals' => ['HTTPS://paperless.example.com', 'HTTPS://paperless.example.com'],
		];
	}

	#[DataProvider('validUrls')]
	public function testValidUrlsAreAccepted(string $url, string $normalizedUrl): void {
		$service = new ConfigService(
			$this->createStub(IAppConfig::class),
			$this->createStub(ICredentialsManager::class),
		);

		self::assertSame($normalizedUrl, $service->normalizeUrl($url));
	}

	#[DataProvider('invalidUrlProvider')]
	public function testInvalidUrlsAreRejected(string $url, string $message): void {
		$service = new ConfigService(
			$this->createStub(IAppConfig::class),
			$this->createStub(ICredentialsManager::class),
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$service->normalizeUrl($url);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidUrlProvider(): iterable {
		yield 'empty' => ['', 'Enter a valid Paperless URL.'];
		yield 'only slashes' => [' // ', 'Enter a valid Paperless URL.'];
		yield 'not a URL' => ['paperless', 'Enter a valid Paperless URL.'];
		yield 'unsupported protocol' => ['file:///etc/passwd', 'The Paperless URL must use HTTP or HTTPS.'];
		yield 'other protocol' => ['ftp://paperless.example.com', 'The Paperless URL must use HTTP or HTTPS.'];
		yield 'embedded credentials' => ['https://user:pass@example.com', 'The Paperless URL must not contain credentials, a query, or a fragment.'];
		yield 'embedded user' => ['https://user@example.com', 'The Paperless URL must not contain credentials, a query, or a fragment.'];
		yield 'query string' => ['https://example.com?token=TEST_VALUE', 'The Paperless URL must not contain credentials, a query, or a fragment.'];
		yield 'fragment' => ['https://example.com/#documents', 'The Paperless URL must not contain credentials, a query, or a fragment.'];
	}
}
