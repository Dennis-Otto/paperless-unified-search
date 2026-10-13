<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use Throwable;
use UnexpectedValueException;

final class PaperlessApiService {
	/**
	 * The fields of a document that a result shows, its correspondent and its type as IDs,
	 * and __search_hit__ with the excerpt. Without them, Paperless sends the whole OCR text
	 * of every document, with its notes, custom fields, permissions and versions, half a
	 * megabyte for a long document, and runs queries of its own for them.
	 */
	private const SEARCH_FIELDS = 'id,title,created,correspondent,document_type,__search_hit__';

	private IClient $client;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		IClientService $clientService,
		private SearchDiagnostics $diagnostics,
	) {
		$this->client = $clientService->newClient();
	}

	public function isConfigured(): bool {
		return $this->configService->isConfigured();
	}

	/**
	 * @param bool $allowLocal whether Nextcloud may reach this Paperless at an address of
	 *                         the local network
	 */
	public function testConnection(string $url, string $token, bool $allowLocal): void {
		// The test reads nothing of the document, so Paperless sends only its ID.
		$this->requestDocuments($url, $token, $allowLocal, [
			'page' => 1,
			'page_size' => 1,
			'fields' => 'id',
		]);
	}

	/**
	 * Search Paperless through its native full-text index.
	 *
	 * @return array{count: int, next: ?string, results: list<array<array-key, mixed>>}
	 */
	public function searchDocuments(string $query, int $page, int $pageSize): array {
		return $this->requestDocuments(
			$this->configService->getUrl(),
			$this->configService->getToken(),
			$this->configService->isLocalNetworkAllowed(),
			[
				'query' => $query,
				'page' => max(1, $page),
				'page_size' => max(1, min(50, $pageSize)),
				'fields' => self::SEARCH_FIELDS,
			],
		);
	}

	/**
	 * Nextcloud refuses to connect to an address of the local network, unless the request
	 * allows it with the option allow_local_address, or allow_local_remote_servers in
	 * config.php allows it to every request of every app. With the option, Nextcloud
	 * would also follow a redirect of Paperless to any address of the network unchecked;
	 * so a request that allows the local network follows none, and only the address of
	 * the settings gets the exception.
	 *
	 * @param array<string, int|string> $query
	 * @return array{count: int, next: ?string, results: list<array<array-key, mixed>>}
	 */
	private function requestDocuments(string $url, string $token, bool $allowLocal, array $query): array {
		if ($url === '' || $token === '') {
			throw new UnexpectedValueException('Paperless is not configured.');
		}

		$response = $this->send(
			rtrim($url, '/') . '/api/documents/',
			[
				'headers' => [
					'Authorization' => 'Token ' . $token,
					'Accept' => 'application/json',
					'User-Agent' => 'Nextcloud-Paperless-Unified-Search/0.1',
				],
				'connect_timeout' => 3,
				'timeout' => 10,
				// Every answer of Paperless reaches the check of its status below.
				'http_errors' => false,
				'query' => $query,
				'nextcloud' => ['allow_local_address' => $allowLocal],
			] + ($allowLocal ? ['allow_redirects' => false] : []),
			[$token, (string)($query['query'] ?? '')],
		);

		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300) {
			throw new UnexpectedValueException('Paperless returned HTTP ' . $statusCode . '.');
		}

		$body = $response->getBody();
		if (!is_string($body)) {
			throw new UnexpectedValueException('Paperless returned an invalid response body.');
		}

		$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
			throw new UnexpectedValueException('Paperless returned an invalid document response.');
		}

		$results = [];
		/** @psalm-suppress MixedAssignment JSON values are validated before use. */
		foreach ($data['results'] as $result) {
			if (is_array($result)) {
				$results[] = $result;
			}
		}

		return [
			'count' => isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : count($results),
			'next' => isset($data['next']) && is_string($data['next']) ? $data['next'] : null,
			'results' => $results,
		];
	}

	/**
	 * A request that gets no answer at all, because the name of the host doesn't resolve
	 * or the connection fails or times out, gets one more try: such failures often pass
	 * within a moment. An answer of Paperless, whatever its status, gets none, and neither
	 * does an address that Nextcloud refuses as one of the local network. A second try
	 * that gets an answer goes into the history of the diagnostics; one that fails as
	 * well fails the request.
	 *
	 * @param array<string, mixed> $options
	 * @param list<string> $secrets the token and the term, which the history must not show
	 */
	private function send(string $url, array $options, array $secrets): IResponse {
		$started = microtime(true);
		try {
			return $this->client->get($url, $options);
		} catch (LocalServerException $error) {
			throw $error;
		} catch (Throwable $error) {
			$durationMs = (int)round((microtime(true) - $started) * 1000.0);
			$response = $this->client->get($url, $options);
			$this->diagnostics->recordRetry($error, $durationMs, $secrets);

			return $response;
		}
	}
}
