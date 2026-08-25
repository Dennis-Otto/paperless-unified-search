<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use UnexpectedValueException;

final class PaperlessApiService {
	private IClient $client;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	public function isConfigured(): bool {
		return $this->configService->isConfigured();
	}

	public function testConnection(string $url, string $token): void {
		$this->requestDocuments($url, $token, [
			'page' => 1,
			'page_size' => 1,
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
			[
				'query' => $query,
				'page' => max(1, $page),
				'page_size' => max(1, min(50, $pageSize)),
			],
		);
	}

	/**
	 * @param array<string, int|string> $query
	 * @return array{count: int, next: ?string, results: list<array<array-key, mixed>>}
	 */
	private function requestDocuments(string $url, string $token, array $query): array {
		if ($url === '' || $token === '') {
			throw new UnexpectedValueException('Paperless is not configured.');
		}

		$response = $this->client->get(
			rtrim($url, '/') . '/api/documents/',
			[
				'headers' => [
					'Authorization' => 'Token ' . $token,
					'Accept' => 'application/json',
					'User-Agent' => 'Nextcloud-Paperless-Unified-Search/0.1',
				],
				'connect_timeout' => 3,
				'timeout' => 10,
				'query' => $query,
			],
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
}
