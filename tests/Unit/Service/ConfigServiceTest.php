<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\InvalidSettingException;
use OCA\PaperlessUnifiedSearch\Exception\SettingProblem;
use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ConfigServiceTest extends TestCase {
	use Accounts;

	private const STORED_URL = 'https://paperless.example.com';
	private const ENTER_AGAIN = 'Enter the API token again: a stored token is only sent to the Paperless server it was saved for.';
	private const PUNYCODE = 'Enter the host of the Paperless URL in its ASCII form (punycode), such as xn--bcher-kva.example for bücher.example.';

	public function testTheUrlCheckEndsTheTaintOfAServerAddress(): void {
		// The taint analysis trusts the check of the URL only while it says so.
		$comment = (new \ReflectionMethod(ConfigService::class, 'normalizeUrl'))->getDocComment();
		self::assertIsString($comment);
		self::assertStringContainsString('@psalm-taint-escape ssrf', $comment);
	}

	public function testPublicConfigNeverContainsToken(): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.archive_owner' => 'archive',
			AppConstants::APP_ID . '.paperless_version' => '3.3.0',
			AppConstants::APP_ID . '.paperless_api_version' => '10',
			AppConstants::APP_ID . '.always_search' => true,
			AppConstants::APP_ID . '.allow_local' => true,
			'paperless_sync.target_user' => 'sync',
		]);

		$credentials = $this->createStub(ICredentialsManager::class);
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
			'allowLocal' => true,
			'paperlessVersion' => '3.3.0',
			'paperlessApiVersion' => '10',
			'personalAlwaysSearch' => true,
			'tokenWarnings' => [],
			'newDocumentAlerts' => false,
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
	 * Paperless Sync stores its settings as lazy values since its version 0.3.0, and as
	 * values that aren't lazy before. Nextcloud answers a read of a lazy value that isn't
	 * lazy itself with the default, so the archive account was empty, and the search
	 * showed no Paperless documents at all (#166).
	 *
	 * @return array<string, array{bool}>
	 */
	public static function storesOfPaperlessSync(): array {
		return [
			'Paperless Sync 0.3.0 and later, lazy' => [true],
			'earlier versions, not lazy' => [false],
		];
	}

	#[DataProvider('storesOfPaperlessSync')]
	public function testTheArchiveAccountIsThatOfPaperlessSyncHoweverItStoresIt(bool $lazy): void {
		$config = $this->settings([])->seed('paperless_sync', 'target_user', 'sync', $lazy);

		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		self::assertSame('sync', $service->getSyncAccount());
		self::assertSame('sync', $service->getArchiveOwner());
		self::assertSame('sync', $service->getPublicConfig()->syncAccount);
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

		$exception = $this->refusal(static fn () => $service->resolveArchiveOwner('nobody'));

		self::assertSame('The archive account does not exist.', $exception->getMessage());
		self::assertSame(SettingProblem::UnknownArchiveAccount, $exception->problem);
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
		$config = $this->settings([
			AppConstants::APP_ID . '.archive_owner' => 'old',
			'paperless_sync.target_user' => $syncAccount,
		]);

		$result = $this->service($config, $this->createStub(ICredentialsManager::class))
			->save('https://paperless.example.com', 'TEST_VALUE', false, $archiveOwner);

		self::assertSame($stored, $config->stored(AppConstants::APP_ID, 'archive_owner'));
		self::assertSame($stored === null, in_array(AppConstants::APP_ID . '.archive_owner', $config->calls('deleteKey'), true));
		self::assertSame($stored ?? '', $result->archiveOwner);
		self::assertSame($syncAccount, $result->syncAccount);
		self::assertSame($missing, $result->archiveOwnerMissing);
	}

	public function testSaveNormalizesUrlAndStoresTokenInCredentialsManager(): void {
		$config = new MemoryAppConfig();

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
		self::assertFalse($result->allowLocal);
		self::assertTrue($result->personalAlwaysSearch);
		// The token goes to the credentials manager alone.
		self::assertSame([
			'paperless_url' => 'https://paperless.example.com',
			'always_search' => true,
			'allow_local' => false,
			'personal_always_search' => true,
		], $config->storedValues(AppConstants::APP_ID));
		self::assertSame('TEST_VALUE', $service->getToken());
	}

	/**
	 * A save that names no switch sets each to its default: no search of every user
	 * without the switch of Nextcloud for connected services, and no address of the local
	 * network, but users may choose to include Paperless in every search of their own.
	 */
	public function testASaveWithoutTheSwitchesTurnsThemOff(): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.always_search' => true,
			AppConstants::APP_ID . '.allow_local' => true,
			AppConstants::APP_ID . '.personal_always_search' => false,
		]);

		$result = $this->service($config, $this->createStub(ICredentialsManager::class))
			->save('https://paperless.example.com', 'TEST_VALUE');

		self::assertFalse($config->stored(AppConstants::APP_ID, 'always_search'));
		self::assertFalse($config->stored(AppConstants::APP_ID, 'allow_local'));
		self::assertTrue($config->stored(AppConstants::APP_ID, 'personal_always_search'));
		self::assertFalse($result->alwaysSearch);
		self::assertFalse($result->allowLocal);
		self::assertTrue($result->personalAlwaysSearch);
	}

	/**
	 * Whether users may include Paperless in every search of their own, in their personal
	 * settings, which an administrator can turn off.
	 */
	#[DataProvider('localNetwork')]
	public function testSaveStoresWhetherUsersMayAlwaysSearchPaperless(bool $allowed): void {
		$config = $this->settings([]);

		$result = $this->service($config, $this->createStub(ICredentialsManager::class))
			->save('https://paperless.example.com', 'TEST_VALUE', personalAlwaysSearch: $allowed);

		self::assertSame($allowed, $config->stored(AppConstants::APP_ID, 'personal_always_search'));
		self::assertSame($allowed, $result->personalAlwaysSearch);
		self::assertSame($allowed, $result->jsonSerialize()['personalAlwaysSearch']);
	}

	#[DataProvider('localNetwork')]
	public function testThePublicConfigTellsWhetherUsersMayAlwaysSearchPaperless(bool $allowed): void {
		$config = $this->settings([AppConstants::APP_ID . '.personal_always_search' => $allowed]);

		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		self::assertSame($allowed, $service->isPersonalAlwaysSearchAllowed());
		self::assertSame($allowed, $service->getPublicConfig()->personalAlwaysSearch);
	}

	public function testUsersMayAlwaysSearchPaperlessByDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueBool')
			->with(AppConstants::APP_ID, 'personal_always_search', true)
			->willReturn(true);

		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		self::assertTrue($service->isPersonalAlwaysSearchAllowed());
	}

	/**
	 * Every search asks for the archive account: without one named, Nextcloud is asked for
	 * no account.
	 */
	public function testWithoutAnArchiveAccountNoAccountIsLookedUp(): void {
		$accounts = $this->createMock(IUserManager::class);
		$accounts->expects(self::never())->method('get');
		$service = new ConfigService($this->settings([]), $this->createStub(ICredentialsManager::class), $accounts, $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));

		self::assertSame('', $service->getArchiveOwner());
		self::assertFalse($service->getPublicConfig()->archiveOwnerMissing);
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function localNetwork(): array {
		return [
			'allowed' => [true],
			'not allowed' => [false],
		];
	}

	/**
	 * Nextcloud refuses addresses of the local network unless a request allows them; the
	 * settings store whether the requests to this Paperless do (#117).
	 */
	#[DataProvider('localNetwork')]
	public function testSaveStoresWhetherTheLocalNetworkIsAllowed(bool $allowLocal): void {
		$config = $this->settings([]);

		$result = $this->service($config, $this->createStub(ICredentialsManager::class))
			->save('http://paperless_ngx:8000', 'TEST_VALUE', false, '', $allowLocal);

		self::assertSame($allowLocal, $config->stored(AppConstants::APP_ID, 'allow_local'));
		self::assertSame($allowLocal, $result->allowLocal);
		self::assertSame($allowLocal, $result->jsonSerialize()['allowLocal']);
		self::assertSame('http://paperless_ngx:8000', $result->url);
	}

	#[DataProvider('localNetwork')]
	public function testThePublicConfigTellsWhetherTheLocalNetworkIsAllowed(bool $allowLocal): void {
		$config = $this->settings([AppConstants::APP_ID . '.allow_local' => $allowLocal]);

		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		self::assertSame($allowLocal, $service->isLocalNetworkAllowed());
		self::assertSame($allowLocal, $service->getPublicConfig()->allowLocal);
		self::assertFalse($service->getPublicConfig()->alwaysSearch);
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
	 * Without the setting, Nextcloud's own rule holds: no address of the local network,
	 * unless config.php allows them to every app.
	 */
	public function testTheLocalNetworkIsNotAllowedByDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueBool')
			->with(AppConstants::APP_ID, 'allow_local', false)
			->willReturn(false);

		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		self::assertFalse($service->isLocalNetworkAllowed());
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

		$exception = $this->refusal(static fn () => $service->resolveToken('', $url));

		self::assertSame(self::ENTER_AGAIN, $exception->getMessage());
		self::assertSame(SettingProblem::TokenForOtherServer, $exception->problem);
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

		$exception = $this->refusal(static fn () => $service->resolveToken('  ', 'https://other.example'));

		self::assertSame('A Paperless API token is required.', $exception->getMessage());
		self::assertSame(SettingProblem::TokenRequired, $exception->problem);
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
		$credentials->expects(self::once())
			->method('retrieve')
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
			$this->createStub(IConfig::class),
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

		$exception = $this->refusal(static fn () => $service->resolveToken('', self::STORED_URL));

		self::assertSame(SettingProblem::TokenRequired, $exception->problem);
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

		$exception = $this->refusal(static fn () => $service->save('https://paperless.example.com', '   '));

		self::assertSame('A Paperless API token is required.', $exception->getMessage());
		self::assertSame(SettingProblem::TokenRequired, $exception->problem);
	}

	public function testResetDeletesTheUrlTheSwitchesTheArchiveAccountAndTheToken(): void {
		$deletedKeys = [];
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::exactly(10))
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
			AppConstants::APP_ID . '.allow_local',
			AppConstants::APP_ID . '.personal_always_search',
			AppConstants::APP_ID . '.new_document_alerts',
			AppConstants::APP_ID . '.new_document_alerts_progress',
			AppConstants::APP_ID . '.paperless_version',
			AppConstants::APP_ID . '.paperless_api_version',
			AppConstants::APP_ID . '.token_warnings',
		], $deletedKeys);
		self::assertSame([
			'url' => '',
			'tokenConfigured' => false,
			'alwaysSearch' => false,
			'archiveOwner' => '',
			'syncAccount' => '',
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
			'allowLocal' => false,
			'paperlessVersion' => '',
			'paperlessApiVersion' => '',
			'personalAlwaysSearch' => true,
			'tokenWarnings' => [],
			'newDocumentAlerts' => false,
		], $result->jsonSerialize());
		self::assertSame('', $service->getToken());
	}

	/**
	 * Saving stores the version of Paperless that the test of the connection found, which
	 * the settings show, and forgets an older one when the answer named none.
	 */
	public function testSaveStoresTheVersionOfTheTestedPaperless(): void {
		$strings = [];
		$deleted = [];
		$config = $this->createStub(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(static function (string $app, string $key, string $value) use (&$strings): bool {
			$strings[$app . '.' . $key] = $value;

			return true;
		});
		$config->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$deleted): void {
			$deleted[] = $app . '.' . $key;
		});
		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		$result = $service->save('https://paperless.example.com', 'TEST_VALUE', paperlessVersion: PaperlessVersion::of('3.3.0', '10'));

		self::assertSame('3.3.0', $strings[AppConstants::APP_ID . '.paperless_version'] ?? null);
		self::assertSame('10', $strings[AppConstants::APP_ID . '.paperless_api_version'] ?? null);
		self::assertSame('3.3.0', $result->jsonSerialize()['paperlessVersion']);
		self::assertSame('10', $result->jsonSerialize()['paperlessApiVersion']);

		$result = $service->save('https://paperless.example.com', 'TEST_VALUE');

		self::assertContains(AppConstants::APP_ID . '.paperless_version', $deleted);
		self::assertContains(AppConstants::APP_ID . '.paperless_api_version', $deleted);
		self::assertSame('', $result->jsonSerialize()['paperlessVersion']);
	}

	/**
	 * A search stores the version that Paperless names only when it changed, so that the
	 * searches that work write nothing.
	 */
	public function testTheVersionOfPaperlessIsWrittenOnlyWhenItChanges(): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.paperless_version' => '3.3.0',
			AppConstants::APP_ID . '.paperless_api_version' => '10',
		]);
		$service = $this->service($config, $this->createStub(ICredentialsManager::class));

		$service->rememberPaperlessVersion(PaperlessVersion::of('3.3.0', '10'));
		$service->rememberPaperlessVersion(null);
		$service->rememberPaperlessVersion(PaperlessVersion::of('3.4.0', '10'));

		self::assertSame([AppConstants::APP_ID . '.paperless_version', AppConstants::APP_ID . '.paperless_api_version'], $config->calls('setValueString'));
		self::assertSame('3.4.0', $config->stored(AppConstants::APP_ID, 'paperless_version'));
		self::assertSame('10', $config->stored(AppConstants::APP_ID, 'paperless_api_version'));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function storedValuesOfNoVersion(): array {
		return [
			'nothing stored' => ['', ''],
			'no version of the API' => ['3.3.0', ''],
			'markup' => ['<b>3.3.0</b>', '10'],
		];
	}

	#[DataProvider('storedValuesOfNoVersion')]
	public function testStoredValuesThatAreNoVersionAreNone(string $version, string $apiVersion): void {
		$config = $this->settings([
			AppConstants::APP_ID . '.paperless_version' => $version,
			AppConstants::APP_ID . '.paperless_api_version' => $apiVersion,
		]);

		self::assertNull($this->service($config, $this->createStub(ICredentialsManager::class))->getPaperlessVersion());
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
			// The names of the services of Docker may hold underscores, which cURL and
			// Docker's DNS accept, unlike PHP's filter of URLs (#117).
			'a service of Docker with an underscore' => ['http://paperless_ngx:8000', 'http://paperless_ngx:8000'],
			'a name without a domain' => ['http://paperless:8000/', 'http://paperless:8000'],
			'an IPv4 address' => ['http://192.168.1.20:8000', 'http://192.168.1.20:8000'],
			'an IPv6 address with a port' => ['http://[fd00::5]:8000/', 'http://[fd00::5]:8000'],
			'an IPv6 address without a port' => ['http://[::1]', 'http://[::1]'],
			'an IPv6 address in full' => ['http://[2001:db8:0:0:0:0:0:1]:8000', 'http://[2001:db8:0:0:0:0:0:1]:8000'],
			'an internationalized name in punycode' => ['https://xn--bcher-kva.example', 'https://xn--bcher-kva.example'],
			'the highest port' => ['https://paperless.example.com:65535', 'https://paperless.example.com:65535'],
			'a path below a path' => ['https://paperless.example.com/apps/paperless/', 'https://paperless.example.com/apps/paperless'],
		];
	}

	#[DataProvider('validUrls')]
	public function testValidUrlsAreAccepted(string $url, string $normalizedUrl): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		self::assertSame($normalizedUrl, $service->normalizeUrl($url));
	}

	/**
	 * The settings name each problem with a translated message of their own (#120).
	 */
	#[DataProvider('invalidUrlProvider')]
	public function testInvalidUrlsAreRejected(string $url, string $message, SettingProblem $problem): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		$exception = $this->refusal(static fn () => $service->normalizeUrl($url));

		self::assertSame($message, $exception->getMessage());
		self::assertSame($problem, $exception->problem);
	}

	/**
	 * @return iterable<string, array{string, string, SettingProblem}>
	 */
	public static function invalidUrlProvider(): iterable {
		yield 'empty' => ['', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'only slashes' => [' // ', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'not a URL' => ['paperless', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'unsupported protocol' => ['file:///etc/passwd', 'The Paperless URL must use HTTP or HTTPS.', SettingProblem::UnsupportedScheme];
		yield 'other protocol' => ['ftp://paperless.example.com', 'The Paperless URL must use HTTP or HTTPS.', SettingProblem::UnsupportedScheme];
		yield 'embedded credentials' => ['https://user:pass@example.com', 'The Paperless URL must not contain credentials, a query, or a fragment.', SettingProblem::UrlWithExtras];
		yield 'embedded user' => ['https://user@example.com', 'The Paperless URL must not contain credentials, a query, or a fragment.', SettingProblem::UrlWithExtras];
		yield 'query string' => ['https://example.com?token=TEST_VALUE', 'The Paperless URL must not contain credentials, a query, or a fragment.', SettingProblem::UrlWithExtras];
		yield 'fragment' => ['https://example.com/#documents', 'The Paperless URL must not contain credentials, a query, or a fragment.', SettingProblem::UrlWithExtras];
		yield 'no host' => ['https://', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'no host before the path' => ['http:///paperless', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'port 0' => ['http://paperless.example.com:0', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'a port above 65535' => ['http://paperless.example.com:65536', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'a blank in the host' => ['http://paper less.example.com', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'a blank in the path' => ['https://paperless.example.com/my documents', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'a control character' => ["https://paperless.example.com/\x07", 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'text that is not UTF-8' => ["https://paperless.example.com/\xff", 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'a host that starts with a hyphen' => ['http://-paperless.example.com', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'a host that ends with an underscore' => ['http://paperless_:8000', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'an escaped character in the host' => ['http://paper%20less.example.com', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'an IPv6 address without brackets' => ['http://fd00::5:8000', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'an IPv6 address that is none' => ['http://[fd00::zz]:8000', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'empty brackets' => ['http://[]', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'an IPv6 address without its closing bracket' => ['http://[::1:8000/paperless', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		yield 'an IPv4 address in brackets' => ['http://[192.168.1.20]', 'Enter a valid Paperless URL.', SettingProblem::InvalidUrl];
		// An empty label: the name has no ASCII form, with or without PHP's intl.
		yield 'an internationalized name without an ASCII form' => ['https://bücher..example', self::PUNYCODE, SettingProblem::HostNotAscii];
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function internationalizedUrls(): array {
		return [
			'a German name' => ['https://bücher.example', 'https://xn--bcher-kva.example'],
			'in capitals, with a port and a path' => ['HTTPS://Bücher.Example:8443/paperless/', 'HTTPS://xn--bcher-kva.example:8443/paperless'],
			'with a sharp s' => ['https://straße.example', 'https://xn--strae-oqa.example'],
			'a Hindi name, with combining marks' => ['http://हिन्दी.example', 'http://xn--j2bd4cyah0f.example'],
		];
	}

	/**
	 * DNS knows an internationalized name only in its ASCII form, punycode, so the
	 * settings store that (#117).
	 */
	#[DataProvider('internationalizedUrls')]
	#[RequiresFunction('idn_to_ascii')]
	public function testAnInternationalizedNameIsStoredInPunycode(string $url, string $normalizedUrl): void {
		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		self::assertSame($normalizedUrl, $service->normalizeUrl($url));
	}

	/**
	 * Nextcloud brings the functions of intl where PHP lacks the extension, but without
	 * them, the settings ask for the name in punycode instead of guessing.
	 */
	public function testWithoutIntlAnInternationalizedNameIsAskedForInPunycode(): void {
		if (function_exists('idn_to_ascii')) {
			self::markTestSkipped('PHP has the functions of intl.');
		}

		$service = $this->service($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class));

		$exception = $this->refusal(static fn () => $service->normalizeUrl('https://bücher.example'));

		self::assertSame(self::PUNYCODE, $exception->getMessage());
		self::assertSame(SettingProblem::HostNotAscii, $exception->problem);
	}

	/**
	 * The refusal of a setting by $refuse.
	 *
	 * @param callable(): mixed $refuse
	 */

	/**
	 * @return array<string, array{?bool, array<string, string>, bool}>
	 */
	public static function newDocumentAlerts(): array {
		return [
			// stored, what config.php names, allowed
			'nothing stored, with a local memory cache' => [null, ['memcache.local' => 'APCu'], true],
			'nothing stored, with a distributed memory cache' => [null, ['memcache.distributed' => 'Redis'], true],
			'nothing stored, without a memory cache' => [null, [], false],
			'turned on, without a memory cache' => [true, [], true],
			'turned off, with a memory cache' => [false, ['memcache.local' => 'APCu'], false],
		];
	}

	/**
	 * Until an administrator chooses, users may ask for notifications only where config.php
	 * names a memory cache, which is the same for the web and for cron.
	 *
	 * @param array<string, string> $system
	 */
	#[DataProvider('newDocumentAlerts')]
	public function testUsersMayAskForNotificationsAsChosenOrWithAMemoryCache(?bool $stored, array $system, bool $allowed): void {
		$values = $stored === null ? [] : [AppConstants::APP_ID . '.new_document_alerts' => $stored];
		$service = new ConfigService($this->settings($values), $this->createStub(ICredentialsManager::class), $this->accounts(), $this->createStub(LoggerInterface::class), $this->systemConfig($system));

		self::assertSame($allowed, $service->isNewDocumentAlertsAllowed());
		self::assertSame($allowed, $service->getPublicConfig()->newDocumentAlerts);
	}

	public function testSavingStoresTheChoiceOfNotificationsOrLeavesItAlone(): void {
		$config = $this->settings([]);
		$service = new ConfigService($config, $this->createStub(ICredentialsManager::class), $this->accounts(), $this->createStub(LoggerInterface::class), $this->systemConfig(['memcache.local' => 'APCu']));

		// No choice leaves the default of the memory cache, and says what counts.
		$result = $service->save('https://paperless.example.com', 'TEST_VALUE');
		self::assertNull($config->stored(AppConstants::APP_ID, 'new_document_alerts'));
		self::assertTrue($result->newDocumentAlerts);

		$result = $service->save('https://paperless.example.com', 'TEST_VALUE', newDocumentAlerts: false);
		self::assertFalse($config->stored(AppConstants::APP_ID, 'new_document_alerts'));
		self::assertFalse($result->newDocumentAlerts);

		// Disconnecting forgets the choice, and says what counts then.
		$result = $service->reset();
		self::assertNull($config->stored(AppConstants::APP_ID, 'new_document_alerts'));
		self::assertTrue($result->newDocumentAlerts);
	}

	/**
	 * The system configuration of a config.php that holds these strings.
	 *
	 * @param array<string, string> $values
	 */
	private function systemConfig(array $values): IConfig {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $values[$key] ?? $default);

		return $config;
	}

	private function refusal(callable $refuse): InvalidSettingException {
		try {
			$refuse();
		} catch (InvalidSettingException $exception) {
			return $exception;
		}

		self::fail('The setting was not refused.');
	}

	/**
	 * The service, with the accounts archive and sync in Nextcloud.
	 */
	private function service(IAppConfig $config, ICredentialsManager $credentials): ConfigService {
		return new ConfigService($config, $credentials, $this->accounts('archive', 'sync'), $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));
	}

	/**
	 * The configuration of the apps with these values, as the settings and Paperless Sync
	 * stored them.
	 *
	 * @param array<string, string|bool> $values by "app.key"
	 */
	private function settings(array $values): MemoryAppConfig {
		return new MemoryAppConfig($values);
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
