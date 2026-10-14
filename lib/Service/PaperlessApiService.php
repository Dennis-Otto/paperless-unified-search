<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use JsonException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\PaperlessStatus;
use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
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

	/**
	 * The version of the API of Paperless that every request asks for. Without one,
	 * Paperless answers in its default version, which a new release raises, as 3.0 did from
	 * 9 to 10, and a new version may change the type of a field, as 9 did with created.
	 * Version 9 is the newest that both Paperless 2.16 and newer and Paperless 3 accept;
	 * an older Paperless answers HTTP 406. tests/e2e/paperless-contract.sh checks the
	 * fields of this version against real Paperless servers, so a change of it is a
	 * deliberate one, with those tests.
	 */
	private const ACCEPT = 'application/json; version=9';

	/** The seconds that a request may take to connect to Paperless. */
	private const CONNECT_TIMEOUT = 3;

	/** The seconds that a request may take in all. */
	private const TIMEOUT = 10;

	/**
	 * The seconds that a request for the names of correspondents or types may take in
	 * all: Paperless has just answered the search, and a result shows without the names.
	 */
	private const NAMES_TIMEOUT = 3;

	/**
	 * A first try that failed within this many milliseconds failed before Paperless had the
	 * request: within the time to connect, with half a second to spare. One that failed
	 * later had a connection, and Paperless took too long to answer, as while it consumes
	 * documents or builds its index anew.
	 */
	private const RETRY_WITHIN_MS = self::CONNECT_TIMEOUT * 1000 + 500;

	/**
	 * The most characters of a term that Paperless 3 searches, in any of its modes; a
	 * longer one it refuses in its simple mode as well.
	 */
	private const LONGEST_TERM = 4096;

	private IClient $client;

	private ?string $userAgent = null;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		IClientService $clientService,
		private SearchDiagnostics $diagnostics,
		private ITimeFactory $timeFactory,
		private IAppManager $appManager,
	) {
		$this->client = $clientService->newClient();
	}

	public function isConfigured(): bool {
		return $this->configService->isConfigured();
	}

	/**
	 * @param bool $allowLocal whether Nextcloud may reach this Paperless at an address of
	 *                         the local network
	 * @return ?PaperlessVersion the version of Paperless, null when its answer names none
	 * @throws PaperlessRequestException whatever failed, with the reason that the settings
	 *                                   name: also when the request got no answer
	 */
	public function testConnection(string $url, string $token, bool $allowLocal): ?PaperlessVersion {
		return $this->probe($url, $token, $allowLocal)->version;
	}

	/**
	 * The test of the connection to the Paperless of the settings, as occ and its checks
	 * run it. The version that Paperless names replaces the stored one, which the overview
	 * of the administration settings shows.
	 *
	 * @throws PaperlessRequestException whatever failed, also when the request got no answer
	 */
	public function probeTheSettings(): PaperlessStatus {
		$status = $this->probe(
			$this->configService->getUrl(),
			$this->configService->getToken(),
			$this->configService->isLocalNetworkAllowed(),
		);
		$this->configService->rememberPaperlessVersion($status->version);

		return $status;
	}

	/**
	 * Asks Paperless for its first document, which tells whether it answers, its version
	 * and how many documents the account of the token may view, with their IDs in the field
	 * all, which Paperless-ngx adds to every list for the selection of all documents.
	 *
	 * @throws PaperlessRequestException whatever failed, with the reason that the settings
	 *                                   name: also when the request got no answer
	 */
	public function probe(string $url, string $token, bool $allowLocal): PaperlessStatus {
		try {
			// The test reads nothing of the document, so Paperless sends only its ID.
			[$data, $version] = $this->requestDocuments($url, $token, $allowLocal, [
				'page' => 1,
				'page_size' => 1,
				'fields' => 'id',
			]);

			return new PaperlessStatus($version, self::documentsOf($data)['count'], self::idsOf($data['all'] ?? null));
		} catch (PaperlessRequestException $exception) {
			throw $exception;
		} catch (Throwable $error) {
			throw PaperlessRequestException::unanswered($error);
		}
	}

	/**
	 * Search Paperless through its native full-text index. A query in the advanced syntax
	 * of Paperless 3 that it can't parse, as one that is still being typed, goes to its
	 * simple mode instead, which takes any input, with only its plain words: the simple
	 * mode looks for every word it gets, and the field still being typed would match no
	 * document. Paperless 2 never gets it: it ignores the parameter and would list all
	 * documents.
	 *
	 * @return array{count: int, next: ?string, results: list<array<array-key, mixed>>}
	 * @throws PaperlessQueryRejected when Paperless refuses the term or the page, which is
	 *                                no problem of the search
	 * @throws PaperlessRequestException when Paperless answers, but not with documents
	 * @throws Throwable the error of a request without answer, as the history shows it
	 */
	public function searchDocuments(string $term, int $page, int $pageSize): array {
		$parameters = [
			'page' => max(1, $page),
			'page_size' => max(1, min(50, $pageSize)),
			'fields' => self::SEARCH_FIELDS,
		];
		try {
			[$documents, $version] = $this->search(['query' => $term] + $parameters, $term);
		} catch (PaperlessQueryRejected $rejection) {
			$words = self::wordsOf($term);
			if (!$rejection->allowsSimpleSearch() || $words === '' || mb_strlen($words) > self::LONGEST_TERM) {
				throw $rejection;
			}

			[$documents, $version] = $this->search(['text' => $words] + $parameters, $words);
		}
		$this->configService->rememberPaperlessVersion($version);

		return $documents;
	}

	/**
	 * The plain words of a query in the advanced syntax, for the simple mode: without its
	 * fields, finished or not, such as created:[2026 to or tag:bills, without brackets and
	 * quotes, and without the operators AND, OR and NOT.
	 */
	private static function wordsOf(string $term): string {
		$words = preg_replace(
			['/\S+:\s*[\[{][^\]}]*(?:[\]}]|$)/u', '/\S+:\S*/u', '/[\[\]{}()"]/u', '/(?<!\S)(?:AND|OR|NOT)(?!\S)/u', '/\s+/u'],
			[' ', ' ', ' ', ' ', ' '],
			$term,
		);

		return trim($words ?? '');
	}

	/**
	 * @param array<string, int|string> $query
	 * @param string $term the term of the query, which the history must not show
	 * @return array{array{count: int, next: ?string, results: list<array<array-key, mixed>>}, ?PaperlessVersion}
	 */
	private function search(array $query, string $term): array {
		[$data, $version] = $this->requestDocuments(
			$this->configService->getUrl(),
			$this->configService->getToken(),
			$this->configService->isLocalNetworkAllowed(),
			$query,
			$term,
		);

		return [self::documentsOf($data), $version];
	}

	/**
	 * Nextcloud refuses to connect to an address of the local network, unless the request
	 * allows it with the option allow_local_address, or allow_local_remote_servers in
	 * config.php allows it to every request of every app. With the option, Nextcloud
	 * would also follow a redirect of Paperless to any address of the network unchecked;
	 * so a request that allows the local network follows none, and only the address of
	 * the settings gets the exception. Returns the answer, a list of documents, and the
	 * version of Paperless that it names.
	 *
	 * @param array<string, int|string> $query
	 * @param string $term the term of a search, which the history must not show
	 * @return array{array{results: array<array-key, mixed>, ...<array-key, mixed>}, ?PaperlessVersion}
	 * @throws PaperlessRequestException when Paperless answers, but not with documents
	 */
	private function requestDocuments(string $url, string $token, bool $allowLocal, array $query, string $term = ''): array {
		if ($url === '' || $token === '') {
			throw new UnexpectedValueException('Paperless is not configured.');
		}

		$response = $this->send(
			rtrim($url, '/') . '/api/documents/',
			$this->options($token, $allowLocal, $query, self::TIMEOUT),
			[$token, $term],
		);

		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300) {
			throw self::failureOf($response, $query);
		}

		$body = $response->getBody();
		if (!is_string($body)) {
			throw PaperlessRequestException::notPaperless('Paperless returned an invalid response body.');
		}

		try {
			$data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			// A page of HTML, such as the login page of a proxy or another service.
			throw PaperlessRequestException::notPaperless('Paperless returned no JSON: ' . $error->getMessage(), $error);
		}
		if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
			throw PaperlessRequestException::notPaperless('Paperless returned an invalid document response.');
		}

		return [$data, PaperlessVersion::fromResponse($response)];
	}

	/**
	 * The documents of an answer of Paperless: their number, the link of the next page and
	 * the documents of this page, without anything else that the list may hold.
	 *
	 * @param array{results: array<array-key, mixed>, ...<array-key, mixed>} $data
	 * @return array{count: int, next: ?string, results: list<array<array-key, mixed>>}
	 */
	private static function documentsOf(array $data): array {
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
	 * The names of the objects with these IDs in a list of Paperless, such as its
	 * correspondents, in one request. The names only add to results that Paperless has
	 * just sent, so the request gets one try, a shorter time and no place in the history
	 * of the problems of the search. An object that the account of the token may not
	 * view, or that no longer exists, has no name.
	 *
	 * @param string $list the path of the list in the API of Paperless: correspondents or document_types
	 * @param list<int> $ids
	 * @return array<int, string> the names, by ID
	 * @throws PaperlessRequestException when Paperless answers, but not with a list
	 * @throws Throwable the error of a request without answer
	 */
	public function findNames(string $list, array $ids): array {
		$url = $this->configService->getUrl();
		$token = $this->configService->getToken();
		if ($ids === [] || $url === '' || $token === '') {
			return [];
		}

		// Paperless-ngx 2 and 3 filter their lists by id__in; they send every field of an
		// object, also when asked for fewer, which for a correspondent is a few hundred bytes.
		$response = $this->client->get(
			rtrim($url, '/') . '/api/' . $list . '/',
			$this->options($token, $this->configService->isLocalNetworkAllowed(), [
				'id__in' => implode(',', $ids),
				'page_size' => count($ids),
			], self::NAMES_TIMEOUT),
		);
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw PaperlessRequestException::forStatus($status);
		}

		$body = $response->getBody();
		try {
			$data = json_decode(is_string($body) ? $body : '', true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw PaperlessRequestException::notPaperless('Paperless returned no JSON: ' . $error->getMessage(), $error);
		}
		if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
			throw PaperlessRequestException::notPaperless('Paperless returned an invalid list.');
		}

		$names = [];
		/** @psalm-suppress MixedAssignment JSON values are validated before use. */
		foreach ($data['results'] as $object) {
			$id = is_array($object) ? ($object['id'] ?? null) : null;
			$name = is_array($object) ? ($object['name'] ?? null) : null;
			if (is_int($id) && is_string($name)) {
				$names[$id] = $name;
			}
		}

		return $names;
	}

	/**
	 * The options of a request to Paperless. Every answer of Paperless reaches the check
	 * of its status, whatever the status is.
	 *
	 * @param array<string, int|string> $query
	 * @return array<string, mixed>
	 */
	private function options(string $token, bool $allowLocal, array $query, int $timeout): array {
		return [
			'headers' => [
				'Authorization' => 'Token ' . $token,
				'Accept' => self::ACCEPT,
				'User-Agent' => $this->getUserAgent(),
			],
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'timeout' => $timeout,
			'http_errors' => false,
			'query' => $query,
			'nextcloud' => ['allow_local_address' => $allowLocal],
		] + ($allowLocal ? ['allow_redirects' => false] : []);
	}

	/**
	 * The IDs of the field all of an answer, or null when it holds no list of IDs.
	 *
	 * @return ?list<int>
	 */
	private static function idsOf(mixed $all): ?array {
		if (!is_array($all)) {
			return null;
		}

		$ids = [];
		/** @psalm-suppress MixedAssignment Every ID is checked before use. */
		foreach ($all as $id) {
			if (!is_int($id)) {
				return null;
			}
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * The failure of an answer whose status isn't 2xx. Of a search, Paperless refuses a
	 * query that it can't parse with HTTP 400 and the errors of the field query, and a
	 * page past the last one with HTTP 404, as when the hits of a search change between
	 * two of its pages. Both come from what the user typed: the search finds nothing.
	 * Any other answer is a failure, also HTTP 400 for another reason, such as the page of
	 * a proxy, and HTTP 404 for the first page, which a wrong URL answers.
	 *
	 * @param array<string, int|string> $query
	 */
	private static function failureOf(IResponse $response, array $query): PaperlessRequestException {
		$status = $response->getStatusCode();
		$searches = isset($query['query']) || isset($query['text']);
		if ($searches && $status === 400 && self::refusesTheQuery($response)) {
			return new PaperlessQueryRejected($status, PaperlessVersion::fromResponse($response));
		}

		$page = $query['page'] ?? 1;
		if ($searches && $status === 404 && $page > 1) {
			return new PaperlessQueryRejected($status);
		}

		return PaperlessRequestException::forStatus($status);
	}

	/**
	 * Whether an answer of HTTP 400 holds the errors of the field query, as Django REST
	 * Framework writes them for Paperless: {"query": ["Invalid date value …"]}.
	 */
	private static function refusesTheQuery(IResponse $response): bool {
		$body = $response->getBody();
		try {
			/** @psalm-suppress MixedAssignment Only its shape counts, which is checked below. */
			$data = is_string($body) ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : null;
		} catch (JsonException) {
			return false;
		}

		return is_array($data) && array_key_exists('query', $data);
	}

	/**
	 * The User-Agent of the requests, with the version of the app, so that the logs of
	 * Paperless and of a proxy tell the app and its version apart.
	 */
	private function getUserAgent(): string {
		return $this->userAgent ??= 'Nextcloud-Paperless-Unified-Search/' . $this->appManager->getAppVersion(AppConstants::APP_ID);
	}

	/**
	 * A request that gets no answer before a connection exists, because the name of the
	 * host doesn't resolve, also when Nextcloud's own lookup reports it, or the connection
	 * fails or isn't set up in time, gets one more try: such failures often pass within a
	 * moment. None gets an answer of Paperless, whatever its status; a request that failed
	 * only after the time to connect, which had a connection and timed out waiting for
	 * Paperless, as a Paperless that hangs does, since a second try would make the user
	 * wait twice and load Paperless twice; and an address that Nextcloud refuses as one of
	 * the local network, which it would refuse again. A second try that gets an answer goes
	 * into the history of the diagnostics; one that fails as well fails the request.
	 *
	 * @param array<string, mixed> $options
	 * @param list<string> $secrets the token and the term, which the history must not show
	 */
	private function send(string $url, array $options, array $secrets): IResponse {
		$started = $this->getSeconds();
		try {
			return $this->client->get($url, $options);
		} catch (Throwable $error) {
			$durationMs = (int)round(($this->getSeconds() - $started) * 1000.0);
			if ($durationMs >= self::RETRY_WITHIN_MS || PaperlessRequestException::isRefusedAddress($error)) {
				throw $error;
			}

			$response = $this->client->get($url, $options);
			$this->diagnostics->recordRetry($error, $durationMs, $secrets);

			return $response;
		}
	}

	private function getSeconds(): float {
		return (float)$this->timeFactory->now()->format('U.u');
	}
}
