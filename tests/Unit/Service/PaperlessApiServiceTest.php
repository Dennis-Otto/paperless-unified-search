<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use JsonException;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class PaperlessApiServiceTest extends TestCase {
	private const NO_DOCUMENTS = '{"count":0,"next":null,"results":[]}';

	/** @var array<string, mixed> the values that the diagnostics stored, by key */
	private array $stored = [];

	/**
	 * @return array<string, array{string, ?string, bool}>
	 */
	public static function configurations(): array {
		return [
			'URL and token' => ['https://paperless.example.com', 'TEST_VALUE', true],
			'no URL' => ['', 'TEST_VALUE', false],
			'no token' => ['https://paperless.example.com', null, false],
		];
	}

	#[DataProvider('configurations')]
	public function testIsConfiguredWithAUrlAndAToken(string $url, ?string $token, bool $configured): void {
		self::assertSame($configured, $this->service($this->createStub(IClient::class), $url, $token)->isConfigured());
	}

	/**
	 * The connection test needs no field of the document, so Paperless sends none but its ID.
	 */
	public function testTheConnectionTestAsksForOneDocumentWithTheGivenSettings(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('https://paperless.example.com/api/documents/', [
				'headers' => [
					'Authorization' => 'Token TEST_VALUE',
					'Accept' => 'application/json',
					'User-Agent' => 'Nextcloud-Paperless-Unified-Search/0.1',
				],
				'connect_timeout' => 3,
				'timeout' => 10,
				'http_errors' => false,
				'query' => ['page' => 1, 'page_size' => 1, 'fields' => 'id'],
				'nextcloud' => ['allow_local_address' => false],
			])
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$this->service($client, '', null, true)->testConnection('https://paperless.example.com/', 'TEST_VALUE', false);
	}

	/**
	 * The test of a connection allows the local network as the form says, not as the
	 * stored settings do (#117).
	 */
	public function testTheConnectionTestAllowsTheLocalNetworkAsTheFormSays(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('http://paperless_ngx:8000/api/documents/', self::callback(
				static fn (array $options): bool => $options['nextcloud'] === ['allow_local_address' => true]
					&& $options['allow_redirects'] === false,
			))
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$this->service($client, '', null, false)->testConnection('http://paperless_ngx:8000', 'TEST_VALUE', true);
	}

	/**
	 * @return array<string, array{bool, array<string, mixed>}>
	 */
	public static function localNetwork(): array {
		return [
			// Nextcloud then also follows a redirect anywhere unchecked, so the request
			// follows none.
			'allowed' => [true, ['nextcloud' => ['allow_local_address' => true], 'allow_redirects' => false]],
			// Nextcloud follows a redirect only to an address that it would reach as well.
			'not allowed' => [false, ['nextcloud' => ['allow_local_address' => false]]],
		];
	}

	/**
	 * Nextcloud refuses addresses of the local network to a request that doesn't allow
	 * them, unless config.php allows them to every request of every app (#117).
	 *
	 * @param array<string, mixed> $options
	 */
	#[DataProvider('localNetwork')]
	public function testSearchAllowsTheLocalNetworkAsTheSettingsSay(bool $allowLocal, array $options): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('https://paperless.example.com/api/documents/', self::callback(
				static fn (array $sent): bool => array_intersect_key($sent, ['nextcloud' => true, 'allow_redirects' => true]) === $options,
			))
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$this->service($client, allowLocal: $allowLocal)->searchDocuments('invoice', 1, 10);
	}

	/**
	 * Nextcloud refuses an address of the local network before any request leaves it, and
	 * would refuse it again (#117).
	 */
	public function testAnAddressThatNextcloudRefusesGetsNoSecondTry(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willThrowException(new LocalServerException('Host "paperless-ngx" violates local access rules'));

		try {
			$this->service($client)->searchDocuments('invoice', 1, 10);
			self::fail('The refused address did not fail the search.');
		} catch (LocalServerException $exception) {
			self::assertSame('Host "paperless-ngx" violates local access rules', $exception->getMessage());
		}
		self::assertSame([], $this->stored);
	}

	/**
	 * @return array<string, array{int, int, int, int}>
	 */
	public static function pages(): array {
		return [
			'within the limits' => [2, 20, 2, 20],
			'page below one' => [0, 20, 1, 20],
			'page size below one' => [1, 0, 1, 1],
			'page size above fifty' => [1, 500, 1, 50],
		];
	}

	/**
	 * Without the fields, Paperless sends the whole OCR text of every document, its notes,
	 * custom fields, permissions and versions, half a megabyte for a long document.
	 */
	#[DataProvider('pages')]
	public function testSearchAsksPaperlessWithTheStoredSettingsWithinItsLimits(int $page, int $pageSize, int $expectedPage, int $expectedPageSize): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token TEST_VALUE'
					&& $options['query'] === [
						'query' => 'invoice',
						'page' => $expectedPage,
						'page_size' => $expectedPageSize,
						'fields' => 'id,title,created,correspondent,document_type,__search_hit__',
					]),
			)
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$this->service($client)->searchDocuments('invoice', $page, $pageSize);
	}

	/**
	 * @return array<string, array{array<string, mixed>, array{count: int, next: ?string, results: list<array<array-key, mixed>>}}>
	 */
	public static function answers(): array {
		return [
			'complete answer' => [
				['count' => 3, 'next' => 'https://paperless.example.com/api/documents/?page=2', 'results' => [['id' => 1], ['id' => 2]]],
				['count' => 3, 'next' => 'https://paperless.example.com/api/documents/?page=2', 'results' => [['id' => 1], ['id' => 2]]],
			],
			'count as text' => [
				['count' => '3', 'next' => null, 'results' => [['id' => 1]]],
				['count' => 3, 'next' => null, 'results' => [['id' => 1]]],
			],
			'no count and no next page' => [
				['results' => [['id' => 1], ['id' => 2]]],
				['count' => 2, 'next' => null, 'results' => [['id' => 1], ['id' => 2]]],
			],
			'count and next page of the wrong type' => [
				['count' => 'many', 'next' => 2, 'results' => [['id' => 1]]],
				['count' => 1, 'next' => null, 'results' => [['id' => 1]]],
			],
			'results that are no documents' => [
				['count' => 4, 'next' => null, 'results' => [['id' => 1], 'invoice', 42, null, ['id' => 2]]],
				['count' => 4, 'next' => null, 'results' => [['id' => 1], ['id' => 2]]],
			],
		];
	}

	/**
	 * @param array<string, mixed> $answer
	 * @param array{count: int, next: ?string, results: list<array<array-key, mixed>>} $expected
	 */
	#[DataProvider('answers')]
	public function testSearchReturnsTheDocumentsOfTheAnswer(array $answer, array $expected): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, json_encode($answer, JSON_THROW_ON_ERROR)));

		self::assertSame($expected, $this->service($client)->searchDocuments('invoice', 1, 10));
	}

	/**
	 * @return array<string, array{string, ?string}>
	 */
	public static function missingSettings(): array {
		return [
			'no URL' => ['', 'TEST_VALUE'],
			'no token' => ['https://paperless.example.com', null],
		];
	}

	#[DataProvider('missingSettings')]
	public function testSearchNeedsTheSettingsBeforeAskingPaperless(string $url, ?string $token): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Paperless is not configured.');

		$this->service($client, $url, $token)->searchDocuments('invoice', 1, 10);
	}

	/**
	 * @return array<string, array{int, ?string, RequestFailure, ?int, string}>
	 */
	public static function failures(): array {
		return [
			'informational status' => [100, self::NO_DOCUMENTS, RequestFailure::Http, 100, 'Paperless returned HTTP 100.'],
			'redirect' => [302, '', RequestFailure::Redirect, 302, 'Paperless returned HTTP 302.'],
			'permanent redirect' => [301, '', RequestFailure::Redirect, 301, 'Paperless returned HTTP 301.'],
			'refused token' => [401, '{"detail":"Invalid token."}', RequestFailure::Unauthorized, 401, 'Paperless returned HTTP 401.'],
			'no permission' => [403, '{"detail":"You do not have permission to perform this action."}', RequestFailure::Unauthorized, 403, 'Paperless returned HTTP 403.'],
			'wrong path' => [404, '<html>Not found</html>', RequestFailure::NotFound, 404, 'Paperless returned HTTP 404.'],
			'wrong request' => [400, '{"query":["Invalid."]}', RequestFailure::Http, 400, 'Paperless returned HTTP 400.'],
			'server error' => [500, '', RequestFailure::Http, 500, 'Paperless returned HTTP 500.'],
			'no body' => [200, null, RequestFailure::NotPaperless, null, 'Paperless returned an invalid response body.'],
			'no JSON' => [200, '<html>', RequestFailure::NotPaperless, null, 'Paperless returned no JSON: Syntax error'],
			'no object' => [200, '"ok"', RequestFailure::NotPaperless, null, 'Paperless returned an invalid document response.'],
			'no results' => [200, '{"count":0}', RequestFailure::NotPaperless, null, 'Paperless returned an invalid document response.'],
			'results that are no list' => [200, '{"results":"none"}', RequestFailure::NotPaperless, null, 'Paperless returned an invalid document response.'],
		];
	}

	/**
	 * Each wrong answer has a reason of its own, which the settings name with a message
	 * that says what to correct (#120).
	 */
	#[DataProvider('failures')]
	public function testAWrongAnswerOfPaperlessFailsWithItsReason(int $status, ?string $body, RequestFailure $reason, ?int $answeredStatus, string $message): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response($status, $body));

		$exception = $this->failedConnectionTest($client);

		self::assertSame($reason, $exception->reason);
		self::assertSame($answeredStatus, $exception->status);
		self::assertSame($message, $exception->getMessage());
	}

	public function testAnAnswerThatIsNoJsonKeepsTheErrorOfJson(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, '<html>Sign in</html>'));

		self::assertInstanceOf(JsonException::class, $this->failedConnectionTest($client)->getPrevious());
	}

	/**
	 * @return array<string, array{Throwable, RequestFailure}>
	 */
	public static function errorsWithoutAnswer(): array {
		$localAddress = new LocalServerException('Host "paperless-ngx" violates local access rules');

		return [
			'an address that Nextcloud refuses' => [$localAddress, RequestFailure::LocalAddress],
			'an address that Nextcloud refuses, inside another error' => [new RuntimeException('The request failed.', 0, $localAddress), RequestFailure::LocalAddress],
			'an untrusted certificate' => [
				new RuntimeException('cURL error 60: SSL certificate problem: self-signed certificate (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://paperless.example.com/api/documents/?page=1&page_size=1&fields=id'),
				RequestFailure::Certificate,
			],
			'a certificate for another name' => [new RuntimeException('cURL error 51: SSL: no alternative certificate subject name matches target host name'), RequestFailure::Certificate],
			'a certificate of an unknown issuer' => [new RuntimeException('cURL error 83: SSL: issuer check failed'), RequestFailure::Certificate],
			'certificates that Nextcloud cannot read' => [new RuntimeException('cURL error 77: error setting certificate verify locations'), RequestFailure::Certificate],
			'a problem with a certificate of the client' => [new RuntimeException('cURL error 58: unable to set private key file'), RequestFailure::Handshake],
			'no common cipher' => [new RuntimeException('cURL error 59: failed setting cipher list'), RequestFailure::Handshake],
			'an error of cURL inside another error' => [new RuntimeException('The request failed.', 0, new RuntimeException('cURL error 60: SSL certificate problem')), RequestFailure::Certificate],
			'plain HTTP at the port of HTTPS' => [new RuntimeException('cURL error 35: OpenSSL/3.0.13: error:0A00010B:SSL routines::wrong version number'), RequestFailure::Handshake],
			'a host that does not resolve' => [new RuntimeException('cURL error 6: Could not resolve host: paperless.invalid'), RequestFailure::UnknownHost],
			'no answer in time' => [new RuntimeException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received'), RequestFailure::Timeout],
			'a refused connection' => [new RuntimeException('cURL error 7: Failed to connect to paperless.example.com port 443'), RequestFailure::Unreachable],
			'an error without a code of cURL' => [new RuntimeException('Network is down'), RequestFailure::Unreachable],
		];
	}

	/**
	 * A test of the connection that gets no answer names the cause, from the error of
	 * cURL, and keeps its message for the log (#120).
	 */
	#[DataProvider('errorsWithoutAnswer')]
	public function testAConnectionTestWithoutAnswerNamesItsCause(Throwable $error, RequestFailure $reason): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException($error);

		$exception = $this->failedConnectionTest($client);

		self::assertSame($reason, $exception->reason);
		self::assertNull($exception->status);
		self::assertSame($error->getMessage(), $exception->getMessage());
		self::assertSame($error, $exception->getPrevious());
	}

	/**
	 * A search keeps the error of a request without answer as it is, so that the history
	 * of problems names it, such as ConnectException.
	 */
	public function testASearchWithoutAnswerFailsWithTheErrorOfTheRequest(): void {
		$error = new RuntimeException('cURL error 7: Failed to connect');
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException($error);

		try {
			$this->service($client)->searchDocuments('invoice', 1, 10);
			self::fail('The search without answer did not fail.');
		} catch (Throwable $exception) {
			self::assertSame($error, $exception);
		}
	}

	public function testARequestWithoutAnswerGetsASecondTry(): void {
		$answer = $this->response(200, '{"count":1,"next":null,"results":[{"id":7}]}');
		$calls = 0;
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->willReturnCallback(static function () use (&$calls, $answer): IResponse {
				if (++$calls === 1) {
					throw new RuntimeException('cURL error 28: Connection timed out after 3001 milliseconds for https://paperless.example.com/api/documents/?query=invoice');
				}

				return $answer;
			});

		self::assertSame(
			['count' => 1, 'next' => null, 'results' => [['id' => 7]]],
			$this->service($client)->searchDocuments('invoice', 1, 10),
		);
		self::assertSame(1, $this->stored['diagnostics']['retried'] ?? null);
		self::assertSame(
			'cURL error 28: Connection timed out after 3001 milliseconds for https://paperless.example.com/api/documents/',
			$this->stored['diagnostics']['events'][0]['message'] ?? null,
		);
	}

	public function testASecondRequestWithoutAnswerFails(): void {
		$calls = 0;
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->willReturnCallback(static function () use (&$calls): never {
				throw new RuntimeException(++$calls === 1 ? 'cURL error 6: Could not resolve host' : 'cURL error 7: Failed to connect');
			});

		try {
			$this->service($client)->searchDocuments('invoice', 1, 10);
			self::fail('The second request without answer did not fail.');
		} catch (RuntimeException $exception) {
			self::assertSame('cURL error 7: Failed to connect', $exception->getMessage());
		}
		// The search that fails records the failure, not the request.
		self::assertSame([], $this->stored);
	}

	public function testAnAnswerOfPaperlessGetsNoSecondTry(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willReturn($this->response(502, '<html>Bad gateway</html>'));

		$this->expectException(PaperlessRequestException::class);
		$this->expectExceptionMessage('Paperless returned HTTP 502.');

		$this->service($client)->searchDocuments('invoice', 1, 10);
	}

	/**
	 * The exception of a test of the connection that failed.
	 */
	private function failedConnectionTest(IClient $client): PaperlessRequestException {
		try {
			$this->service($client)->testConnection('https://paperless.example.com', 'TEST_VALUE', false);
		} catch (PaperlessRequestException $exception) {
			return $exception;
		}

		self::fail('The test of the connection did not fail.');
	}

	private function service(IClient $client, string $url = 'https://paperless.example.com', ?string $token = 'TEST_VALUE', bool $allowLocal = false): PaperlessApiService {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn($url);
		$config->method('getValueBool')->willReturn($allowLocal);
		$config->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('getValueArray')->willReturnCallback(function (string $app, string $key, array $default): array {
			$value = $this->stored[$key] ?? $default;

			return is_array($value) ? $value : $default;
		});

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn($token);

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new PaperlessApiService(
			new ConfigService($config, $credentials, $this->createStub(IUserManager::class), $this->createStub(LoggerInterface::class)),
			$clientService,
			new SearchDiagnostics($config, $this->createStub(ITimeFactory::class)),
		);
	}

	private function response(int $status, ?string $body): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}
}
