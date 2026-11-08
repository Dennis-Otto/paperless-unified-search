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
	/** The version of the API of Paperless that the search speaks too (PaperlessApiService). */
	private const ACCEPT = 'application/json; version=9';
	private const CONNECT_TIMEOUT = 3;
	private const TIMEOUT = 10;
	/** The seconds that one request of the bulk editor may take. */
	private const BULK_TIMEOUT = 60;

	private IClient $client;

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
	 * token of the settings.
	 *
	 * @throws InvalidSettingException when the token belongs to no superuser
	 */
	private function requireSuperuser(string $url, #[SensitiveParameter] string $token, bool $allowLocal): void {
		$warnings = TokenWarning::of($this->answer('GET', $url, '/api/ui_settings/', $token, $allowLocal));
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
	 * permissions: whoever may view or change one keeps it, and so does its owner.
	 *
	 * @return array{int, int} how many documents, and how many names, it may view now
	 */
	private function letView(string $url, #[SensitiveParameter] string $token, bool $allowLocal, int $userId): array {
		$permissions = ['view' => ['users' => [$userId], 'groups' => []], 'change' => ['users' => [], 'groups' => []]];
		$documents = $this->idsOf($url, $token, $allowLocal, 'documents');
		foreach (array_chunk($documents, self::BATCH) as $batch) {
			$this->answer('POST', $url, '/api/documents/bulk_edit/', $token, $allowLocal, [
				'documents' => $batch,
				'method' => 'set_permissions',
				'parameters' => ['set_permissions' => $permissions, 'merge' => true],
			], timeout: self::BULK_TIMEOUT);
		}

		$names = 0;
		foreach (self::NAMES as $list) {
			$ids = $this->idsOf($url, $token, $allowLocal, $list);
			foreach (array_chunk($ids, self::BATCH) as $batch) {
				$this->answer('POST', $url, '/api/bulk_edit_objects/', $token, $allowLocal, [
					'objects' => $batch,
					'object_type' => $list,
					'operation' => 'set_permissions',
					'permissions' => $permissions,
					'merge' => true,
				], timeout: self::BULK_TIMEOUT);
			}
			$names += count($ids);
		}

		return [count($documents), $names];
	}

	/**
	 * The IDs of everything in a list of Paperless, in the field all, which Paperless adds
	 * to the first page of every list for the selection of all of it. Of the documents, the
	 * page holds only the ID of the first, as the test of the connection asks for it.
	 *
	 * @param string $list documents, or one of NAMES
	 * @return list<int>
	 */
	private function idsOf(string $url, #[SensitiveParameter] string $token, bool $allowLocal, string $list): array {
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
		try {
			$response = $this->send($method, $url, $path, $token, $allowLocal, $json, $query, $timeout);
		} catch (Exception $error) {
			// The errors of the client of Nextcloud, such as a timeout.
			throw PaperlessRequestException::unanswered($error);
		}

		$status = $response->getStatusCode();
		if ($status !== $expected) {
			throw PaperlessRequestException::forStatus($status);
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
	 * Every answer reaches the check of its status, whatever the status is.
	 *
	 * @param ?array<string, mixed> $json
	 * @param array<string, int|string> $query
	 */
	private function send(string $method, string $url, string $path, #[SensitiveParameter] string $token, bool $allowLocal, ?array $json, array $query, int $timeout): IResponse {
		$options = [
			'headers' => [
				'Accept' => self::ACCEPT,
				'User-Agent' => 'Nextcloud-Paperless-Unified-Search/' . $this->appManager->getAppVersion(AppConstants::APP_ID),
			] + ($token !== '' ? ['Authorization' => 'Token ' . $token] : []),
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'timeout' => $timeout,
			'http_errors' => false,
			'allow_redirects' => false,
			'nextcloud' => ['allow_local_address' => $allowLocal],
		] + ($query !== [] ? ['query' => $query] : []) + ($json !== null ? ['json' => $json] : []);

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
