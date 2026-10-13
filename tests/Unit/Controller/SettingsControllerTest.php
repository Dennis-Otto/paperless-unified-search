<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Controller\SettingsController;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SettingsControllerTest extends TestCase {
	use Accounts;

	private const NO_DOCUMENTS = '{"count":0,"next":null,"results":[]}';
	private const STORED_URL = 'https://paperless.example.com';

	public function testSaveStoresTheSettingsOnceTheConnectionWorks(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token TEST_VALUE'
					&& $options['query'] === ['page' => 1, 'page_size' => 1, 'fields' => 'id']
					&& $options['nextcloud'] === ['allow_local_address' => false]
					&& !isset($options['allow_redirects'])),
			)
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('setValueString')
			->with(AppConstants::APP_ID, 'paperless_url', 'https://paperless.example.com');
		$switches = [];
		$config->expects(self::exactly(2))
			->method('setValueBool')
			->willReturnCallback(static function (string $app, string $key, bool $value) use (&$switches): bool {
				$switches[$app . '.' . $key] = $value;

				return true;
			});
		$config->expects(self::never())->method('setValueArray');
		$deleted = [];
		$config->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$deleted): void {
			$deleted[] = $app . '.' . $key;
		});

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::once())
			->method('store')
			->with('', AppConstants::APP_ID . '.api-token', 'TEST_VALUE');

		$response = $this->controller($config, $credentials, $client)
			->save(' https://paperless.example.com/ ', ' TEST_VALUE ', true);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'url' => 'https://paperless.example.com',
			'tokenConfigured' => true,
			'alwaysSearch' => true,
			'archiveOwner' => '',
			'syncAccount' => '',
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
			'allowLocal' => false,
		], json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('TEST_VALUE', $response->render());
		self::assertNotContains(AppConstants::APP_ID . '.diagnostics', $deleted);
		self::assertSame([AppConstants::APP_ID . '.always_search' => true, AppConstants::APP_ID . '.allow_local' => false], $switches);
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
	 * A Paperless in the local network or in Docker needs no change of config.php, which
	 * would let every app reach every address of the network: the switch of the settings
	 * allows it for this Paperless alone, for the test of the connection and then for the
	 * searches (#117).
	 */
	#[DataProvider('localNetwork')]
	public function testSaveAllowsTheLocalNetworkForThisPaperlessOnlyWhenAsked(bool $allowLocal): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'http://paperless_ngx:8000/api/documents/',
				self::callback(static fn (array $options): bool => $options['nextcloud'] === ['allow_local_address' => $allowLocal]),
			)
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$switches = [];
		$config = $this->createStub(IAppConfig::class);
		$config->method('setValueBool')->willReturnCallback(static function (string $app, string $key, bool $value) use (&$switches): bool {
			$switches[$app . '.' . $key] = $value;

			return true;
		});

		$response = $this->controller($config, $this->createStub(ICredentialsManager::class), $client)
			->save('http://paperless_ngx:8000', 'TEST_VALUE', false, '', $allowLocal);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($allowLocal, $switches[AppConstants::APP_ID . '.allow_local'] ?? null);
		self::assertSame($allowLocal, json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR)['allowLocal']);
	}

	/**
	 * Nextcloud refuses an address of the local network unless it is allowed, and the
	 * administrator saw only that the connection failed (#117).
	 */
	public function testSaveNamesTheSwitchWhenNextcloudRefusesALocalAddress(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willThrowException(new LocalServerException('Host "paperless-ngx" violates local access rules'));

		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client)
			->save('http://paperless-ngx:8000', 'TEST_VALUE');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['message' => 'Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.'],
			$response->getData(),
		);
	}

	/**
	 * Nextcloud finds an account whatever the case of its ID, but the search compares the
	 * exact ID with the owners of the files (#99).
	 */
	public function testSaveStoresTheArchiveAccountAsNextcloudSpellsIt(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, self::NO_DOCUMENTS));

		$stored = [];
		$config = $this->createStub(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$app . '.' . $key] = $value;

				return true;
			},
		);

		$response = $this->controller($config, $this->createStub(ICredentialsManager::class), $client)
			->save('https://paperless.example.com', 'TEST_VALUE', false, ' Archive ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('archive', $stored[AppConstants::APP_ID . '.archive_owner']);
		$data = json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('archive', $data['archiveOwner']);
		self::assertFalse($data['archiveOwnerMissing']);
	}

	public function testSaveRejectsAnArchiveAccountThatDoesNotExist(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client)
			->save('https://paperless.example.com', 'TEST_VALUE', false, 'nobody');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'The archive account does not exist.'], $response->getData());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function sameServers(): array {
		return [
			'the same URL' => ['https://paperless.example.com', 'https://paperless.example.com/api/documents/'],
			'a slash at the end' => ['https://paperless.example.com/', 'https://paperless.example.com/api/documents/'],
			'another path' => ['https://paperless.example.com/paperless/', 'https://paperless.example.com/paperless/api/documents/'],
			'the port of HTTPS named' => ['https://Paperless.Example.com:443', 'https://Paperless.Example.com:443/api/documents/'],
		];
	}

	#[DataProvider('sameServers')]
	public function testSaveWithoutATokenKeepsTheStoredOneForTheSameServer(string $url, string $request): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				$request,
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token STORED_TEST_VALUE'),
			)
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('STORED_TEST_VALUE');
		$credentials->expects(self::once())
			->method('store')
			->with('', AppConstants::APP_ID . '.api-token', 'STORED_TEST_VALUE');

		$response = $this->controller($this->storedUrl(), $credentials, $client)->save($url, '');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse(json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR)['alwaysSearch']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function otherServers(): array {
		return [
			'another host' => ['https://attacker.example'],
			'plain HTTP' => ['http://paperless.example.com'],
			'another port' => ['https://paperless.example.com:8443'],
			'a subdomain' => ['https://evil.paperless.example.com/'],
		];
	}

	/**
	 * Whoever can use the session of an administrator must not be able to collect the
	 * stored token with a server of their own, or read it on its way over plain HTTP,
	 * just by changing the URL and leaving the token blank (#99).
	 */
	#[DataProvider('otherServers')]
	public function testSaveWithoutATokenNeverSendsTheStoredOneToAnotherServer(string $url): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('STORED_TEST_VALUE');
		$credentials->expects(self::never())->method('store');

		$response = $this->controller($this->storedUrl(), $credentials, $client)->save($url, ' ', true, 'archive');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['message' => 'Enter the API token again: a stored token is only sent to the Paperless server it was saved for.'],
			$response->getData(),
		);
		self::assertStringNotContainsString('STORED_TEST_VALUE', $response->render());
	}

	public function testSaveSendsANewTokenToANewServer(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.other.example/api/documents/',
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token NEW_TEST_VALUE'),
			)
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('retrieve');
		$credentials->expects(self::once())
			->method('store')
			->with('', AppConstants::APP_ID . '.api-token', 'NEW_TEST_VALUE');

		$response = $this->controller($this->storedUrl(), $credentials, $client)
			->save('https://paperless.other.example', 'NEW_TEST_VALUE');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('https://paperless.other.example', json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR)['url']);
	}

	/**
	 * After a move or a restore of Nextcloud with another secret in config.php, the stored
	 * token can't be decrypted: the administrator enters it again (#99).
	 */
	public function testSaveStoresANewTokenWhenTheStoredOneCannotBeDecrypted(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, self::NO_DOCUMENTS));

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willThrowException(new RuntimeException('HMAC does not match.'));
		$credentials->expects(self::once())
			->method('store')
			->with('', AppConstants::APP_ID . '.api-token', 'NEW_TEST_VALUE');

		$response = $this->controller($this->storedUrl(), $credentials, $client)->save(self::STORED_URL, 'NEW_TEST_VALUE');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
		self::assertTrue($data['tokenConfigured']);
		self::assertFalse($data['tokenUnreadable']);
	}

	public function testSaveWithoutATokenAsksForOneWhenTheStoredOneCannotBeDecrypted(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willThrowException(new RuntimeException('HMAC does not match.'));
		$credentials->expects(self::never())->method('store');

		$response = $this->controller($this->storedUrl(), $credentials, $client)->save(self::STORED_URL, '');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'A Paperless API token is required.'], $response->getData());
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalidSettings(): array {
		return [
			'invalid URL' => ['paperless', 'TEST_VALUE', 'Enter a valid Paperless URL.'],
			'unsupported protocol' => ['ftp://paperless.example.com', 'TEST_VALUE', 'The Paperless URL must use HTTP or HTTPS.'],
			'port 0' => ['http://paperless_ngx:0', 'TEST_VALUE', 'Enter a valid Paperless URL.'],
			'no token at all' => ['https://paperless.example.com', ' ', 'A Paperless API token is required.'],
		];
	}

	#[DataProvider('invalidSettings')]
	public function testSaveRejectsInvalidSettingsWithTheirReason(string $url, string $token, string $message): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client)
			->save($url, $token);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => $message], $response->getData());
	}

	public function testSaveKeepsTheSettingsWhenPaperlessIsOutOfReach(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('cURL error 7 for TEST_VALUE'));

		$this->assertConnectionFailed($client);
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function wrongAnswers(): array {
		return [
			'refused token' => [403, '{"detail":"Invalid token."}'],
			'not Paperless' => [200, '<html>'],
			'no documents' => [200, '{"detail":"Not found."}'],
		];
	}

	#[DataProvider('wrongAnswers')]
	public function testSaveKeepsTheSettingsWhenPaperlessAnswersWrongly(int $status, string $body): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response($status, $body));

		$this->assertConnectionFailed($client);
	}

	public function testResetForgetsTheSettings(): void {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::once())
			->method('delete')
			->with('', AppConstants::APP_ID . '.api-token');

		$deleted = [];
		$config = $this->createStub(IAppConfig::class);
		$config->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$deleted): void {
			$deleted[] = $app . '.' . $key;
		});

		$response = $this->controller($config, $credentials, $this->createStub(IClient::class))
			->reset();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'url' => '',
			'tokenConfigured' => false,
			'alwaysSearch' => false,
			'archiveOwner' => '',
			'syncAccount' => '',
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
			'allowLocal' => false,
		], json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR));
		self::assertContains(AppConstants::APP_ID . '.diagnostics', $deleted);
	}

	public function testClearDiagnosticsForgetsTheHistoryOnly(): void {
		$deleted = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$deleted): void {
			$deleted[] = $app . '.' . $key;
		});
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('delete');

		$response = $this->controller($config, $credentials, $this->createStub(IClient::class))->clearDiagnostics();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
		self::assertSame([
			AppConstants::APP_ID . '.diagnostics',
			AppConstants::APP_ID . '.last_failure',
			AppConstants::APP_ID . '.last_recovery',
		], $deleted);
	}

	/**
	 * The controller, in a Nextcloud with the account archive.
	 */
	private function controller(IAppConfig $config, ICredentialsManager $credentials, IClient $client): SettingsController {
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$configService = new ConfigService($config, $credentials, $this->accounts('archive'), $this->createStub(LoggerInterface::class));
		$diagnostics = new SearchDiagnostics($config, $this->createStub(ITimeFactory::class));

		return new SettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			new PaperlessApiService($configService, $clientService, $diagnostics),
			$diagnostics,
		);
	}

	/**
	 * Settings that hold the URL that the stored token was saved for.
	 */
	private function storedUrl(): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $app . '.' . $key === AppConstants::APP_ID . '.paperless_url' ? self::STORED_URL : '',
		);

		return $config;
	}

	private function unchangedConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::never())->method('setValueString');
		$config->expects(self::never())->method('setValueBool');

		return $config;
	}

	private function unchangedCredentials(): ICredentialsManager {
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('store');

		return $credentials;
	}

	private function assertConnectionFailed(IClient $client): void {
		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client)
			->save('https://paperless.example.com', 'TEST_VALUE', true);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['message' => 'Could not connect to Paperless. Check the URL, API token, and Nextcloud outbound connection policy.'],
			$response->getData(),
		);
	}

	private function response(int $status, string $body): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}
}
