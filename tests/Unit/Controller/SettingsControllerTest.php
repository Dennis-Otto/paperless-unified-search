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
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsControllerTest extends TestCase {
	private const NO_DOCUMENTS = '{"count":0,"next":null,"results":[]}';

	public function testSaveStoresTheSettingsOnceTheConnectionWorks(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token TEST_VALUE'
					&& $options['query'] === ['page' => 1, 'page_size' => 1]),
			)
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('setValueString')
			->with(AppConstants::APP_ID, 'paperless_url', 'https://paperless.example.com');
		$config->expects(self::once())
			->method('setValueBool')
			->with(AppConstants::APP_ID, 'always_search', true);
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
		], json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('TEST_VALUE', $response->render());
		self::assertNotContains(AppConstants::APP_ID . '.diagnostics', $deleted);
	}

	public function testSaveStoresAnArchiveAccountThatExists(): void {
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

		$users = $this->createMock(IUserManager::class);
		$users->expects(self::once())->method('userExists')->with('archive')->willReturn(true);

		$response = $this->controller($config, $this->createStub(ICredentialsManager::class), $client, $users)
			->save('https://paperless.example.com', 'TEST_VALUE', false, ' archive ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('archive', $stored[AppConstants::APP_ID . '.archive_owner']);
		self::assertSame('archive', json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR)['archiveOwner']);
	}

	public function testSaveRejectsAnArchiveAccountThatDoesNotExist(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$users = $this->createStub(IUserManager::class);
		$users->method('userExists')->willReturn(false);

		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client, $users)
			->save('https://paperless.example.com', 'TEST_VALUE', false, 'nobody');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'The archive account does not exist.'], $response->getData());
	}

	public function testSaveWithoutATokenKeepsTheStoredOne(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token STORED_TEST_VALUE'),
			)
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('STORED_TEST_VALUE');
		$credentials->expects(self::once())
			->method('store')
			->with('', AppConstants::APP_ID . '.api-token', 'STORED_TEST_VALUE');

		$response = $this->controller($this->createStub(IAppConfig::class), $credentials, $client)
			->save('https://paperless.example.com', '');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse(json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR)['alwaysSearch']);
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalidSettings(): array {
		return [
			'invalid URL' => ['paperless', 'TEST_VALUE', 'Enter a valid Paperless URL.'],
			'unsupported protocol' => ['ftp://paperless.example.com', 'TEST_VALUE', 'The Paperless URL must use HTTP or HTTPS.'],
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

	private function controller(
		IAppConfig $config,
		ICredentialsManager $credentials,
		IClient $client,
		?IUserManager $users = null,
	): SettingsController {
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$configService = new ConfigService($config, $credentials);
		$diagnostics = new SearchDiagnostics($config, $this->createStub(ITimeFactory::class));

		return new SettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			new PaperlessApiService($configService, $clientService, $diagnostics),
			$users ?? $this->createStub(IUserManager::class),
			$diagnostics,
		);
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
