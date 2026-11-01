<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use Exception;
use JsonException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\PaperlessStatus;
use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;
use OCA\PaperlessUnifiedSearch\Model\TokenWarning;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Throwable;
use UnexpectedValueException;

final class PaperlessApiService {
	/**
	 * The fields of a document that a result shows, its correspondent, its type and its
	 * tags as IDs, and __search_hit__ with the excerpt. Without them, Paperless sends the
	 * whole OCR text of every document, with its notes, custom fields, permissions and
	 * versions, half a megabyte for a long document, and runs queries of its own for them.
	 */
	private const SEARCH_FIELDS = 'id,title,created,correspondent,document_type,tags,__search_hit__';

	/**
	 * The fields of a document that the dashboard shows, with the moment that Paperless
	 * added it, by which the dashboard lists the newest documents, instead of the date of
	 * the document and the excerpt of a search.
	 */
	private const RECENT_FIELDS = 'id,title,added,correspondent,document_type,tags';

	/**
	 * How many of the newest documents the test of the connection looks at for names that
	 * the account of the token can't see, as many as a page of a search, and the only
	 * fields of a document that it reads for them.
	 */
	private const NAMES_CHECK_DOCUMENTS = 25;
	private const NAMES_CHECK_FIELDS = 'id,correspondent,document_type,tags';

	/**
	 * The filter of Paperless for the documents of a day and later, by the date of the
	 * document, as Y-m-d: the same in Paperless-ngx 2, where the date has a time, and 3.
	 */
	public const CREATED_FROM = 'created__date__gte';

	/** The filter of Paperless for the documents of a day and earlier, by their date. */
	public const CREATED_UNTIL = 'created__date__lte';

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
	 * The seconds that a request for the names of correspondents, types or tags may take
	 * in all: Paperless has just answered the search, and a result shows without the names.
	 */
	private const NAMES_TIMEOUT = 3;

	/**
	 * The seconds that the request for what the account of the token may do may take in
	 * all: Paperless has just answered the test of the connection, which passes without it.
	 */
	private const PRIVILEGES_TIMEOUT = 3;

	/**
	 * The seconds that the request for the newest documents may take in all: the
	 * dashboard waits for it, and Paperless answers it from its database alone.
	 */
	private const RECENT_TIMEOUT = 5;

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

	/**
	 * A term that asks for the documents similar to one, as More like this in the web
	 * interface of Paperless does: like: with the ID of the document, alone or as the marker
	 * of its file, such as like:412, like:P412 and like:[P412], in capitals or not. Nothing
	 * else fits, no further word and no ID with a leading zero, which no document has: such a
	 * term is a search like any other.
	 */
	private const SIMILAR_TERM = '/^like:(?|\[p([1-9]\d*)\]|p?([1-9]\d*))$/i';

	/**
	 * What Paperless answers with HTTP 403 to the question for documents similar to one that
	 * it doesn't have, and to one that the account of the token may not view: only these
	 * words, whereas it refuses an account that may view no documents at all with the field
	 * detail in JSON. The app asks for no language, so they are always in English.
	 * tests/e2e/paperless-contract.sh checks both against real Paperless servers.
	 */
	private const UNKNOWN_DOCUMENT = 'Invalid more_like_id';
	private const HIDDEN_DOCUMENT = 'Insufficient permissions.';
	/**
	 * What Paperless-ngx before 2.20.13 answers with HTTP 400 when a search fails for any
	 * reason, for documents similar to one that it doesn't have as well.
	 */
	private const SEARCH_ERROR = 'Error listing search results, check logs for more detail.';

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

	/**
	 * The test of the connection to the Paperless of the settings, as occ and its checks
	 * run it. The version that Paperless names replaces the stored one, and so do the
	 * warnings about the account of the token, which the overview of the administration
	 * settings and the settings page show.
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
		$this->configService->rememberTokenWarnings($status->tokenWarnings);

		return $status;
	}

	/**
	 * Asks Paperless for its first document, which tells whether it answers, its version
	 * and how many documents the account of the token may view, with their IDs in the field
	 * all, which Paperless-ngx adds to every list for the selection of all documents. Once
	 * Paperless has answered, it asks what the account of the token may do, for the
	 * warnings about it, and whether it can see the names of what it may view.
	 *
	 * @param bool $allowLocal whether Nextcloud may reach this Paperless at an address of
	 *                         the local network
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
		} catch (PaperlessRequestException $exception) {
			throw $exception;
		} catch (Throwable $error) {
			throw PaperlessRequestException::unanswered($error);
		}

		$documents = self::documentsOf($data)['count'];

		return new PaperlessStatus(
			$version,
			$documents,
			self::idsOf($data['all'] ?? null),
			$this->findTokenWarnings($url, $token, $allowLocal, $documents),
		);
	}

	/**
	 * What the account of the token may do beyond what the search needs, or may not do of
	 * what the results show, as /api/ui_settings/ of Paperless names it: the user of the
	 * token and the permissions of its account and its groups. Paperless answers it only to
	 * an account that may view its UI settings, and refuses it with HTTP 403 otherwise. The
	 * warnings only add to a test that Paperless has passed, so the request gets one try, a
	 * short time and no place in the history of the problems of the search, and a request
	 * without an answer, an answer other than HTTP 200 and one that isn't JSON leave the
	 * warnings unknown.
	 *
	 * @param int $documents how many documents the account of the token may view
	 * @return ?list<TokenWarning> null when Paperless doesn't say what the account may do
	 */
	private function findTokenWarnings(string $url, string $token, bool $allowLocal, int $documents): ?array {
		try {
			$response = $this->client->get(
				rtrim($url, '/') . '/api/ui_settings/',
				$this->options($token, $allowLocal, [], self::PRIVILEGES_TIMEOUT),
			);
			$body = $response->getBody();
			if ($response->getStatusCode() !== 200 || !is_string($body)) {
				return null;
			}

			$warnings = TokenWarning::of(json_decode($body, true, flags: JSON_THROW_ON_ERROR));
		} catch (Exception) {
			// The errors of the client of Nextcloud, such as a timeout, and of JSON.
			return null;
		}

		// A superuser sees every name, and an account without documents misses none.
		if ($warnings === null || $documents === 0 || in_array(TokenWarning::Superuser, $warnings, true)) {
			return $warnings;
		}

		return $this->hidesNamesOfOthers($url, $token, $allowLocal) ? [...$warnings, TokenWarning::HidesNamesOfOthers] : $warnings;
	}

	/**
	 * Whether the account of the token can't see the name of a correspondent, a type or a
	 * tag of the newest documents that it may view. The permission to view them, which
	 * /api/ui_settings/ names, doesn't tell: Paperless applies the permissions of each
	 * object to them as it does to documents, and whoever adds one in its web interface
	 * owns it. A document names the ID of such an object all the same, while the
	 * list of the objects leaves it out, so asking for both tells. Like the question for
	 * the privileges, it only adds to a test that Paperless has passed: its requests get
	 * one try, a short time and no place in the history of the problems of the search, and
	 * one that fails finds nothing.
	 */
	private function hidesNamesOfOthers(string $url, string $token, bool $allowLocal): bool {
		try {
			[$data] = $this->requestDocuments($url, $token, $allowLocal, [
				'ordering' => '-added',
				'page' => 1,
				'page_size' => self::NAMES_CHECK_DOCUMENTS,
				'fields' => self::NAMES_CHECK_FIELDS,
			], timeout: self::PRIVILEGES_TIMEOUT, retry: false);
		} catch (Exception) {
			return false;
		}

		$documents = self::documentsOf($data)['results'];
		foreach (PaperlessNames::FIELDS as $field => $list) {
			$ids = PaperlessNames::idsIn($documents, $field);
			if ($ids !== [] && $this->lacksNames($url, $token, $allowLocal, $list, $ids)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a list of Paperless leaves out one of these IDs. A list that fails says
	 * nothing, as one that the account may not view at all, which has its own warning. The
	 * tag of the inbox counts as seen: the app leaves its name out, not Paperless.
	 *
	 * @param string $list the path of the list in the API of Paperless: correspondents, document_types or tags
	 * @param non-empty-array<int, int> $ids
	 */
	private function lacksNames(string $url, string $token, bool $allowLocal, string $list, array $ids): bool {
		try {
			return array_diff($ids, array_keys($this->namesFrom($url, $token, $allowLocal, $list, $ids, withInbox: true))) !== [];
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * Search Paperless through its native full-text index. A query in the advanced syntax
	 * of Paperless 3 that it can't parse, as one that is still being typed, goes to its
	 * simple mode instead, which takes any input, with only its plain words: the simple
	 * mode looks for every word it gets, and the field still being typed would match no
	 * document. Paperless 2 never gets it: it ignores the parameter and would list all
	 * documents. A term such as like:[P412] asks for the documents similar to that one, as
	 * SIMILAR_TERM says, without the document itself, which Paperless leaves out. The filters
	 * narrow the documents in every case; none of them replaces the term, the page or the
	 * fields.
	 *
	 * @param array<string, string> $filters filters of Paperless on the documents, such as
	 *                                       CREATED_FROM => '2026-08-01'
	 * @return array{count: int, next: ?string, results: list<array<array-key, mixed>>}
	 * @throws PaperlessQueryRejected when Paperless refuses the term, the page or the document
	 *                                to find similar ones of, which is no problem of the search
	 * @throws PaperlessRequestException when Paperless answers, but not with documents
	 * @throws Throwable the error of a request without answer, as the history shows it
	 */
	public function searchDocuments(string $term, int $page, int $pageSize, array $filters = []): array {
		$parameters = [
			'page' => max(1, $page),
			'page_size' => max(1, min(50, $pageSize)),
			'fields' => self::SEARCH_FIELDS,
		] + $filters;
		$similarTo = self::similarTo($term);
		try {
			[$documents, $version] = $this->search(($similarTo === null ? ['query' => $term] : ['more_like_id' => $similarTo]) + $parameters, $term);
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
	 * The documents that Paperless added last, newest first, as many as asked for, with
	 * the fields that the dashboard shows: as far as the account of the token may view
	 * them, also those that a user can't open. The dashboard waits for the answer, so the
	 * request gets one try and a short time, and no place in the history of the problems
	 * of the search, which is about searches.
	 *
	 * @return list<array<array-key, mixed>>
	 * @throws PaperlessRequestException when Paperless answers, but not with documents
	 * @throws Throwable the error of a request without answer, or without the settings
	 */
	public function findRecentDocuments(int $count): array {
		[$data, $version] = $this->requestDocuments(
			$this->configService->getUrl(),
			$this->configService->getToken(),
			$this->configService->isLocalNetworkAllowed(),
			[
				'ordering' => '-added',
				'page' => 1,
				'page_size' => max(1, min(100, $count)),
				'fields' => self::RECENT_FIELDS,
			],
			timeout: self::RECENT_TIMEOUT,
			retry: false,
		);
		$this->configService->rememberPaperlessVersion($version);

		return self::documentsOf($data)['results'];
	}

	/**
	 * The ID of the document that a term asks for similar ones of, as Paperless takes it:
	 * as text, so that a number too large for an integer stays what it is, one that
	 * Paperless doesn't know. Null for any other term.
	 */
	private static function similarTo(string $term): ?string {
		return preg_match(self::SIMILAR_TERM, trim($term), $match) === 1 ? $match[1] : null;
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
	 * @param int $timeout the seconds that the request may take in all
	 * @param bool $retry whether a request without answer gets a second try, as send() decides
	 * @return array{array{results: array<array-key, mixed>, ...<array-key, mixed>}, ?PaperlessVersion}
	 * @throws PaperlessRequestException when Paperless answers, but not with documents
	 */
	private function requestDocuments(string $url, string $token, bool $allowLocal, array $query, string $term = '', int $timeout = self::TIMEOUT, bool $retry = true): array {
		if ($url === '' || $token === '') {
			throw new UnexpectedValueException('Paperless is not configured.');
		}

		$documents = rtrim($url, '/') . '/api/documents/';
		$options = $this->options($token, $allowLocal, $query, $timeout);
		$response = $retry ? $this->send($documents, $options, [$token, $term]) : $this->client->get($documents, $options);

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
	 * view, or that no longer exists, has no name, and neither has a tag of the inbox,
	 * which Paperless gives every new document until someone has looked at it: it says
	 * nothing about the document, and most documents of a busy archive have it.
	 *
	 * @param string $list the path of the list in the API of Paperless: correspondents, document_types or tags
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

		return $this->namesFrom($url, $token, $this->configService->isLocalNetworkAllowed(), $list, $ids);
	}

	/**
	 * @param string $list the path of the list in the API of Paperless: correspondents, document_types or tags
	 * @param non-empty-array<int, int> $ids
	 * @param bool $withInbox whether the tag of the inbox, which no result shows, keeps its name
	 * @return array<int, string> the names, by ID
	 * @throws PaperlessRequestException when Paperless answers, but not with a list
	 * @throws Throwable the error of a request without answer
	 */
	private function namesFrom(string $url, string $token, bool $allowLocal, string $list, array $ids, bool $withInbox = false): array {
		// Paperless-ngx 2 and 3 filter their lists by id__in; they send every field of an
		// object, also when asked for fewer, which for a correspondent is a few hundred bytes.
		$response = $this->client->get(
			rtrim($url, '/') . '/api/' . $list . '/',
			$this->options($token, $allowLocal, [
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
			$data = json_decode(is_string($body) ? $body : '', true, flags: JSON_THROW_ON_ERROR);
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
			$inbox = is_array($object) && ($object['is_inbox_tag'] ?? null) === true;
			if (is_int($id) && is_string($name) && ($withInbox || !$inbox)) {
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
	 * two of its pages, and with a refusal of the document to the question for documents
	 * similar to one. All of them come from what the user typed: the search finds nothing.
	 * Any other answer is a failure, also HTTP 400 for another reason, such as the page of a
	 * proxy, HTTP 403 for an account that may view no documents, and HTTP 404 for the first
	 * page, which a wrong URL answers.
	 *
	 * @param array<string, int|string> $query
	 */
	private static function failureOf(IResponse $response, array $query): PaperlessRequestException {
		$status = $response->getStatusCode();
		$similar = isset($query['more_like_id']);
		$searches = $similar || isset($query['query']) || isset($query['text']);
		if ($searches && $status === 400 && self::refusesTheQuery($response)) {
			return new PaperlessQueryRejected($status, PaperlessVersion::fromResponse($response));
		}

		if ($similar && self::refusesTheDocument($response)) {
			return new PaperlessQueryRejected($status, refusedDocument: true);
		}

		$page = $query['page'] ?? 1;
		if ($searches && $status === 404 && $page > 1) {
			return new PaperlessQueryRejected($status);
		}

		return PaperlessRequestException::forStatus($status);
	}

	/**
	 * Whether an answer to the question for documents similar to one refuses that document:
	 * HTTP 403 with the words of Paperless for one that it doesn't have or that the account
	 * of the token may not view, or, from a Paperless that doesn't check the document yet,
	 * HTTP 400 with the words of its error. That Paperless says the same when its index
	 * fails, so such a failure counts as a refusal here; any other search still notices it.
	 */
	private static function refusesTheDocument(IResponse $response): bool {
		$status = $response->getStatusCode();
		$body = $response->getBody();
		if ($status === 403) {
			return in_array($body, [self::UNKNOWN_DOCUMENT, self::HIDDEN_DOCUMENT], true);
		}

		$version = PaperlessVersion::fromResponse($response);

		return $status === 400 && $body === self::SEARCH_ERROR && $version !== null && !$version->checksSimilarDocument();
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
