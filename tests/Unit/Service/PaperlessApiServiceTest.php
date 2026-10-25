<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use DateTimeImmutable;
use JsonException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Model\TokenWarning;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class PaperlessApiServiceTest extends TestCase {
	private const NOW = 1791500000;
	private const NO_DOCUMENTS = '{"count":0,"next":null,"results":[]}';
	private const FIELDS = 'id,title,created,correspondent,document_type,__search_hit__';
	/** The headers of an answer of Paperless 3.3.0, as it names its version. */
	private const PAPERLESS_3 = ['X-Version' => '3.3.0', 'X-Api-Version' => '10'];
	private const PAPERLESS_2 = ['X-Version' => '2.20.13', 'X-Api-Version' => '9'];
	/** The keys of the version of Paperless and of its API, as the app stores them. */
	private const VERSION_KEYS = [AppConstants::APP_ID . '.paperless_version', AppConstants::APP_ID . '.paperless_api_version'];
	/**
	 * The answer of /api/ui_settings/ to the token of an account that may view documents,
	 * correspondents, document types and its UI settings, and nothing else, as
	 * Paperless-ngx 3.3.1 sends it, without the settings of the UI.
	 */
	private const VIEWER = '{"user":{"id":3,"username":"nextcloud","is_staff":false,"is_superuser":false,"groups":[]},"settings":{},"permissions":["view_document","view_correspondent","view_documenttype","view_uisettings"]}';

	/** The same of a superuser, abridged: Paperless names every permission of Django. */
	private const SUPERUSER = '{"user":{"id":1,"username":"admin","is_staff":true,"is_superuser":true,"groups":[]},"settings":{},"permissions":["add_document","change_document","delete_document","view_document","view_correspondent","view_documenttype","view_uisettings"]}';

	/** How Paperless 3.3.0 refuses the query created:[2026 to, measured. */
	private const INVALID_DATE = '{"query":["Invalid date value \'[2026\' for field \'created\'."]}';

	/** The configuration of the apps, with the settings and what the app stores. */
	private MemoryAppConfig $config;

	/** @var list<float> the seconds that the clock reads one after the other, before it stands still at NOW */
	private array $readings = [];

	protected function setUp(): void {
		$this->config = new MemoryAppConfig();
	}

	/**
	 * The connection test needs no field of the document, so Paperless sends none but its ID.
	 * Then it asks what the account of the token may do, with the same settings and a
	 * shorter time.
	 */
	public function testTheConnectionTestAsksForOneDocumentWithTheGivenSettings(): void {
		$headers = [
			'Authorization' => 'Token TEST_VALUE',
			'Accept' => 'application/json; version=9',
			'User-Agent' => 'Nextcloud-Paperless-Unified-Search/0.5.1',
		];
		$requests = [];
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->willReturnCallback(function (string $url, array $options) use (&$requests): IResponse {
				$requests[] = [$url, $options];

				return $this->response(200, count($requests) === 1 ? self::NO_DOCUMENTS : self::VIEWER);
			});

		$this->service($client, '', null, true)->probe('https://paperless.example.com/', 'TEST_VALUE', false);

		self::assertSame([
			['https://paperless.example.com/api/documents/', [
				'headers' => $headers,
				'connect_timeout' => 3,
				'timeout' => 10,
				'http_errors' => false,
				'query' => ['page' => 1, 'page_size' => 1, 'fields' => 'id'],
				'nextcloud' => ['allow_local_address' => false],
			]],
			['https://paperless.example.com/api/ui_settings/', [
				'headers' => $headers,
				'connect_timeout' => 3,
				'timeout' => 3,
				'http_errors' => false,
				'query' => [],
				'nextcloud' => ['allow_local_address' => false],
			]],
		], $requests);
	}

	/**
	 * The test of a connection allows the local network as the form says, not as the
	 * stored settings do (#117), for both of its requests.
	 */
	public function testTheConnectionTestAllowsTheLocalNetworkAsTheFormSays(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->with(self::stringStartsWith('http://paperless_ngx:8000/api/'), self::callback(
				static fn (array $options): bool => $options['nextcloud'] === ['allow_local_address' => true]
					&& $options['allow_redirects'] === false,
			))
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$this->service($client, '', null, false)->probe('http://paperless_ngx:8000', 'TEST_VALUE', true);
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
	 * @return array<string, array{Throwable}>
	 */
	public static function refusedAddresses(): array {
		$refused = new LocalServerException('Host "paperless-ngx" violates local access rules');

		return [
			'the error of Nextcloud' => [$refused],
			'the error of Nextcloud inside another error' => [new RuntimeException('The request failed.', 0, $refused)],
		];
	}

	/**
	 * Nextcloud refuses an address of the local network before any request leaves it, and
	 * would refuse it again (#117, #128).
	 */
	#[DataProvider('refusedAddresses')]
	public function testAnAddressThatNextcloudRefusesGetsNoSecondTry(Throwable $error): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willThrowException($error);

		try {
			$this->service($client)->searchDocuments('invoice', 1, 10);
			self::fail('The refused address did not fail the search.');
		} catch (Throwable $exception) {
			self::assertSame($error, $exception);
		}
		self::assertNull($this->history());
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
	 * @return array<string, array{int}>
	 */
	public static function successfulStatuses(): array {
		return [
			'OK' => [200],
			'the last status of success' => [299],
		];
	}

	#[DataProvider('successfulStatuses')]
	public function testEveryStatusOfSuccessBringsTheDocuments(int $status): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response($status, '{"count":1,"next":null,"results":[{"id":7}]}'));

		self::assertSame(['count' => 1, 'next' => null, 'results' => [['id' => 7]]], $this->service($client)->searchDocuments('invoice', 1, 10));
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
			'multiple choices' => [300, self::NO_DOCUMENTS, RequestFailure::Redirect, 300, 'Paperless returned HTTP 300.'],
			'redirect' => [302, '', RequestFailure::Redirect, 302, 'Paperless returned HTTP 302.'],
			'permanent redirect' => [301, '', RequestFailure::Redirect, 301, 'Paperless returned HTTP 301.'],
			'refused token' => [401, '{"detail":"Invalid token."}', RequestFailure::Unauthorized, 401, 'Paperless returned HTTP 401.'],
			'no permission' => [403, '{"detail":"You do not have permission to perform this action."}', RequestFailure::Unauthorized, 403, 'Paperless returned HTTP 403.'],
			'wrong path' => [404, '<html>Not found</html>', RequestFailure::NotFound, 404, 'Paperless returned HTTP 404.'],
			'an older Paperless' => [406, '', RequestFailure::UnsupportedVersion, 406, 'Paperless returned HTTP 406.'],
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

		// A test of the connection searches nothing: even an answer that refuses a query fails it.
		self::assertSame(PaperlessRequestException::class, $exception::class);
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
			// The DNS pinning of Nextcloud, on by default, reports a name without a record
			// as a LocalServerException when the request doesn't allow local addresses.
			'a host without a record of DNS, found by Nextcloud' => [new LocalServerException('No DNS record found for paperless.invalid'), RequestFailure::UnknownHost],
			'a host without a record of DNS, inside another error' => [
				new RuntimeException('The request failed.', 0, new LocalServerException('No DNS record found for paperless.invalid')),
				RequestFailure::UnknownHost,
			],
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

	/**
	 * @return array<string, array{Throwable}>
	 */
	public static function namesThatDidNotResolve(): array {
		$unknown = new LocalServerException('No DNS record found for paperless.example.com');

		return [
			'the error of Nextcloud' => [$unknown],
			'the error of Nextcloud inside another error' => [new RuntimeException('The request failed.', 0, $unknown)],
		];
	}

	/**
	 * With dns_pinning on, as by default, Nextcloud's own lookup reports a name that doesn't
	 * resolve with the exception of a local address. That one is no refused address, and a
	 * lookup that failed for a moment gets its second try as with cURL's error 6 (#133).
	 */
	#[DataProvider('namesThatDidNotResolve')]
	public function testANameThatNextcloudCouldNotResolveGetsASecondTry(Throwable $error): void {
		$answer = $this->response(200, '{"count":1,"next":null,"results":[{"id":7}]}');
		$calls = 0;
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->willReturnCallback(static function () use (&$calls, $answer, $error): IResponse {
				if (++$calls === 1) {
					throw $error;
				}

				return $answer;
			});

		self::assertSame(
			['count' => 1, 'next' => null, 'results' => [['id' => 7]]],
			$this->service($client)->searchDocuments('invoice', 1, 10),
		);
		self::assertSame(1, $this->history()['retried'] ?? null);
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
		self::assertSame(1, $this->history()['retried'] ?? null);
		self::assertSame(
			'cURL error 28: Connection timed out after 3001 milliseconds for https://paperless.example.com/api/documents/',
			$this->history()['events'][0]['message'] ?? null,
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
		self::assertNull($this->history());
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
	 * A request that had a connection and then waited for Paperless in vain, as for a
	 * Paperless that hangs while it consumes documents, gets no second try: it would make
	 * the user wait twice as long and load the struggling Paperless twice (#128).
	 */
	public function testARequestThatFailsAfterTheTimeToConnectGetsNoSecondTry(): void {
		$this->readings = [self::NOW, self::NOW + 3.5];
		$error = new RuntimeException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received');
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('get')->willThrowException($error);

		try {
			$this->service($client)->searchDocuments('invoice', 1, 10);
			self::fail('The request that timed out did not fail the search.');
		} catch (RuntimeException $exception) {
			self::assertSame($error, $exception);
		}
		self::assertNull($this->history());
	}

	/**
	 * @return array<string, array{float}>
	 */
	public static function failuresWithinTheTimeToConnect(): array {
		return [
			'3498.7 milliseconds' => [3.4987],
			'3499.2 milliseconds' => [3.4992],
		];
	}

	/**
	 * A request that fails within the time to connect, and half a second to spare, never
	 * reached Paperless, and gets its second try (#128). Its duration counts to the
	 * nearest millisecond, as the history notes it.
	 */
	#[DataProvider('failuresWithinTheTimeToConnect')]
	public function testARequestThatFailsWithinTheTimeToConnectGetsASecondTry(float $seconds): void {
		$this->readings = [self::NOW, self::NOW + $seconds];
		$calls = 0;
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->willReturnCallback(function () use (&$calls): IResponse {
				if (++$calls === 1) {
					throw new RuntimeException('cURL error 28: Connection timed out after 3001 milliseconds');
				}

				return $this->response(200, self::NO_DOCUMENTS);
			});

		$this->service($client)->searchDocuments('invoice', 1, 10);

		self::assertSame(1, $this->history()['retried'] ?? null);
		self::assertSame(3499, $this->history()['events'][0]['durationMs'] ?? null);
	}

	/**
	 * The history keeps neither the token nor the term of a request that only its second
	 * try answered, in whatever case the error repeats them (#128).
	 */
	public function testARetryLeavesOutTheTokenAndTheTerm(): void {
		$calls = 0;
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function () use (&$calls): IResponse {
			if (++$calls === 1) {
				throw new RuntimeException('Token TEST_VALUE refused for Invoice at host');
			}

			return $this->response(200, self::NO_DOCUMENTS);
		});

		$this->service($client)->searchDocuments('invoice', 1, 10);

		self::assertSame('Token … refused for … at host', $this->history()['events'][0]['message'] ?? null);
	}

	/**
	 * The User-Agent names the version of the app, which Nextcloud is asked for once.
	 */
	public function testTheRequestsNameTheVersionOfTheApp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects(self::once())
			->method('getAppVersion')
			->with(AppConstants::APP_ID)
			->willReturn('0.6.0');
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->with(self::anything(), self::callback(
				static fn (array $options): bool => $options['headers']['User-Agent'] === 'Nextcloud-Paperless-Unified-Search/0.6.0',
			))
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$service = $this->service($client, appManager: $appManager);
		$service->searchDocuments('invoice', 1, 10);
		$service->searchDocuments('invoice', 2, 10);
	}

	/**
	 * Paperless 3 refuses a query of its advanced syntax that it can't parse, such as the
	 * range of dates that a user is still typing; its simple mode gets the plain words of
	 * the term and finds their documents. Neither answer is a problem of the search (#128).
	 */
	public function testAnUnfinishedQueryOfPaperless3GoesToItsSimpleMode(): void {
		$queries = [];
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$queries): IResponse {
			$queries[] = $options['query'];

			return isset($options['query']['query'])
				? $this->response(400, self::INVALID_DATE, self::PAPERLESS_3)
				: $this->response(200, '{"count":1,"next":null,"results":[{"id":7}]}', self::PAPERLESS_3);
		});

		self::assertSame(
			['count' => 1, 'next' => null, 'results' => [['id' => 7]]],
			$this->service($client)->searchDocuments('invoice created:[2026 to', 2, 25),
		);
		self::assertSame([
			['query' => 'invoice created:[2026 to', 'page' => 2, 'page_size' => 25, 'fields' => self::FIELDS],
			['text' => 'invoice', 'page' => 2, 'page_size' => 25, 'fields' => self::FIELDS],
		], $queries);
		self::assertNull($this->history());
	}

	/**
	 * The filters of a search, such as the dates of Nextcloud's date filter, narrow the
	 * documents of the advanced syntax and of the simple mode alike.
	 */
	public function testTheFiltersNarrowTheDocumentsInEitherModeOfTheSearch(): void {
		$queries = [];
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$queries): IResponse {
			$queries[] = $options['query'];

			return isset($options['query']['query'])
				? $this->response(400, self::INVALID_DATE, self::PAPERLESS_3)
				: $this->response(200, self::NO_DOCUMENTS, self::PAPERLESS_3);
		});
		$filters = [PaperlessApiService::CREATED_FROM => '2026-08-01', PaperlessApiService::CREATED_UNTIL => '2026-08-31'];

		$this->service($client)->searchDocuments('invoice created:[2026 to', 3, 25, $filters);

		self::assertSame([
			['query' => 'invoice created:[2026 to', 'page' => 3, 'page_size' => 25, 'fields' => self::FIELDS, 'created__date__gte' => '2026-08-01', 'created__date__lte' => '2026-08-31'],
			['text' => 'invoice', 'page' => 3, 'page_size' => 25, 'fields' => self::FIELDS, 'created__date__gte' => '2026-08-01', 'created__date__lte' => '2026-08-31'],
		], $queries);
	}

	/**
	 * A filter can neither replace the term nor the page, the size of a page or the fields,
	 * which keep the answer small.
	 */
	public function testAFilterReplacesNoParameterOfTheSearch(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('https://paperless.example.com/api/documents/', self::callback(static fn (array $options): bool => $options['query'] === [
				'query' => 'invoice',
				'page' => 2,
				'page_size' => 25,
				'fields' => self::FIELDS,
				'created__date__gte' => '2026-08-01',
			]))
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$this->service($client)->searchDocuments('invoice', 2, 25, [
			'query' => 'everything',
			'page' => '1',
			'page_size' => '50',
			'fields' => 'content',
			'created__date__gte' => '2026-08-01',
		]);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function wordsOfQueries(): array {
		return [
			'a range still being typed' => ['invoice created:[2026 to', 'invoice'],
			'a finished range between words' => ['tax created:[2025 to 2026] receipt', 'tax receipt'],
			'a range in braces' => ['bill added:{2026-01-01 TO', 'bill'],
			'a field and its value' => ['tag:bills  insurance', 'insurance'],
			'a field and a quote still being typed' => ['contract title:"car', 'contract'],
			'operators, brackets and quotes' => ['(invoice OR "receipt") AND NOT draft', 'invoice receipt draft'],
			'words with umlauts' => ['Bürgschaft Rechnung created:[2026', 'Bürgschaft Rechnung'],
		];
	}

	/**
	 * The simple mode of Paperless 3 looks for every word that it gets, so a field that is
	 * still being typed, and every other part of the advanced syntax, would match no
	 * document: it gets only the plain words of the term.
	 */
	#[DataProvider('wordsOfQueries')]
	public function testTheSimpleModeGetsThePlainWordsOfTheTerm(string $term, string $words): void {
		$texts = [];
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$texts): IResponse {
			if (isset($options['query']['query'])) {
				return $this->response(400, self::INVALID_DATE, self::PAPERLESS_3);
			}
			$texts[] = $options['query']['text'];

			return $this->response(200, self::NO_DOCUMENTS, self::PAPERLESS_3);
		});

		$this->service($client)->searchDocuments($term, 1, 5);

		self::assertSame([$words], $texts);
	}

	/**
	 * A term that is nothing but a field still being typed has no words for the simple
	 * mode, which would find nothing: the refusal of Paperless stands, which is no problem
	 * of the search either.
	 */
	public function testATermWithoutPlainWordsGoesToNoOtherMode(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willReturn($this->response(400, self::INVALID_DATE, self::PAPERLESS_3));

		$rejection = $this->rejectedSearch($client, 'created:[2026 to');

		self::assertSame(400, $rejection->status);
		self::assertTrue($rejection->allowsSimpleSearch());
		self::assertNull($this->history());
	}

	/**
	 * Every request names version 9 of the API, the one that Paperless 2.16 and newer and
	 * Paperless 3 all accept, so that a new default version of Paperless can't change the
	 * fields that the app reads; the contract tests in tests/e2e/paperless-contract.sh
	 * check it against real Paperless servers. The advanced query and its simple mode both.
	 */
	public function testEveryRequestAsksForVersion9OfTheApi(): void {
		$accepted = [];
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$accepted): IResponse {
			$accepted[] = $options['headers']['Accept'];

			return isset($options['query']['query'])
				? $this->response(400, self::INVALID_DATE, self::PAPERLESS_3)
				: $this->response(200, self::NO_DOCUMENTS, self::PAPERLESS_3);
		});

		$this->service($client)->searchDocuments('invoice created:[2026 to', 1, 5);

		self::assertSame(['application/json; version=9', 'application/json; version=9'], $accepted);
	}

	/**
	 * The second try of a request of the simple mode leaves out the term as well.
	 */
	public function testARetryOfTheSimpleModeLeavesOutTheTerm(): void {
		$calls = 0;
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function () use (&$calls): IResponse {
			return match (++$calls) {
				1 => $this->response(400, self::INVALID_DATE, self::PAPERLESS_3),
				2 => throw new RuntimeException('cURL error 7: Failed to connect for invoice'),
				default => $this->response(200, self::NO_DOCUMENTS, self::PAPERLESS_3),
			};
		});

		$this->service($client)->searchDocuments('invoice created:[2026 to', 1, 25);

		self::assertSame('cURL error 7: Failed to connect for …', $this->history()['events'][0]['message'] ?? null);
	}

	/**
	 * @return array<string, array{array<string, string>}>
	 */
	public static function versionsWithoutSimpleSearch(): array {
		return [
			// Paperless 2 ignores the parameter text and would list all documents.
			'Paperless 2' => [self::PAPERLESS_2],
			'a Paperless that names no version' => [[]],
		];
	}

	/**
	 * @param array<string, string> $headers
	 */
	#[DataProvider('versionsWithoutSimpleSearch')]
	public function testOnlyPaperless3GetsTheTermInItsSimpleMode(array $headers): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willReturn($this->response(400, self::INVALID_DATE, $headers));

		$rejection = $this->rejectedSearch($client, 'created:[2026 to');

		self::assertSame(400, $rejection->status);
		self::assertSame(RequestFailure::Http, $rejection->reason);
		self::assertSame('Paperless rejected the query with HTTP 400.', $rejection->getMessage());
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function termLengths(): array {
		return [
			'the longest term of Paperless' => [4096, 2],
			'a longer one, which its simple mode refuses as well' => [4097, 1],
		];
	}

	/**
	 * Paperless 3 searches at most 4096 characters in any mode; a longer term goes to no
	 * other mode, and the refusal of the simple mode is no problem either.
	 */
	#[DataProvider('termLengths')]
	public function testATermTooLongForPaperlessGoesToNoOtherMode(int $length, int $requests): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly($requests))
			->method('get')
			->willReturn($this->response(400, '{"query":["The search query is too long (' . $length . ' characters). The maximum allowed length is 4096 characters."]}', self::PAPERLESS_3));

		// Characters, not bytes: ä takes two.
		self::assertSame(400, $this->rejectedSearch($client, str_repeat('ä', $length))->status);
	}

	/**
	 * Paperless refuses a page past the last one, as when documents go away between two
	 * pages of a search; the search finds nothing more (#128).
	 */
	public function testAPagePastTheLastOneIsRejected(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willReturn($this->response(404, '{"detail":"Invalid page."}', self::PAPERLESS_3));

		$rejection = $this->rejectedSearch($client, 'invoice', 3);

		self::assertSame(404, $rejection->status);
		self::assertSame(RequestFailure::NotFound, $rejection->reason);
		self::assertFalse($rejection->allowsSimpleSearch());
	}

	/**
	 * @return array<string, array{int, int, ?string}>
	 */
	public static function failuresOfASearch(): array {
		return [
			'a first page that is not found, at a wrong URL' => [404, 1, '{"detail":"Not found."}'],
			'HTTP 400 of a proxy' => [400, 1, '<html><title>400 The plain HTTP request was sent to HTTPS port</title></html>'],
			'HTTP 400 for another parameter' => [400, 1, '{"detail":"Specify only one of text, title_search, query, or more_like_id."}'],
			'HTTP 400 with a list' => [400, 1, '["query"]'],
			'HTTP 400 without a body' => [400, 1, null],
			'a refused token' => [401, 2, '{"detail":"Invalid token."}'],
			'a server error' => [500, 2, ''],
		];
	}

	/**
	 * Every other answer that isn't 2xx still fails the search, and the history shows it,
	 * also HTTP 400 and 404 that don't refuse what the user typed (#128).
	 */
	#[DataProvider('failuresOfASearch')]
	public function testOtherAnswersStillFailTheSearch(int $status, int $page, ?string $body): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willReturn($this->response($status, $body, self::PAPERLESS_3));

		try {
			$this->service($client)->searchDocuments('invoice', $page, 25);
			self::fail('The answer did not fail the search.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame(PaperlessRequestException::class, $exception::class);
			self::assertSame($status, $exception->status);
		}
	}

	/**
	 * The test of the connection returns the version that Paperless names, which saving
	 * stores for the settings.
	 */
	public function testTheConnectionTestReturnsTheVersionOfPaperless(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, self::NO_DOCUMENTS, self::PAPERLESS_3));

		$version = $this->service($client)->probe('https://paperless.example.com', 'TEST_VALUE', false)->version;

		self::assertNotNull($version);
		self::assertSame('3.3.0', $version->version);
		self::assertSame('10', $version->apiVersion);
	}

	public function testAConnectionTestWithoutAVersionReturnsNone(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, self::NO_DOCUMENTS));

		self::assertNull($this->service($client)->probe('https://paperless.example.com', 'TEST_VALUE', false)->version);
	}

	/**
	 * @return array<string, array{string, int, ?list<int>}>
	 */
	public static function probes(): array {
		return [
			'the IDs of all documents' => ['{"count":3,"next":null,"all":[3,2,1],"results":[{"id":3}]}', 3, [3, 2, 1]],
			'no documents' => ['{"count":0,"next":null,"all":[],"results":[]}', 0, []],
			'no field all' => ['{"count":1180,"next":null,"results":[{"id":3}]}', 1180, null],
			'no list in all' => ['{"count":3,"all":"3,2,1","results":[{"id":3}]}', 3, null],
			'an ID that is no number' => ['{"count":2,"all":[2,"1"],"results":[{"id":2}]}', 2, null],
			'no count' => ['{"results":[{"id":3}]}', 1, null],
		];
	}

	/**
	 * The test of the connection also says how many documents the account of the token may
	 * view, and which, when Paperless lists their IDs in the field all, as Paperless-ngx
	 * does for the selection of all documents.
	 *
	 * @param ?list<int> $documentIds
	 */
	#[DataProvider('probes')]
	public function testTheProbeCountsTheDocumentsOfPaperless(string $body, int $documents, ?array $documentIds): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, $body, self::PAPERLESS_3));

		$status = $this->service($client)->probe('https://paperless.example.com', 'TEST_VALUE', false);

		self::assertSame('3.3.0', $status->version?->version);
		self::assertSame($documents, $status->documents);
		self::assertSame($documentIds, $status->documentIds);
	}

	/**
	 * occ tests the stored settings, and the overview of the administration settings then
	 * shows the version of the answer and the warnings about the account of the token.
	 */
	public function testProbingTheSettingsUsesThemAndRemembersTheVersionAndTheWarnings(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))
			->method('get')
			->with(
				self::stringStartsWith('http://paperless:8000/api/'),
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token TEST_VALUE'
					&& $options['nextcloud'] === ['allow_local_address' => true]),
			)
			->willReturnCallback(fn (string $url, array $options): IResponse => $url === 'http://paperless:8000/api/documents/'
				&& $options['query'] === ['page' => 1, 'page_size' => 1, 'fields' => 'id']
				? $this->response(200, self::NO_DOCUMENTS, self::PAPERLESS_3)
				: $this->response(200, self::SUPERUSER, self::PAPERLESS_3));

		$status = $this->service($client, 'http://paperless:8000', 'TEST_VALUE', true)->probeTheSettings();

		self::assertSame(0, $status->documents);
		self::assertSame([TokenWarning::Superuser], $status->tokenWarnings);
		self::assertSame([...self::VERSION_KEYS, AppConstants::APP_ID . '.token_warnings'], $this->config->calls('setValueString'));
		self::assertSame('3.3.0', $this->config->stored(AppConstants::APP_ID, 'paperless_version'));
		self::assertSame('superuser', $this->config->stored(AppConstants::APP_ID, 'token_warnings'));
	}

	/**
	 * A test of the stored settings after which Paperless doesn't say what the account of
	 * the token may do keeps the warnings that an earlier test found, and one that finds
	 * none forgets them.
	 */
	public function testProbingTheSettingsKeepsTheWarningsWhenPaperlessDoesNotSay(): void {
		$privileges = $this->response(403, '{"detail":"You do not have permission to perform this action."}');
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use (&$privileges): IResponse {
			return str_ends_with($url, '/api/documents/') ? $this->response(200, self::NO_DOCUMENTS) : $privileges;
		});
		$this->config->seed(AppConstants::APP_ID, 'token_warnings', 'staff', true);
		$service = $this->service($client);

		self::assertNull($service->probeTheSettings()->tokenWarnings);
		self::assertSame('staff', $this->config->stored(AppConstants::APP_ID, 'token_warnings'));

		$privileges = $this->response(200, self::VIEWER);
		self::assertSame([], $service->probeTheSettings()->tokenWarnings);
		self::assertNull($this->config->stored(AppConstants::APP_ID, 'token_warnings'));
	}

	/**
	 * @return array<string, array{int, ?string, ?list<TokenWarning>}>
	 */
	public static function privileges(): array {
		return [
			'an account that may only view' => [200, self::VIEWER, []],
			'a superuser' => [200, self::SUPERUSER, [TokenWarning::Superuser]],
			// Paperless answers it only to an account that may view its UI settings.
			'an account that may not view its UI settings' => [403, '{"detail":"You do not have permission to perform this action."}', null],
			'a Paperless without the list, or a proxy that hides it' => [404, '{"detail":"Not found."}', null],
			'a refusal with the answer of a superuser' => [500, self::SUPERUSER, null],
			'another answer of success' => [204, self::SUPERUSER, null],
			'a page of HTML' => [200, '<!doctype html><title>Sign in</title>', null],
			'an answer without the permissions' => [200, '{"user":{"is_superuser":true}}', null],
			'no answer at all' => [200, null, null],
		];
	}

	/**
	 * After Paperless has answered the test of the connection, the test asks what the
	 * account of the token may do; whatever answer doesn't say leaves it unknown, and the
	 * test passes either way.
	 *
	 * @param ?list<TokenWarning> $warnings
	 */
	#[DataProvider('privileges')]
	public function testTheProbeNamesWhatTheAccountOfTheTokenMayDo(int $status, ?string $body, ?array $warnings): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $url): IResponse => str_ends_with($url, '/api/documents/')
			? $this->response(200, self::NO_DOCUMENTS, self::PAPERLESS_3)
			: $this->response($status, $body, self::PAPERLESS_3));

		$probed = $this->service($client)->probe('https://paperless.example.com', 'TEST_VALUE', false);

		self::assertSame('3.3.0', $probed->version?->version);
		self::assertSame($warnings, $probed->tokenWarnings);
	}

	/**
	 * A request for the privileges that gets no answer, such as one that times out, gets no
	 * second try and leaves them unknown, and the test of the connection still passes.
	 */
	public function testAQuestionForThePrivilegesWithoutAnswerLeavesThemUnknown(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::exactly(2))->method('get')->willReturnCallback(function (string $url): IResponse {
			if (str_ends_with($url, '/api/ui_settings/')) {
				throw new RuntimeException('cURL error 28: Operation timed out after 3001 milliseconds');
			}

			return $this->response(200, self::NO_DOCUMENTS);
		});

		self::assertNull($this->service($client)->probe('https://paperless.example.com', 'TEST_VALUE', false)->tokenWarnings);
	}

	/**
	 * A body that the client of Nextcloud hands over as a stream, which it doesn't for
	 * these requests, says nothing either.
	 */
	public function testAnAnswerWithoutATextLeavesThePrivilegesUnknown(): void {
		$stream = fopen('php://memory', 'rb');
		$privileges = $this->createStub(IResponse::class);
		$privileges->method('getStatusCode')->willReturn(200);
		$privileges->method('getBody')->willReturn($stream);
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $url): IResponse => str_ends_with($url, '/api/documents/')
			? $this->response(200, self::NO_DOCUMENTS)
			: $privileges);

		self::assertNull($this->service($client)->probe('https://paperless.example.com', 'TEST_VALUE', false)->tokenWarnings);
	}

	/**
	 * A test of the connection that fails asks nothing more.
	 */
	public function testAFailedTestOfTheConnectionAsksForNoPrivileges(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('get')->willReturn($this->response(401, '{"detail":"Invalid token."}'));

		self::assertSame(RequestFailure::Unauthorized, $this->failedConnectionTest($client)->reason);
	}

	/**
	 * A search stores the version of Paperless only when it changes, so that searches that
	 * work write nothing; an answer without a version keeps the stored one.
	 */
	public function testASearchRemembersTheVersionOfPaperlessWhenItChanges(): void {
		$headers = self::PAPERLESS_2;
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function () use (&$headers): IResponse {
			return $this->response(200, self::NO_DOCUMENTS, $headers);
		});
		$service = $this->service($client);

		$service->searchDocuments('invoice', 1, 10);
		$service->searchDocuments('invoice', 1, 10);
		self::assertSame(self::VERSION_KEYS, $this->config->calls('setValueString'));
		self::assertSame('2.20.13', $this->config->stored(AppConstants::APP_ID, 'paperless_version'));

		$headers = self::PAPERLESS_3;
		$service->searchDocuments('invoice', 1, 10);
		$headers = [];
		$service->searchDocuments('invoice', 1, 10);

		self::assertSame([...self::VERSION_KEYS, ...self::VERSION_KEYS], $this->config->calls('setValueString'));
		self::assertSame('3.3.0', $this->config->stored(AppConstants::APP_ID, 'paperless_version'));
		self::assertSame('10', $this->config->stored(AppConstants::APP_ID, 'paperless_api_version'));
	}

	/**
	 * The names of correspondents and types come in one request for all IDs, as
	 * Paperless-ngx 3.3.0 answered it (tests/Unit/fixtures/README.md), with every field of
	 * each object and without the ID that it doesn't have.
	 */
	public function testTheNamesOfAListComeInOneRequest(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('https://paperless.example.com/api/correspondents/', self::callback(
				static fn (array $options): bool => $options['query'] === ['id__in' => '1,2,99', 'page_size' => 3]
					&& $options['headers'] === [
						'Authorization' => 'Token TEST_VALUE',
						'Accept' => 'application/json; version=9',
						'User-Agent' => 'Nextcloud-Paperless-Unified-Search/0.5.1',
					]
					&& $options['connect_timeout'] === 3
					&& $options['timeout'] === 3
					&& $options['http_errors'] === false,
			))
			->willReturn($this->response(200, self::fixture('paperless-3.3.0-correspondents.json')));

		self::assertSame(
			[1 => 'ACME Energy', 2 => 'Harbor & Sons <Insurance>'],
			$this->service($client)->findNames('correspondents', [1, 2, 99]),
		);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	#[DataProvider('localNetwork')]
	public function testTheNamesAllowTheLocalNetworkAsTheSettingsSay(bool $allowLocal, array $options): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('https://paperless.example.com/api/document_types/', self::callback(
				static fn (array $sent): bool => array_intersect_key($sent, ['nextcloud' => true, 'allow_redirects' => true]) === $options,
			))
			->willReturn($this->response(200, self::fixture('paperless-3.3.0-document-types.json')));

		self::assertSame([1 => 'Invoice'], $this->service($client, allowLocal: $allowLocal)->findNames('document_types', [1]));
	}

	/**
	 * A URL of the settings with a slash at its end, as an administrator may type it,
	 * still names the list.
	 */
	public function testTheNamesOfAListTakeTheUrlWithASlashAtItsEnd(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('https://paperless.example.com/api/document_types/')
			->willReturn($this->response(200, self::fixture('paperless-3.3.0-document-types.json')));

		self::assertSame([1 => 'Invoice'], $this->service($client, 'https://paperless.example.com/')->findNames('document_types', [1]));
	}

	public function testOnlyObjectsWithANumberAndANameHaveANameInTheList(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response(200, json_encode(['results' => [
			['id' => '1', 'name' => 'ID as text'],
			['id' => 2],
			['name' => 'No ID'],
			'no object',
			['id' => 3, 'name' => null],
			['id' => 4, 'name' => 'Four'],
		]], JSON_THROW_ON_ERROR)));

		self::assertSame([4 => 'Four'], $this->service($client)->findNames('correspondents', [1, 2, 3, 4]));
	}

	/**
	 * @return array<string, array{list<int>, string, ?string}>
	 */
	public static function lookupsWithoutRequest(): array {
		return [
			'no IDs' => [[], 'https://paperless.example.com', 'TEST_VALUE'],
			'no URL' => [[1], '', 'TEST_VALUE'],
			'no token' => [[1], 'https://paperless.example.com', null],
		];
	}

	/**
	 * @param list<int> $ids
	 */
	#[DataProvider('lookupsWithoutRequest')]
	public function testNamesNeedIdsAndTheSettings(array $ids, string $url, ?string $token): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		self::assertSame([], $this->service($client, $url, $token)->findNames('correspondents', $ids));
	}

	/**
	 * @return array<string, array{int, ?string, RequestFailure, string}>
	 */
	public static function failedLookups(): array {
		return [
			'informational status' => [100, '{"results":[]}', RequestFailure::Http, 'Paperless returned HTTP 100.'],
			'a redirect' => [300, '{"results":[]}', RequestFailure::Redirect, 'Paperless returned HTTP 300.'],
			'no permission' => [403, '{"detail":"You do not have permission to perform this action."}', RequestFailure::Unauthorized, 'Paperless returned HTTP 403.'],
			'server error' => [500, '', RequestFailure::Http, 'Paperless returned HTTP 500.'],
			'no body' => [200, null, RequestFailure::NotPaperless, 'Paperless returned no JSON: Syntax error'],
			'no JSON' => [200, '<html>', RequestFailure::NotPaperless, 'Paperless returned no JSON: Syntax error'],
			'no object' => [200, '"ok"', RequestFailure::NotPaperless, 'Paperless returned an invalid list.'],
			'no results' => [200, '{"count":0}', RequestFailure::NotPaperless, 'Paperless returned an invalid list.'],
			'results that are no list' => [200, '{"results":"none"}', RequestFailure::NotPaperless, 'Paperless returned an invalid list.'],
		];
	}

	#[DataProvider('failedLookups')]
	public function testAWrongAnswerFailsTheLookupWithItsReason(int $status, ?string $body, RequestFailure $reason, string $message): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response($status, $body));

		try {
			$this->service($client)->findNames('correspondents', [1]);
			self::fail('The lookup did not fail.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame($reason, $exception->reason);
			self::assertSame($message, $exception->getMessage());
		}
	}

	/**
	 * Names only add to results that Paperless has just sent: a request without answer
	 * gets no second try and leaves the history of the problems of the search alone.
	 */
	public function testALookupWithoutAnswerGetsNoSecondTry(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willThrowException(new RuntimeException('cURL error 7: Failed to connect'));

		try {
			$this->service($client)->findNames('correspondents', [1]);
			self::fail('The lookup did not fail.');
		} catch (RuntimeException $error) {
			self::assertSame('cURL error 7: Failed to connect', $error->getMessage());
		}
		self::assertArrayNotHasKey('diagnostics', $this->config->storedValues(AppConstants::APP_ID));
	}

	/**
	 * The dashboard lists the documents that Paperless added last: one page of them, newest
	 * first, with the fields that it shows and the moment that Paperless added each.
	 */
	public function testTheNewestDocumentsComeInOneRequestWithTheFieldsOfTheDashboard(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with('https://paperless.example.com/api/documents/', self::callback(
				static fn (array $options): bool => $options['query'] === [
					'ordering' => '-added',
					'page' => 1,
					'page_size' => 25,
					'fields' => 'id,title,added,correspondent,document_type',
				]
					&& $options['headers'] === [
						'Authorization' => 'Token TEST_VALUE',
						'Accept' => 'application/json; version=9',
						'User-Agent' => 'Nextcloud-Paperless-Unified-Search/0.5.1',
					]
					&& $options['connect_timeout'] === 3
					&& $options['timeout'] === 5
					&& $options['http_errors'] === false,
			))
			->willReturn($this->response(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [
				['id' => 412, 'title' => 'Electricity invoice', 'added' => '2026-08-13T09:12:45.118731+02:00', 'correspondent' => 3, 'document_type' => 1],
				'no document',
				['id' => 389, 'title' => 'Home insurance renewal', 'added' => '2026-07-22T10:00:00+02:00', 'correspondent' => null, 'document_type' => null],
			]]), self::PAPERLESS_3));

		self::assertSame([
			['id' => 412, 'title' => 'Electricity invoice', 'added' => '2026-08-13T09:12:45.118731+02:00', 'correspondent' => 3, 'document_type' => 1],
			['id' => 389, 'title' => 'Home insurance renewal', 'added' => '2026-07-22T10:00:00+02:00', 'correspondent' => null, 'document_type' => null],
		], $this->service($client)->findRecentDocuments(25));
		// The answer names the version of Paperless, which the overview shows.
		self::assertSame('3.3.0', $this->config->stored(AppConstants::APP_ID, 'paperless_version'));
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function recentCounts(): array {
		return [
			'none' => [0, 1],
			'a few' => [7, 7],
			'more than Paperless sends on one page' => [500, 100],
		];
	}

	#[DataProvider('recentCounts')]
	public function testTheNewestDocumentsAreOnePageOfPaperless(int $count, int $pageSize): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(self::anything(), self::callback(static fn (array $options): bool => $options['query']['page_size'] === $pageSize))
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		self::assertSame([], $this->service($client)->findRecentDocuments($count));
	}

	/**
	 * @param array<string, mixed> $options
	 */
	#[DataProvider('localNetwork')]
	public function testTheNewestDocumentsAllowTheLocalNetworkAsTheSettingsSay(bool $allowLocal, array $options): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(self::anything(), self::callback(
				static fn (array $sent): bool => array_intersect_key($sent, ['nextcloud' => true, 'allow_redirects' => true]) === $options,
			))
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		self::assertSame([], $this->service($client, allowLocal: $allowLocal)->findRecentDocuments(25));
	}

	/**
	 * The dashboard waits for the answer: a request without answer gets no second try and
	 * leaves the history of the problems of the search alone.
	 */
	public function testTheNewestDocumentsWithoutAnswerGetNoSecondTry(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->willThrowException(new RuntimeException('cURL error 7: Failed to connect'));

		try {
			$this->service($client)->findRecentDocuments(25);
			self::fail('The request did not fail.');
		} catch (RuntimeException $error) {
			self::assertSame('cURL error 7: Failed to connect', $error->getMessage());
		}
		self::assertNull($this->history());
	}

	/**
	 * @return array<string, array{int, ?string, RequestFailure, string}>
	 */
	public static function failedRecentDocuments(): array {
		return [
			'a refused token' => [401, '{"detail":"Invalid token."}', RequestFailure::Unauthorized, 'Paperless returned HTTP 401.'],
			// Without a search, HTTP 400 and 404 are failures as well.
			'a refused request' => [400, '{"query":["Invalid."]}', RequestFailure::Http, 'Paperless returned HTTP 400.'],
			'no such page' => [404, '{"detail":"Not found."}', RequestFailure::NotFound, 'Paperless returned HTTP 404.'],
			'a page of a proxy' => [200, '<html>', RequestFailure::NotPaperless, 'Paperless returned no JSON: Syntax error'],
		];
	}

	#[DataProvider('failedRecentDocuments')]
	public function testAWrongAnswerFailsTheNewestDocumentsWithItsReason(int $status, ?string $body, RequestFailure $reason, string $message): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response($status, $body));

		try {
			$this->service($client)->findRecentDocuments(25);
			self::fail('The request did not fail.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame($reason, $exception->reason);
			self::assertSame($message, $exception->getMessage());
		}
	}

	public function testTheNewestDocumentsNeedTheSettings(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$this->expectException(UnexpectedValueException::class);
		$this->service($client, token: null)->findRecentDocuments(25);
	}

	/**
	 * The body of an answer of a real Paperless-ngx in tests/Unit/fixtures.
	 */
	private static function fixture(string $name): string {
		$body = file_get_contents(__DIR__ . '/../fixtures/' . $name);
		self::assertIsString($body);

		return $body;
	}

	/**
	 * The rejection of a search that Paperless refused.
	 */
	private function rejectedSearch(IClient $client, string $term, int $page = 1): PaperlessQueryRejected {
		try {
			$this->service($client)->searchDocuments($term, $page, 25);
		} catch (PaperlessQueryRejected $rejection) {
			return $rejection;
		}

		self::fail('Paperless did not reject the search.');
	}

	/**
	 * The exception of a test of the connection that failed.
	 */
	private function failedConnectionTest(IClient $client): PaperlessRequestException {
		try {
			$this->service($client)->probe('https://paperless.example.com', 'TEST_VALUE', false);
		} catch (PaperlessRequestException $exception) {
			return $exception;
		}

		self::fail('The test of the connection did not fail.');
	}

	private function service(
		IClient $client,
		string $url = 'https://paperless.example.com',
		?string $token = 'TEST_VALUE',
		bool $allowLocal = false,
		?IAppManager $appManager = null,
	): PaperlessApiService {
		$config = $this->config
			->seed(AppConstants::APP_ID, 'paperless_url', $url)
			->seed(AppConstants::APP_ID, 'allow_local', $allowLocal);

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn($token);

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);
		$clock->method('now')->willReturnCallback(function (): DateTimeImmutable {
			$time = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', array_shift($this->readings) ?? self::NOW));
			self::assertInstanceOf(DateTimeImmutable::class, $time);

			return $time;
		});

		if ($appManager === null) {
			$appManager = $this->createStub(IAppManager::class);
			$appManager->method('getAppVersion')->willReturn('0.5.1');
		}

		return new PaperlessApiService(
			new ConfigService($config, $credentials, $this->createStub(IUserManager::class), $this->createStub(LoggerInterface::class)),
			$clientService,
			new SearchDiagnostics($config, $clock, $this->createStub(ICacheFactory::class)),
			$clock,
			$appManager,
		);
	}

	/**
	 * The history of the diagnostics as the app stored it, or null while it stored none.
	 *
	 * @return ?array<array-key, mixed>
	 */
	private function history(): ?array {
		$history = $this->config->stored(AppConstants::APP_ID, 'diagnostics');

		return is_array($history) ? $history : null;
	}

	/**
	 * @param array<string, string> $headers by name
	 */
	private function response(int $status, ?string $body, array $headers = []): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');

		return $response;
	}
}
