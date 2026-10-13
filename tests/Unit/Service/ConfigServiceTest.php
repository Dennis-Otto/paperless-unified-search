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
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ConfigServiceTest extends TestCase {
	use Accounts;

	private const STORED_URL = 'https://paperless.example.com';
	private const ENTER_AGAIN = 'Enter the API token again: a stored token is only sent to the Paperless server it was saved for.';

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

		$service = $this->service($config, $credentials);
		$serialized = $service->getPublicConfig()->jsonSerialize();

		self::assertSame([
			'url' => 'https://paperless.example.com',
			'tokenConfigured' => true,
			'alwaysSearch' => true,
			'archiveOwner' => 'archive',
			'syncAccount' => 'sync',
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
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
			'own setting in another case' => ['Archive', 'sync', 'archive'],
			'account of Paperless Sync in another case' => ['', 'SYNC', 'sync'],
			'own setting that does not exist' => ['deleted', 'sync', ''],
			'account of Paperless Sync that does not exist' => ['', 'deleted', ''],
		];
	}

	/**
	 * Nextcloud finds an account whatever the case of its ID, but the owner of a file
	 * carries the exact ID, which the search compares (#99).
	 */
	#[DataProvider('archiveAccounts')]
	public function testTheArchiveAccountFallsBackToThatOfPaperlessSyncAsNextcloudSpellsIt(string $setting, string $syncAccount, string $owner): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.archive_owner' => $setting,
			'paperless_sync.target_user' => $syncAccount,
		]);

		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		self::assertSame($owner, $service->getArchiveOwner());
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function missingArchiveAccounts(): array {
		return [
			'own setting' => ['archive', 'deleted', false],
			'account of Paperless Sync' => ['', 'sync', false],
			'neither' => ['', '', false],
			'own setting that does not exist' => ['deleted', 'sync', true],
			'account of Paperless Sync that does not exist' => ['', 'deleted', true],
		];
	}

	#[DataProvider('missingArchiveAccounts')]
	public function testThePublicConfigTellsWhenTheArchiveAccountDoesNotExist(string $setting, string $syncAccount, bool $missing): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.archive_owner' => $setting,
			'paperless_sync.target_user' => $syncAccount,
		]);

		$publicConfig = $this->service($config, $this->createStub(ICredentialsManager::class))->getPublicConfig();

		self::assertSame($missing, $publicConfig->archiveOwnerMissing);
		self::assertSame($setting, $publicConfig->archiveOwner);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function enteredArchiveAccounts(): array {
		return [
			'as Nextcloud spells it' => ['archive', 'archive'],
			'in another case, with blanks' => [' Archive ', 'archive'],
			'blank, for the account of Paperless Sync' => ['  ', ''],
		];
	}

	#[DataProvider('enteredArchiveAccounts')]
	public function testAnEnteredArchiveAccountIsStoredAsNextcloudSpellsIt(string $entered, string $stored): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		self::assertSame($stored, $service->resolveArchiveOwner($entered));
	}

	public function testAnArchiveAccountThatDoesNotExistIsRefused(): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The archive account does not exist.');
		$service->resolveArchiveOwner('nobody');
	}

	/**
	 * @return array<string, array{string, string, ?string, bool}>
	 */
	public static function savedArchiveAccounts(): array {
		return [
			'an account' => [' archive ', 'sync', 'archive', false],
			'blank' => ['  ', 'sync', null, false],
			'blank, and Paperless Sync names an account that does not exist' => ['', 'deleted', null, true],
		];
	}

	#[DataProvider('savedArchiveAccounts')]
	public function testSaveStoresOrForgetsTheArchiveAccount(string $archiveOwner, string $syncAccount, ?string $stored, bool $missing): void {
		$values = [];
		$deleted = [];
		$config = $this->settings(['paperless_sync.target_user' => $syncAccount]);
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

		$result = $this->service($config, $this->createStub(ICredentialsManager::class))
			->save('https://paperless.example.com', 'TEST_VALUE', false, $archiveOwner);

		self::assertSame($stored, $values[AppConstants::APP_ID . '.archive_owner'] ?? null);
		self::assertSame($stored === null, in_array(AppConstants::APP_ID . '.archive_owner', $deleted, true));
		self::assertSame($stored ?? '', $result->archiveOwner);
		self::assertSame($syncAccount, $result->syncAccount);
		self::assertSame($missing, $result->archiveOwnerMissing);
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
		$credentials->expects(self::never())->method('retrieve');

		$service = $this->service($config, $credentials);
		$result = $service->save(' https://paperless.example.com/// ', ' TEST_VALUE ', true);

		self::assertSame('https://paperless.example.com', $result->url);
		self::assertTrue($result->tokenConfigured);
		self::assertFalse($result->tokenUnreadable);
		self::assertTrue($result->alwaysSearch);
		self::assertSame('TEST_VALUE', $service->getToken());
	}

	public function testAlwaysSearchIsDisabledByDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueBool')
			->with(AppConstants::APP_ID, 'always_search', false)
			->willReturn(false);

		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		self::assertFalse($service->isAlwaysSearchEnabled());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function sameServers(): array {
		return [
			'the same URL' => ['https://paperless.example.com', 'https://paperless.example.com'],
			'another path' => ['https://paperless.example.com', 'https://paperless.example.com/paperless'],
			'host and scheme in capitals' => ['https://paperless.example.com', 'HTTPS://Paperless.Example.COM'],
			'the port of HTTPS named' => ['https://paperless.example.com', 'https://paperless.example.com:443'],
			'the port of HTTP no longer named' => ['http://paperless.example.com:80/paperless', 'http://paperless.example.com'],
			'the same port and no path' => ['http://paperless.example.com:8000/paperless', 'http://paperless.example.com:8000'],
		];
	}

	#[DataProvider('sameServers')]
	public function testABlankTokenKeepsTheStoredOneForTheSameServer(string $storedUrl, string $url): void {
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('existing-token');

		$service = $this->service($this->settings([AppConstants::APP_ID . '.paperless_url' => $storedUrl]), $credentials);

		self::assertSame('existing-token', $service->resolveToken(' ', $url));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function otherServers(): array {
		return [
			'another host' => ['https://paperless.example.com', 'https://attacker.example'],
			'plain HTTP' => ['https://paperless.example.com', 'http://paperless.example.com'],
			'HTTPS instead of HTTP' => ['http://paperless.example.com', 'https://paperless.example.com'],
			'another port' => ['https://paperless.example.com', 'https://paperless.example.com:8443'],
			'the port of HTTP with HTTPS' => ['https://paperless.example.com', 'https://paperless.example.com:80'],
			'no port instead of another one' => ['http://paperless.example.com:8000', 'http://paperless.example.com'],
			'a subdomain' => ['https://paperless.example.com', 'https://evil.paperless.example.com'],
			'the host as the start of another' => ['https://paperless.example.com', 'https://paperless.example.com.attacker.example'],
			'the host as a path' => ['https://paperless.example.com', 'https://attacker.example/paperless.example.com'],
			'no URL stored' => ['', 'https://paperless.example.com'],
		];
	}

	/**
	 * Whoever can use the session of an administrator must not be able to send the stored
	 * token to a server of their own, or over plain HTTP, by changing the URL (#99).
	 */
	#[DataProvider('otherServers')]
	public function testABlankTokenNeverSendsTheStoredOneToAnotherServer(string $storedUrl, string $url): void {
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('existing-token');

		$service = $this->service($this->settings([AppConstants::APP_ID . '.paperless_url' => $storedUrl]), $credentials);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(self::ENTER_AGAIN);
		$service->resolveToken('', $url);
	}

	public function testTheCandidateTokenIsTrimmedAndWinsOverTheStoredOne(): void {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('retrieve');

		$service = $this->service($this->settings([AppConstants::APP_ID . '.paperless_url' => self::STORED_URL]), $credentials);

		self::assertSame('new-token', $service->resolveToken(' new-token ', 'https://other.example'));
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function noStoredTokens(): array {
		return [
			'none' => [null],
			'empty' => [''],
		];
	}

	#[DataProvider('noStoredTokens')]
	public function testATokenIsRequiredWhenNoneIsStored(mixed $stored): void {
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn($stored);

		$service = $this->service($this->settings([AppConstants::APP_ID . '.paperless_url' => self::STORED_URL]), $credentials);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');
		$service->resolveToken('  ', 'https://other.example');
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function origins(): array {
		return [
			'HTTPS' => ['https://paperless.example.com', 'https://paperless.example.com:443'],
			'HTTP' => ['http://paperless.example.com/', 'http://paperless.example.com:80'],
			'capitals' => ['HTTPS://Paperless.Example.COM', 'https://paperless.example.com:443'],
			'a port and a path' => ['http://paperless.example.com:8000/paperless', 'http://paperless.example.com:8000'],
			'an IPv6 address' => ['http://[fd00::1]:8000', 'http://[fd00::1]:8000'],
			'another scheme' => ['ftp://paperless.example.com', 'ftp://paperless.example.com:0'],
			'no URL' => ['paperless', '://:0'],
			'a URL that PHP cannot parse' => ['http:///paperless', ''],
		];
	}

	#[DataProvider('origins')]
	public function testTheOriginOfAUrlIsItsSchemeHostAndPort(string $url, string $origin): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		self::assertSame($origin, $service->originOf($url));
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

		$service = $this->service($config, $credentials);

		self::assertSame($configured, $service->isConfigured());
		self::assertSame(is_string($token) && $token !== '', $service->getPublicConfig()->tokenConfigured);
		self::assertFalse($service->isTokenUnreadable());
	}

	/**
	 * The credentials manager decrypts the token on every call, and a search asked for it
	 * up to three times.
	 */
	public function testTheTokenIsDecryptedOncePerRequest(): void {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::once())->method('retrieve')->willReturn('TEST_VALUE');

		$service = $this->service($this->settings([AppConstants::APP_ID . '.paperless_url' => self::STORED_URL]), $credentials);

		self::assertTrue($service->isConfigured());
		self::assertSame('TEST_VALUE', $service->getToken());
		self::assertTrue($service->getPublicConfig()->tokenConfigured);
	}

	/**
	 * After a move or a restore of Nextcloud with another secret in config.php, the
	 * credentials manager throws "HMAC does not match." for the stored token (#99).
	 */
	public function testATokenThatCannotBeDecryptedCountsAsNoneAndIsLoggedOnce(): void {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::once())
			->method('retrieve')
			->willThrowException(new RuntimeException('HMAC does not match.'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				'The stored Paperless API token can\'t be decrypted, for example after the secret of config.php changed; enter it again ({errorType}).',
				['app' => AppConstants::APP_ID, 'errorType' => RuntimeException::class],
			);

		$service = new ConfigService(
			$this->settings([AppConstants::APP_ID . '.paperless_url' => self::STORED_URL]),
			$credentials,
			$this->accounts(),
			$logger,
		);

		self::assertSame('', $service->getToken());
		self::assertFalse($service->isConfigured());
		self::assertTrue($service->isTokenUnreadable());
		$publicConfig = $service->getPublicConfig();
		self::assertFalse($publicConfig->tokenConfigured);
		self::assertTrue($publicConfig->tokenUnreadable);
	}

	public function testABlankTokenIsRequiredWhenTheStoredOneCannotBeDecrypted(): void {
		$service = $this->service(
			$this->settings([AppConstants::APP_ID . '.paperless_url' => self::STORED_URL]),
			$this->undecryptable(),
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Paperless API token is required.');
		$service->resolveToken('', self::STORED_URL);
	}

	public function testSavingANewTokenReplacesOneThatCannotBeDecrypted(): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->undecryptable());
		self::assertTrue($service->isTokenUnreadable());

		$result = $service->save(self::STORED_URL, 'NEW_TEST_VALUE');

		self::assertTrue($result->tokenConfigured);
		self::assertFalse($result->tokenUnreadable);
		self::assertFalse($service->isTokenUnreadable());
		self::assertSame('NEW_TEST_VALUE', $service->getToken());
	}

	public function testSaveRejectsABlankTokenWithoutStoringAnything(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::never())->method('setValueString');
		$config->expects(self::never())->method('setValueBool');

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('store');

		$service = $this->service($config, $credentials);

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
		$credentials->expects(self::never())->method('retrieve');

		$service = $this->service($config, $credentials);
		$result = $service->reset();

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
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
		], $result->jsonSerialize());
		self::assertSame('', $service->getToken());
	}

	public function testResetForgetsATokenThatCannotBeDecrypted(): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->undecryptable());
		self::assertTrue($service->isTokenUnreadable());

		self::assertFalse($service->reset()->tokenUnreadable);
		self::assertFalse($service->isTokenUnreadable());
	}

	public function testResetTellsWhenTheAccountOfPaperlessSyncDoesNotExist(): void {
		$config = $this->settings(['paperless_sync.target_user' => 'deleted']);

		$result = $this->service($config, $this->createStub(ICredentialsManager::class))->reset();

		self::assertSame('deleted', $result->syncAccount);
		self::assertTrue($result->archiveOwnerMissing);
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
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		self::assertSame($normalizedUrl, $service->normalizeUrl($url));
	}

	#[DataProvider('invalidUrlProvider')]
	public function testInvalidUrlsAreRejected(string $url, string $message): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

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

	/**
	 * The service, with the accounts archive and sync in Nextcloud.
	 */
	private function service(IAppConfig $config, ICredentialsManager $credentials): ConfigService {
		return new ConfigService($config, $credentials, $this->accounts('archive', 'sync'), $this->createStub(LoggerInterface::class));
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
	 * A credentials manager whose stored token was encrypted with another secret.
	 */
	private function undecryptable(): ICredentialsManager {
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willThrowException(new RuntimeException('HMAC does not match.'));

		return $credentials;
	}
}
