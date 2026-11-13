<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use Closure;
use Exception;
use JsonException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\InvalidSettingException;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\SearchAccountUnfinished;
use OCA\PaperlessUnifiedSearch\Exception\SettingProblem;
use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCA\PaperlessUnifiedSearch\Model\SearchAccount;
use OCA\PaperlessUnifiedSearch\Model\TokenWarning;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ISecureRandom;
use SensitiveParameter;
use Throwable;

/**
 * The search account in Paperless, set up with one click instead of the steps of
 * docs/configure.md (decision 0012). The token of a superuser of Paperless serves these
 * requests alone: nothing stores it, logs it or answers it. With it, the set-up adds the
 * user nextcloud-search, which is neither superuser nor staff and may only view what the
 * search reads, with a random password that nobody learns, and gets its token, with which
 * the settings are saved. As the administrator chose, a workflow lets the user view each
 * new document, and the bulk editor of Paperless adds it to the permission to view of the
 * documents, correspondents, document types and tags that Paperless has. Each change only
 * adds: whoever may view or change something keeps it.
 */
final class SearchAccountSetup {
	/** The username of the search account in Paperless. */
	public const USERNAME = 'nextcloud-search';
	/**
	 * The permissions of the search account, as Paperless names them: View for documents,
	 * correspondents, document types and tags, which the search reads, for users, whose
	 * names tell whom Paperless Sync gives the documents of each owner (decision 0013), and
	 * for its UI settings, which tell the test of the connection what the account may do.
	 */
	public const PERMISSIONS = ['view_document', 'view_correspondent', 'view_documenttype', 'view_tag', 'view_user', 'view_uisettings'];
	/** The name of the workflow that lets the search account view each new document. */
	public const WORKFLOW = 'Nextcloud search: view new documents';
	/** The lists of names that the search shows, as the bulk editor of Paperless names them. */
	public const NAMES = ['correspondents', 'document_types', 'tags'];
	/**
	 * How many IDs one request of the bulk editor names at most: Paperless changes the
	 * permissions of each object of a request before it answers.
	 */
	public const BATCH = 500;
	/** The types of a trigger and an action of a workflow in the API of Paperless-ngx: Document Added, and Assignment. */
	private const DOCUMENT_ADDED = 2;
	private const ASSIGNMENT = 1;
	/** The length of the random password of the search account, which nobody learns. */
	private const PASSWORD_LENGTH = 64;
	/**
	 * How many objects of a list one request asks for in version 10 of the API, whose lists
	 * no longer name the IDs of all objects in the field all, and for how many pages at
	 * most: a million documents, which hold only their IDs, or names.
	 */
	private const ID_PAGE_SIZE = 10000;
	private const MOST_ID_PAGES = 100;
	private const CONNECT_TIMEOUT = 3;
	private const TIMEOUT = 10;
	/** The seconds that one request of the bulk editor may take. */
	private const BULK_TIMEOUT = 60;
	/**
	 * The pauses before each further try of an edit that Paperless failed to make, in
	 * milliseconds (edit()): five tries within a quarter of a minute, which outlast the
	 * task of Paperless-ngx 2 for a batch of documents.
	 */
	private const EDIT_PAUSES_MS = [1000, 2000, 4000, 8000];
	/**
	 * How Paperless-ngx 2 and 3 begin the answer, as plain text with HTTP 400, to an edit
	 * of the bulk editor that failed while they made it, such as "Error performing bulk
	 * permissions edit, check logs for more detail."
	 */
	private const EDIT_FAILED = 'Error performing bulk ';

	private IClient $client;

	/**
	 * The version of the API of Paperless that the requests of a set-up ask for, as
	 * PaperlessApiService does: the newest that the app knows where the first answer of
	 * Paperless names it (requireSuperuser()).
	 */
	private int $api = PaperlessVersion::OLDEST_API;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		IClientService $clientService,
		private ISecureRandom $random,
		private IAppManager $appManager,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * Sets the search account up in the Paperless at $url, as the token of a superuser may,
	 * and hands its token to $save, which saves the settings with it. A failure once
	 * Paperless has added the user removes the user and the workflow again, so that a
	 * second try starts afresh; the permissions of the user go with it. A user or, with
	 * $viewNew, a workflow of the names that the set-up adds is refused before Paperless
	 * changes anything.
	 *
	 * @param string $url the Paperless URL, as ConfigService::normalizeUrl() returns it
	 * @param bool $allowLocal whether Nextcloud may reach this Paperless at an address of
	 *                         the local network
	 * @param bool $viewNew whether a workflow lets the account view each new document
	 * @param bool $viewExisting whether the account may view the documents, correspondents,
	 *                           document types and tags that Paperless has
	 * @param Closure(string): PublicConfig $save tests the connection with the token of
	 *                                            the account and saves the settings
	 * @throws InvalidSettingException when the token is blank or belongs to no superuser,
	 *                                 or the user exists already, or with $viewNew the
	 *                                 workflow
	 * @throws PaperlessRequestException when Paperless doesn't answer as asked; nothing of
	 *                                   the account remains then
	 * @throws SearchAccountUnfinished when a step failed and the user or the workflow
	 *                                 couldn't be removed
	 * @throws Throwable what $save throws, once the account is removed again
	 */
	public function create(string $url, #[SensitiveParameter] string $superuserToken, bool $allowLocal, bool $viewNew, bool $viewExisting, Closure $save): SearchAccount {
		$token = trim($superuserToken);
		if ($token === '') {
			throw new InvalidSettingException(SettingProblem::SuperuserTokenRequired, 'Enter the API token of a superuser of Paperless.');
		}

		$this->requireSuperuser($url, $token, $allowLocal);
		$this->requireNoUser($url, $token, $allowLocal);
		if ($viewNew) {
			$this->requireNoWorkflow($url, $token, $allowLocal);
		}
		$password = $this->random->generate(self::PASSWORD_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$userId = $this->addUser($url, $token, $allowLocal, $password);
		$workflowId = null;
		try {
			$accountToken = $this->tokenOf($url, $allowLocal, $password);
			if ($viewNew) {
				$workflowId = $this->addWorkflow($url, $token, $allowLocal, $userId);
			}
			[$documents, $names] = $viewExisting ? $this->letView($url, $token, $allowLocal, $userId) : [0, 0];
			$config = $save($accountToken);
		} catch (Throwable $failure) {
			[$userRemains, $workflowRemains] = $this->remove($url, $token, $allowLocal, $userId, $workflowId);
			if ($userRemains || $workflowRemains) {
				throw new SearchAccountUnfinished(self::USERNAME, $userRemains, $workflowRemains ? self::WORKFLOW : null, $failure);
			}

			throw $failure;
		}

		return new SearchAccount(self::USERNAME, $viewNew, $documents, $names, $config);
	}

	/**
	 * Only a superuser may add users and change the permissions of what another user owns,
	 * and Paperless names one in /api/ui_settings/, as it does for the warnings about the
	 * token of the settings. Its answer names the version of Paperless, whose API the
	 * further requests ask for: the first request asks for version 9, which Paperless 2.16
	 * and newer and Paperless 3 speak, and once more for version 10 when Paperless refuses
	 * it with HTTP 406, as a Paperless may that no longer speaks it.
	 *
	 * @throws InvalidSettingException when the token belongs to no superuser
	 */
	private function requireSuperuser(string $url, #[SensitiveParameter] string $token, bool $allowLocal): void {
		$this->api = PaperlessVersion::OLDEST_API;
		$response = $this->sent('GET', $url, '/api/ui_settings/', $token, $allowLocal, null, [], self::TIMEOUT);
		if ($response->getStatusCode() === 406) {
			$this->api = PaperlessVersion::NEWEST_API;
			$newer = $this->sent('GET', $url, '/api/ui_settings/', $token, $allowLocal, null, [], self::TIMEOUT);
			// Paperless speaks neither: the refusal of the oldest version says why.
			$response = $newer->getStatusCode() === 406 ? $response : $newer;
		}
		$this->api = PaperlessVersion::apiToAsk(PaperlessVersion::fromResponse($response));

		$warnings = TokenWarning::of(self::dataOf($response, 200, $this->api));
		if ($warnings === null) {
			throw PaperlessRequestException::notPaperless('Paperless named no user for the token.');
		}
		if (!in_array(TokenWarning::Superuser, $warnings, true)) {
			throw new InvalidSettingException(SettingProblem::NotSuperuser, 'The token belongs to no superuser of Paperless.');
		}
	}

	/**
	 * A user of the name stays as it is: whoever uses it may need its permissions and its
	 * password. Paperless compares names in its filter without their case, and so does this.
	 *
	 * @throws InvalidSettingException when Paperless has the user already
	 */
	private function requireNoUser(string $url, #[SensitiveParameter] string $token, bool $allowLocal): void {
		$users = $this->answer('GET', $url, '/api/users/', $token, $allowLocal, query: ['username__iexact' => self::USERNAME, 'page' => 1, 'page_size' => 100])['results'] ?? null;
		if (!is_array($users)) {
			throw PaperlessRequestException::notPaperless('Paperless listed no users.');
		}
		/** @psalm-suppress MixedAssignment Every user is checked before use. */
		foreach ($users as $user) {
			if (is_array($user) && isset($user['username']) && is_string($user['username']) && strcasecmp($user['username'], self::USERNAME) === 0) {
				throw new InvalidSettingException(SettingProblem::SearchAccountExists, 'Paperless has the user ' . self::USERNAME . ' already.');
			}
		}
	}

	/**
	 * Paperless names each workflow once, so a workflow of the name, such as the one of an
	 * earlier set-up whose user an administrator deleted, would refuse the new one after
	 * Paperless had added the user. It stays as it is, as the user does, and the set-up
	 * changes nothing: whoever made or changed it may need it. Paperless filters its
	 * workflows by no name, so this reads each page of them.
	 *
	 * @throws InvalidSettingException when Paperless has the workflow already
	 */
	private function requireNoWorkflow(string $url, #[SensitiveParameter] string $token, bool $allowLocal): void {
		$page = 1;
		$seen = 0;
		do {
			$answer = $this->answer('GET', $url, '/api/workflows/', $token, $allowLocal, query: ['page' => $page, 'page_size' => 100]);
			$workflows = $answer['results'] ?? null;
			$count = $answer['count'] ?? null;
			if (!is_array($workflows) || !is_int($count)) {
				throw PaperlessRequestException::notPaperless('Paperless listed no workflows.');
			}
			/** @psalm-suppress MixedAssignment Every workflow is checked before use. */
			foreach ($workflows as $workflow) {
				if (is_array($workflow) && ($workflow['name'] ?? null) === self::WORKFLOW) {
					throw new InvalidSettingException(SettingProblem::SearchWorkflowExists, 'Paperless has the workflow ' . self::WORKFLOW . ' already.');
				}
			}
			$seen += count($workflows);
			$page++;
		} while ($workflows !== [] && $seen < $count);
	}

	/**
	 * The user of the search account, active, neither superuser nor staff, with the
	 * permissions of PERMISSIONS and nothing else.
	 *
	 * @return int its ID
	 */
	private function addUser(string $url, #[SensitiveParameter] string $token, bool $allowLocal, #[SensitiveParameter] string $password): int {
		return self::idOf($this->answer('POST', $url, '/api/users/', $token, $allowLocal, [
			'username' => self::USERNAME,
			'password' => $password,
			'is_active' => true,
			'is_staff' => false,
			'is_superuser' => false,
			'user_permissions' => self::PERMISSIONS,
		], expected: 201));
	}

	/**
	 * The API token of the search account, as Paperless gives it for its username and
	 * password: without the token of the superuser, which this request doesn't need.
	 */
	private function tokenOf(string $url, bool $allowLocal, #[SensitiveParameter] string $password): string {
		$token = $this->answer('POST', $url, '/api/token/', '', $allowLocal, ['username' => self::USERNAME, 'password' => $password])['token'] ?? null;
		if (!is_string($token) || trim($token) === '') {
			throw PaperlessRequestException::notPaperless('Paperless gave no token for the search account.');
		}

		return trim($token);
	}

	/**
	 * The workflow with the trigger Document Added and an action Assignment that lets the
	 * user view each new document, as docs/configure.md sets it up by hand.
	 *
	 * @return int its ID
	 */
	private function addWorkflow(string $url, #[SensitiveParameter] string $token, bool $allowLocal, int $userId): int {
		return self::idOf($this->answer('POST', $url, '/api/workflows/', $token, $allowLocal, [
			'name' => self::WORKFLOW,
			'order' => 0,
			'enabled' => true,
			'triggers' => [['type' => self::DOCUMENT_ADDED]],
			'actions' => [['type' => self::ASSIGNMENT, 'assign_view_users' => [$userId], 'assign_view_groups' => []]],
		], expected: 201));
	}

	/**
	 * Adds the user to the permission to view of every document, correspondent, document
	 * type and tag of Paperless, as its bulk editor does with Merge with existing
	 * permissions: whoever may view or change one keeps it, and so does its owner. The
	 * names come first: Paperless-ngx 2 updates the documents of an edit afterwards, in a
	 * task of its worker, and on SQLite each edit in that time fails (edit()); the names
	 * start no such task.
	 *
	 * @return array{int, int} how many documents, and how many names, it may view now
	 */
	private function letView(string $url, #[SensitiveParameter] string $token, bool $allowLocal, int $userId): array {
		$permissions = ['view' => ['users' => [$userId], 'groups' => []], 'change' => ['users' => [], 'groups' => []]];
		$names = 0;
		foreach (self::NAMES as $list) {
			$ids = $this->idsOf($url, $token, $allowLocal, $list);
			foreach (array_chunk($ids, self::BATCH) as $batch) {
				$this->edit($url, '/api/bulk_edit_objects/', $token, $allowLocal, [
					'objects' => $batch,
					'object_type' => $list,
					'operation' => 'set_permissions',
					'permissions' => $permissions,
					'merge' => true,
				]);
			}
			$names += count($ids);
		}

		$documents = $this->idsOf($url, $token, $allowLocal, 'documents');
		foreach (array_chunk($documents, self::BATCH) as $batch) {
			$this->edit($url, '/api/documents/bulk_edit/', $token, $allowLocal, [
				'documents' => $batch,
				'method' => 'set_permissions',
				'parameters' => ['set_permissions' => $permissions, 'merge' => true],
			]);
		}

		return [count($documents), $names];
	}

	/**
	 * An edit of the bulk editor of Paperless, made again after a pause while Paperless
	 * fails to make it: Paperless-ngx 2 on SQLite updates the documents of an edit in a
	 * task of its worker, and the writes of the task lock the database, so that an edit
	 * that comes meanwhile, such as the next batch, fails with "database is locked" (#346).
	 * Paperless-ngx 3 waits for the lock instead. Each edit only adds the permission, so
	 * another try changes nothing that the first made; a refused edit, whose reason
	 * Paperless gives in JSON, would fail again and ends the set-up at once.
	 *
	 * @param array<string, mixed> $json
	 * @throws PaperlessRequestException when Paperless doesn't make the edit
	 */
	private function edit(string $url, string $path, #[SensitiveParameter] string $token, bool $allowLocal, array $json): void {
		$pauses = self::EDIT_PAUSES_MS;
		$pause = 0;
		do {
			$response = $this->sent('POST', $url, $path, $token, $allowLocal, $json, [], self::BULK_TIMEOUT, $pause);
			$pause = array_shift($pauses);
		} while ($pause !== null && self::failedToEdit($response));

		self::dataOf($response, 200, $this->api);
	}

	/**
	 * Whether Paperless failed while it made an edit of its bulk editor, rather than
	 * refused it: it answers HTTP 400 with the text of EDIT_FAILED, where it gives the
	 * reason of a refusal in JSON. The client of Nextcloud hands out the body of an answer
	 * once, so only an answer with HTTP 400 is read here; dataOf() reads any other.
	 */
	private static function failedToEdit(IResponse $response): bool {
		if ($response->getStatusCode() !== 400) {
			return false;
		}
		$body = $response->getBody();

		return is_string($body) && str_starts_with($body, self::EDIT_FAILED);
	}

	/**
	 * The IDs of everything in a list of Paperless. In version 9 of the API, they are in the
	 * field all, which Paperless adds to the first page of every list for the selection of
	 * all of it; of the documents, the page holds only the ID of the first, as the test of
	 * the connection asks for it. Version 10 has no field all, and the pages of the list
	 * name them.
	 *
	 * @param string $list documents, or one of NAMES
	 * @return list<int>
	 */
	private function idsOf(string $url, #[SensitiveParameter] string $token, bool $allowLocal, string $list): array {
		if ($this->api >= PaperlessVersion::NEWEST_API) {
			return $this->listedIdsOf($url, $token, $allowLocal, $list);
		}

		$all = $this->answer('GET', $url, '/api/' . $list . '/', $token, $allowLocal, query: ['page' => 1, 'page_size' => 1] + ($list === 'documents' ? ['fields' => 'id'] : []))['all'] ?? null;
		$ids = [];
		/** @psalm-suppress MixedAssignment Every ID is checked before use. */
		foreach (is_array($all) ? $all : [] as $id) {
			if (is_int($id)) {
				$ids[] = $id;
			}
		}
		if (!is_array($all) || count($ids) !== count($all)) {
			throw PaperlessRequestException::notPaperless('Paperless listed no IDs of its ' . $list . '.');
		}

		return $ids;
	}

	/**
	 * The IDs of everything in a list of Paperless, from its pages, as version 10 of the API
	 * names them: the documents ordered by their IDs and with their IDs alone, the names
	 * in the order of Paperless, which sends every field of them.
	 *
	 * @param string $list documents, or one of NAMES
	 * @return list<int>
	 * @throws PaperlessRequestException when Paperless doesn't answer with the list, or
	 *                                   with more of it than MOST_ID_PAGES pages
	 */
	private function listedIdsOf(string $url, #[SensitiveParameter] string $token, bool $allowLocal, string $list): array {
		$ids = [];
		for ($page = 1; $page <= self::MOST_ID_PAGES; $page++) {
			$data = $this->answer('GET', $url, '/api/' . $list . '/', $token, $allowLocal, query: ['page' => $page, 'page_size' => self::ID_PAGE_SIZE] + ($list === 'documents' ? ['ordering' => 'id', 'fields' => 'id'] : []));
			$results = $data['results'] ?? null;
			if (!is_array($results)) {
				throw PaperlessRequestException::notPaperless('Paperless listed no IDs of its ' . $list . '.');
			}
			/** @psalm-suppress MixedAssignment Every object is checked before use. */
			foreach ($results as $object) {
				/** @psalm-suppress MixedAssignment The ID is checked before use. */
				$id = is_array($object) ? $object['id'] ?? null : null;
				$ids[] = is_int($id) ? $id : throw PaperlessRequestException::notPaperless('Paperless listed no IDs of its ' . $list . '.');
			}
			if (($data['next'] ?? null) === null) {
				return $ids;
			}
		}

		throw PaperlessRequestException::notPaperless(sprintf('Paperless lists more than %d of its %s.', self::ID_PAGE_SIZE * self::MOST_ID_PAGES, $list));
	}

	/**
	 * Removes the workflow and the user of a set-up that failed, and with the user its
	 * permissions; each removal is tried, whatever the other does.
	 *
	 * @return array{bool, bool} whether the user, and whether the workflow, remain
	 */
	private function remove(string $url, #[SensitiveParameter] string $token, bool $allowLocal, int $userId, ?int $workflowId): array {
		$workflowRemains = $workflowId !== null && !$this->removed($url, '/api/workflows/' . $workflowId . '/', $token, $allowLocal);
		$userRemains = !$this->removed($url, '/api/users/' . $userId . '/', $token, $allowLocal);

		return [$userRemains, $workflowRemains];
	}

	/**
	 * Whether Paperless removed what the path names, or doesn't have it any more.
	 */
	private function removed(string $url, string $path, #[SensitiveParameter] string $token, bool $allowLocal): bool {
		try {
			$status = $this->send('DELETE', $url, $path, $token, $allowLocal, null, [], self::TIMEOUT)->getStatusCode();
		} catch (Exception) {
			return false;
		}

		return $status === 204 || $status === 404;
	}

	/**
	 * The answer of Paperless to a request, as JSON, once its status is the expected one.
	 *
	 * @param ?array<string, mixed> $json the body of the request
	 * @param array<string, int|string> $query
	 * @return array<array-key, mixed>
	 * @throws PaperlessRequestException when Paperless doesn't answer, or not with the
	 *                                   expected status and JSON
	 */
	private function answer(string $method, string $url, string $path, #[SensitiveParameter] string $token, bool $allowLocal, ?array $json = null, array $query = [], int $expected = 200, int $timeout = self::TIMEOUT): array {
		return self::dataOf($this->sent($method, $url, $path, $token, $allowLocal, $json, $query, $timeout), $expected, $this->api);
	}

	/**
	 * The answer of Paperless to a request, whatever its status.
	 *
	 * @param ?array<string, mixed> $json
	 * @param array<string, int|string> $query
	 * @param int $pause the milliseconds to wait before the request
	 * @throws PaperlessRequestException when Paperless doesn't answer
	 */
	private function sent(string $method, string $url, string $path, #[SensitiveParameter] string $token, bool $allowLocal, ?array $json, array $query, int $timeout, int $pause = 0): IResponse {
		try {
			return $this->send($method, $url, $path, $token, $allowLocal, $json, $query, $timeout, $pause);
		} catch (Exception $error) {
			// The errors of the client of Nextcloud, such as a timeout.
			throw PaperlessRequestException::unanswered($error);
		}
	}

	/**
	 * The JSON of an answer of Paperless, once its status is the expected one.
	 *
	 * @param int $api the version of the API that the request asked for
	 * @return array<array-key, mixed>
	 * @throws PaperlessRequestException when the status is another, or the body no JSON
	 *                                   object
	 */
	private static function dataOf(IResponse $response, int $expected, int $api): array {
		$status = $response->getStatusCode();
		if ($status !== $expected) {
			throw PaperlessRequestException::forStatus($status, $api);
		}

		$body = $response->getBody();
		try {
			/** @psalm-suppress MixedAssignment Its shape is checked below. */
			$data = is_string($body) ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : null;
		} catch (JsonException $error) {
			throw PaperlessRequestException::notPaperless('Paperless returned no JSON: ' . $error->getMessage(), $error);
		}

		return is_array($data) ? $data : throw PaperlessRequestException::notPaperless('Paperless returned no object.');
	}

	/**
	 * A request to Paperless that follows no redirect, which could take the token of the
	 * superuser to another server, and allows the local network only as the settings do.
	 * Every answer reaches the check of its status, whatever the status is. A pause waits
	 * in the client, by the option delay of Guzzle, before the request goes out.
	 *
	 * @param ?array<string, mixed> $json
	 * @param array<string, int|string> $query
	 * @param int $pause the milliseconds to wait before the request
	 */
	private function send(string $method, string $url, string $path, #[SensitiveParameter] string $token, bool $allowLocal, ?array $json, array $query, int $timeout, int $pause = 0): IResponse {
		$options = [
			'headers' => [
				'Accept' => 'application/json; version=' . $this->api,
				'User-Agent' => 'Nextcloud-Paperless-Unified-Search/' . $this->appManager->getAppVersion(AppConstants::APP_ID),
			] + ($token !== '' ? ['Authorization' => 'Token ' . $token] : []),
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'timeout' => $timeout,
			'http_errors' => false,
			'allow_redirects' => false,
			'nextcloud' => ['allow_local_address' => $allowLocal],
		] + ($query !== [] ? ['query' => $query] : []) + ($json !== null ? ['json' => $json] : []) + ($pause > 0 ? ['delay' => $pause] : []);

		return match ($method) {
			'GET' => $this->client->get($url . $path, $options),
			'POST' => $this->client->post($url . $path, $options),
			default => $this->client->delete($url . $path, $options),
		};
	}

	/**
	 * The ID of what Paperless added, as its answer names it. Psalm's taint analysis ends
	 * here the path from the answer to the path of a later request, which removes the user
	 * or the workflow again: a number names an object of the same Paperless and can't
	 * change the server that the request goes to.
	 *
	 * @psalm-taint-escape ssrf
	 * @param array<array-key, mixed> $data
	 */
	private static function idOf(array $data): int {
		return isset($data['id']) && is_int($data['id']) ? $data['id'] : throw PaperlessRequestException::notPaperless('Paperless named no ID of what it added.');
	}
}
