<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use JsonException;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use UnexpectedValueException;

final class PaperlessApiServiceTest extends TestCase {
	private const NO_DOCUMENTS = '{"count":0,"next":null,"results":[]}';

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
				'query' => ['page' => 1, 'page_size' => 1],
			])
			->willReturn($this->response(200, self::NO_DOCUMENTS));

		$this->service($client, '', null)->testConnection('https://paperless.example.com/', 'TEST_VALUE');
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

	#[DataProvider('pages')]
	public function testSearchAsksPaperlessWithTheStoredSettingsWithinItsLimits(int $page, int $pageSize, int $expectedPage, int $expectedPageSize): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Token TEST_VALUE'
					&& $options['query'] === ['query' => 'invoice', 'page' => $expectedPage, 'page_size' => $expectedPageSize]),
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
	 * @return array<string, array{int, ?string, class-string<Throwable>, string}>
	 */
	public static function failures(): array {
		return [
			'informational status' => [100, self::NO_DOCUMENTS, UnexpectedValueException::class, 'Paperless returned HTTP 100.'],
			'redirect' => [302, '', UnexpectedValueException::class, 'Paperless returned HTTP 302.'],
			'refused token' => [401, '{"detail":"Invalid token."}', UnexpectedValueException::class, 'Paperless returned HTTP 401.'],
			'server error' => [500, '', UnexpectedValueException::class, 'Paperless returned HTTP 500.'],
			'no body' => [200, null, UnexpectedValueException::class, 'Paperless returned an invalid response body.'],
			'no JSON' => [200, '<html>', JsonException::class, 'Syntax error'],
			'no object' => [200, '"ok"', UnexpectedValueException::class, 'Paperless returned an invalid document response.'],
			'no results' => [200, '{"count":0}', UnexpectedValueException::class, 'Paperless returned an invalid document response.'],
			'results that are no list' => [200, '{"results":"none"}', UnexpectedValueException::class, 'Paperless returned an invalid document response.'],
		];
	}

	/**
	 * @param class-string<Throwable> $exception
	 */
	#[DataProvider('failures')]
	public function testAWrongAnswerOfPaperlessFails(int $status, ?string $body, string $exception, string $message): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response($status, $body));

		$this->expectException($exception);
		$this->expectExceptionMessage($message);

		$this->service($client)->testConnection('https://paperless.example.com', 'TEST_VALUE');
	}

	private function service(IClient $client, string $url = 'https://paperless.example.com', ?string $token = 'TEST_VALUE'): PaperlessApiService {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn($url);

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn($token);

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new PaperlessApiService(new ConfigService($config, $credentials), $clientService);
	}

	private function response(int $status, ?string $body): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}
}
