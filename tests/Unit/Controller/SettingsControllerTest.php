<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use DateTimeImmutable;
use JsonException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Controller\SettingsController;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SearchExplainer;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

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
			'paperlessVersion' => '',
			'paperlessApiVersion' => '',
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
			[
				'message' => 'DE:Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.',
				'code' => 'local_address',
			],
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
			->save('https://paperless.example.com', 'TEST_VALUE', false, ' nobody ');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'DE:The archive account nobody does not exist.', 'code' => 'unknown_archive_account'], $response->getData());
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
			['message' => 'DE:Enter the API token again: a stored token is only sent to the Paperless server it was saved for.', 'code' => 'token_for_other_server'],
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
		self::assertSame(['message' => 'DE:A Paperless API token is required.', 'code' => 'token_required'], $response->getData());
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function invalidSettings(): array {
		return [
			'invalid URL' => ['paperless', 'TEST_VALUE', 'invalid_url', 'Enter a valid Paperless URL.'],
			'unsupported protocol' => ['ftp://paperless.example.com', 'TEST_VALUE', 'unsupported_scheme', 'The Paperless URL must use HTTP or HTTPS.'],
			'port 0' => ['http://paperless_ngx:0', 'TEST_VALUE', 'invalid_url', 'Enter a valid Paperless URL.'],
			'a query' => ['https://paperless.example.com/?token=TEST_VALUE', 'TEST_VALUE', 'url_extras', 'The Paperless URL must not contain credentials, a query, or a fragment.'],
			'no token at all' => ['https://paperless.example.com', ' ', 'token_required', 'A Paperless API token is required.'],
		];
	}

	/**
	 * Each problem of a setting has a code and a message in the language of the
	 * administrator (#120).
	 */
	#[DataProvider('invalidSettings')]
	public function testSaveRejectsInvalidSettingsWithTheirReason(string $url, string $token, string $code, string $message): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client)
			->save($url, $token);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'DE:' . $message, 'code' => $code], $response->getData());
	}

	/**
	 * A name of a host that isn't ASCII and has no ASCII form, with or without the
	 * extension intl, has to be entered in punycode, and the settings say so.
	 */
	public function testSaveAsksForAnInternationalizedNameInPunycode(): void {
		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $this->createStub(IClient::class))
			->save('https://bücher..example', 'TEST_VALUE');

		self::assertSame([
			'message' => 'DE:Enter the host of the Paperless URL in its ASCII form (punycode), such as xn--bcher-kva.example for bücher.example.',
			'code' => 'host_not_ascii',
		], $response->getData());
	}

	/**
	 * @return array<string, array{IResponse|Throwable, string, string, class-string<Throwable>, string}>
	 */
	public static function failedConnections(): array {
		$curl = static fn (string $error): RuntimeException => new RuntimeException(
			'cURL error ' . $error . ' (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://paperless.example.com/api/documents/?page=1&page_size=1&fields=id',
		);
		$detail = static fn (string $error): string => 'cURL error ' . $error . ' for https://paperless.example.com/api/documents/';

		return [
			'a refused token' => [
				self::answer(401, '{"detail":"Invalid token."}'), 'unauthorized',
				'Paperless rejected the API token (HTTP 401). Check the token, and that its account may view documents.',
				PaperlessRequestException::class, 'Paperless returned HTTP 401.',
			],
			'an account without permission' => [
				self::answer(403, '{"detail":"You do not have permission to perform this action."}'), 'unauthorized',
				'Paperless rejected the API token (HTTP 403). Check the token, and that its account may view documents.',
				PaperlessRequestException::class, 'Paperless returned HTTP 403.',
			],
			'a wrong path' => [
				self::answer(404, '<html>Not found</html>'), 'not_found',
				'This URL answers, but not with the API of Paperless (HTTP 404). Check the address and its path.',
				PaperlessRequestException::class, 'Paperless returned HTTP 404.',
			],
			'a redirect' => [
				self::answer(301, ''), 'redirect',
				'This URL forwards to another address (HTTP 301). Enter the address that it forwards to.',
				PaperlessRequestException::class, 'Paperless returned HTTP 301.',
			],
			'a Paperless older than 2.16' => [
				self::answer(406, '{"detail":"Invalid version in \\"Accept\\" header."}'), 'unsupported_version',
				'Paperless is older than 2.16 and does not speak version 9 of its API (HTTP 406). Update Paperless-ngx.',
				PaperlessRequestException::class, 'Paperless returned HTTP 406.',
			],
			'an error of the server' => [
				self::answer(502, '<html>Bad gateway</html>'), 'http',
				'Paperless answered with an error (HTTP 502). Check its log, or try again later.',
				PaperlessRequestException::class, 'Paperless returned HTTP 502.',
			],
			'a page of HTML' => [
				self::answer(200, '<html>Sign in</html>'), 'not_paperless',
				'This URL answers, but not like Paperless-ngx. Check the address and its path.',
				JsonException::class, 'Syntax error',
			],
			'JSON without documents' => [
				self::answer(200, '{"detail":"Not found."}'), 'not_paperless',
				'This URL answers, but not like Paperless-ngx. Check the address and its path.',
				PaperlessRequestException::class, 'Paperless returned an invalid document response.',
			],
			'an untrusted certificate' => [
				$curl('60: SSL certificate problem: self-signed certificate'), 'tls',
				'Nextcloud does not trust the certificate of Paperless at this URL. If Paperless uses a certificate of its own, import it with occ security:certificates:import.',
				RuntimeException::class, $detail('60: SSL certificate problem: self-signed certificate'),
			],
			'plain HTTP at the port of HTTPS' => [
				$curl('35: OpenSSL/3.0.13: error:0A00010B:SSL routines::wrong version number'), 'tls_handshake',
				'Nextcloud could not set up an encrypted connection to Paperless at this URL. If Paperless answers plain HTTP at this port, enter the URL with http://.',
				RuntimeException::class, $detail('35: OpenSSL/3.0.13: error:0A00010B:SSL routines::wrong version number'),
			],
			'a host that does not resolve' => [
				$curl('6: Could not resolve host: paperless.invalid'), 'unknown_host',
				'Nextcloud could not find the host of this URL. Check its spelling; the name of a Docker service only works when Nextcloud runs in the same Docker network.',
				RuntimeException::class, $detail('6: Could not resolve host: paperless.invalid'),
			],
			'a host that does not resolve, found by the DNS pinning of Nextcloud' => [
				new LocalServerException('No DNS record found for paperless.invalid'), 'unknown_host',
				'Nextcloud could not find the host of this URL. Check its spelling; the name of a Docker service only works when Nextcloud runs in the same Docker network.',
				LocalServerException::class, 'No DNS record found for paperless.invalid',
			],
			'no answer in time' => [
				$curl('28: Operation timed out after 10001 milliseconds with 0 bytes received'), 'timeout',
				'Paperless did not answer in time. Check that it runs and is not overloaded, then try again.',
				RuntimeException::class, $detail('28: Operation timed out after 10001 milliseconds with 0 bytes received'),
			],
			'a refused connection' => [
				$curl('7: Failed to connect to paperless.example.com port 443 after 3 ms: Connection refused'), 'unreachable',
				'Nextcloud could not reach Paperless at this URL. Check the address and the port, and that Paperless runs.',
				RuntimeException::class, $detail('7: Failed to connect to paperless.example.com port 443 after 3 ms: Connection refused'),
			],
		];
	}

	/**
	 * Every failed test of the connection said "Could not connect to Paperless", in
	 * English, and logged nothing. Now each cause has its code and a message in the
	 * language of the administrator that says what to correct, and the log names the error
	 * without the token and the query of the URL; nothing is saved (#120).
	 *
	 * @param class-string<Throwable> $errorType
	 */
	#[DataProvider('failedConnections')]
	public function testSaveNamesTheCauseOfAFailedConnectionAndLogsIt(IResponse|Throwable $answer, string $code, string $message, string $errorType, string $detail): void {
		$client = $this->createStub(IClient::class);
		if ($answer instanceof Throwable) {
			$client->method('get')->willThrowException($answer);
		} else {
			$client->method('get')->willReturn($answer);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('The test of the connection to Paperless failed: {reason} ({errorType}: {detail})', [
				'app' => AppConstants::APP_ID,
				'reason' => $code,
				'errorType' => $errorType,
				'detail' => $detail,
			]);

		$response = $this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client, $logger)
			->save('https://paperless.example.com', 'TEST_VALUE', true);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'DE:' . $message, 'code' => $code], $response->getData());
	}

	/**
	 * The log never shows the token, also where an error repeats it.
	 */
	public function testTheLogOfAFailedConnectionLeavesOutTheToken(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('cURL error 7: Token SECRET_TEST_VALUE refused'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(self::anything(), self::callback(static fn (array $context): bool => $context['detail'] === 'cURL error 7: Token … refused'));

		$this->controller($this->unchangedConfig(), $this->unchangedCredentials(), $client, $logger)
			->save('https://paperless.example.com', 'SECRET_TEST_VALUE');
	}

	/**
	 * When saving fails after Paperless answered, for example in the database, the answer
	 * says so instead of blaming the connection, and the log has the error.
	 */
	public function testSaveSaysWhenTheSettingsCannotBeStored(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, self::NO_DOCUMENTS));

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('store')->willThrowException(new RuntimeException('The database went away while storing SECRET_TEST_VALUE.'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::once())
			->method('error')
			->with('The settings of Paperless Unified Search could not be saved ({errorType}: {detail})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
				'detail' => 'The database went away while storing ….',
			]);

		$response = $this->controller($this->createStub(IAppConfig::class), $credentials, $client, $logger)
			->save('https://paperless.example.com', 'SECRET_TEST_VALUE');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame([
			'message' => 'DE:The settings could not be saved. The log of Nextcloud has the details.',
			'code' => 'internal',
		], $response->getData());
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
			'paperlessVersion' => '',
			'paperlessApiVersion' => '',
		], json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR));
		self::assertContains(AppConstants::APP_ID . '.diagnostics', $deleted);
	}

	public function testClearDiagnosticsForgetsTheHistoryOnly(): void {
		$config = new MemoryAppConfig([
			AppConstants::APP_ID . '.paperless_url' => self::STORED_URL,
			AppConstants::APP_ID . '.always_search' => true,
		]);
		$config->seed(AppConstants::APP_ID, 'diagnostics', ['since' => 1791500000, 'failed' => 1, 'retried' => 0, 'lastFailureAt' => 1791500000, 'recoveredAt' => null, 'events' => []], true);
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::never())->method('delete');

		$response = $this->controller($config, $credentials, $this->createStub(IClient::class))->clearDiagnostics();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
		self::assertSame([AppConstants::APP_ID . '.diagnostics'], $config->calls('deleteKey'));
		// The settings stay; no failure waits for a search that works any more.
		self::assertSame([
			'paperless_url' => self::STORED_URL,
			'always_search' => true,
			'diagnostics_pending' => false,
		], $config->storedValues(AppConstants::APP_ID));
	}

	/**
	 * The controller, in a Nextcloud with the account archive, which translates every
	 * text into "DE:" and the text with its parameters.
	 */
	private function controller(IAppConfig $config, ICredentialsManager $credentials, IClient $client, ?LoggerInterface $logger = null): SettingsController {
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$accounts = $this->accounts('archive');
		$configService = new ConfigService($config, $credentials, $accounts, $this->createStub(LoggerInterface::class));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('@1791500000'));
		$diagnostics = new SearchDiagnostics($config, $clock, $this->createStub(ICacheFactory::class));
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.5.1');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'DE:' . vsprintf($text, $parameters),
		);

		return new SettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			new PaperlessApiService($configService, $clientService, $diagnostics, $clock, $appManager),
			$diagnostics,
			new SettingsMessages($l10n),
			$l10n,
			$logger ?? $this->createStub(LoggerInterface::class),
			new SearchExplainer(new NextcloudFileLocator($this->createStub(IRootFolder::class), $this->createStub(LoggerInterface::class)), $accounts),
			$accounts,
			$this->createStub(IUserSession::class),
			$this->createStub(IEventDispatcher::class),
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

	private function response(int $status, string $body): IResponse {
		return self::answer($status, $body);
	}

	/**
	 * An answer of Paperless, also for the data providers, which run before any test.
	 */
	private static function answer(int $status, string $body): IResponse {
		return new class($status, $body) implements IResponse {
			public function __construct(
				private int $status,
				private string $body,
			) {
			}

			public function getBody() {
				return $this->body;
			}

			public function getStatusCode(): int {
				return $this->status;
			}

			public function getHeader(string $key): string {
				return '';
			}

			public function getHeaders(): array {
				return [];
			}
		};
	}
}
