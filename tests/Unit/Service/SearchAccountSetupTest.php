<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use Exception;
use JsonException;
use OCA\PaperlessUnifiedSearch\Exception\InvalidSettingException;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Exception\SearchAccountUnfinished;
use OCA\PaperlessUnifiedSearch\Exception\SettingProblem;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCA\PaperlessUnifiedSearch\Service\SearchAccountSetup;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The set-up of the search account in Paperless with the token of a superuser (decision
 * 0012), against answers of Paperless as its contract tests check them on Paperless-ngx
 * 2.20 and 3.x.
 */
final class SearchAccountSetupTest extends TestCase {
	private const URL = 'http://paperless:8000';
	private const SUPERUSER_TOKEN = 'SUPERUSER_VALUE';
	private const ACCOUNT_TOKEN = 'ACCOUNT_VALUE';
	private const PASSWORD = 'RANDOM_PASSWORD_VALUE';
	/** /api/ui_settings/ for the token of a superuser, abridged. */
	private const SUPERUSER = '{"user":{"id":1,"username":"admin","is_staff":true,"is_superuser":true,"groups":[]},"settings":{},"permissions":["add_user","view_document"]}';
	/** The same for the account that the documentation recommends. */
	private const VIEWER = '{"user":{"id":3,"username":"nextcloud","is_staff":false,"is_superuser":false,"groups":[]},"settings":{},"permissions":["view_document"]}';
	private const NO_USERS = '{"count":0,"next":null,"previous":null,"all":[],"results":[]}';
	private const OK = '{"result":"OK"}';
	/** The view permission that each bulk edit adds, for the user 7, with nothing to change. */
	private const PERMISSIONS = ['view' => ['users' => [7], 'groups' => []], 'change' => ['users' => [], 'groups' => []]];

	/** @var list<array{string, string, array<string, mixed>}> the method, the path and the options of each request */
	private array $requests = [];

	public function testItAddsTheUserLetsItViewEverythingAndSavesWithItsToken(): void {
		$saved = [];
		$account = $this->accountSetup($this->paperless())->create(self::URL, ' ' . self::SUPERUSER_TOKEN . ' ', true, true, true, static function (string $token) use (&$saved): PublicConfig {
			$saved[] = $token;

			return new PublicConfig(self::URL, true, false);
		});

		self::assertSame([self::ACCOUNT_TOKEN], $saved);
		self::assertSame('nextcloud-search', $account->username);
		self::assertTrue($account->workflow);
		self::assertSame(3, $account->documents);
		self::assertSame(3, $account->names);
		self::assertSame(self::URL, $account->config->url);
		self::assertSame([
			'GET /api/ui_settings/',
			'GET /api/users/',
			'GET /api/workflows/',
			'POST /api/users/',
			'POST /api/token/',
			'POST /api/workflows/',
			// The names first: Paperless-ngx 2 updates the documents of an edit in a task
			// of its worker, whose writes to a database of SQLite fail an edit meanwhile.
			'GET /api/correspondents/',
			'POST /api/bulk_edit_objects/',
			'GET /api/document_types/',
			'GET /api/tags/',
			'POST /api/bulk_edit_objects/',
			'GET /api/documents/',
			'POST /api/documents/bulk_edit/',
		], $this->asked());

		// Every request goes to the Paperless of the form, follows no redirect and allows
		// the local network as the form does.
		self::assertSame([
			'headers' => [
				'Accept' => 'application/json; version=9',
				'User-Agent' => 'Nextcloud-Paperless-Unified-Search/1.2.3',
				'Authorization' => 'Token ' . self::SUPERUSER_TOKEN,
			],
			'connect_timeout' => 3,
			'timeout' => 10,
			'http_errors' => false,
			'allow_redirects' => false,
			'nextcloud' => ['allow_local_address' => true],
		], $this->requests[0][2]);
		self::assertSame(['username__iexact' => 'nextcloud-search', 'page' => 1, 'page_size' => 100], $this->requests[1][2]['query']);
		self::assertSame(['page' => 1, 'page_size' => 100], $this->requests[2][2]['query']);
		// A user that may only view what the search reads, with a random password.
		self::assertSame([
			'username' => 'nextcloud-search',
			'password' => self::PASSWORD,
			'is_active' => true,
			'is_staff' => false,
			'is_superuser' => false,
			'user_permissions' => ['view_document', 'view_correspondent', 'view_documenttype', 'view_tag', 'view_user', 'view_uisettings'],
		], $this->requests[3][2]['json']);
		// Its token comes for its password alone, without the token of the superuser.
		self::assertSame(['username' => 'nextcloud-search', 'password' => self::PASSWORD], $this->requests[4][2]['json']);
		self::assertArrayNotHasKey('Authorization', $this->requests[4][2]['headers']);
		self::assertSame([
			'name' => 'Nextcloud search: view new documents',
			'order' => 0,
			'enabled' => true,
			'triggers' => [['type' => 2]],
			'actions' => [['type' => 1, 'assign_view_users' => [7], 'assign_view_groups' => []]],
		], $this->requests[5][2]['json']);
		self::assertSame(['page' => 1, 'page_size' => 1], $this->requests[6][2]['query']);
		self::assertSame(['page' => 1, 'page_size' => 1, 'fields' => 'id'], $this->requests[11][2]['query']);
		// The permissions only add the user, to each document and name that Paperless has.
		self::assertSame(['objects' => [5], 'object_type' => 'correspondents', 'operation' => 'set_permissions', 'permissions' => self::PERMISSIONS, 'merge' => true], $this->requests[7][2]['json']);
		self::assertSame(['objects' => [8, 9], 'object_type' => 'tags', 'operation' => 'set_permissions', 'permissions' => self::PERMISSIONS, 'merge' => true], $this->requests[10][2]['json']);
		self::assertSame([
			'documents' => [1, 2, 3],
			'method' => 'set_permissions',
			'parameters' => ['set_permissions' => self::PERMISSIONS, 'merge' => true],
		], $this->requests[12][2]['json']);
		self::assertSame(60, $this->requests[12][2]['timeout']);
		// An edit that Paperless makes at once goes without a pause.
		self::assertArrayNotHasKey('delay', $this->requests[12][2]);
		self::assertArrayNotHasKey('delay', $this->requests[7][2]);
		self::assertStringNotContainsString(self::SUPERUSER_TOKEN, (string)json_encode($account));
		self::assertStringNotContainsString(self::ACCOUNT_TOKEN, (string)json_encode($account));
	}

	/**
	 * Without the choices, the user may only view what Paperless lets every user view, its
	 * own and what has no owner, and Paperless changes nothing else.
	 */
	public function testWithoutItsChoicesItOnlyAddsTheUser(): void {
		$account = $this->accountSetup($this->paperless())->create(self::URL, self::SUPERUSER_TOKEN, false, false, false, $this->saves());

		self::assertSame(['GET /api/ui_settings/', 'GET /api/users/', 'POST /api/users/', 'POST /api/token/'], $this->asked());
		self::assertFalse($account->workflow);
		self::assertSame(0, $account->documents);
		self::assertSame(0, $account->names);
		self::assertSame(['allow_local_address' => false], $this->requests[0][2]['nextcloud']);
	}

	/**
	 * A large archive goes to the bulk editor in batches, each of which Paperless finishes
	 * within the time of its request.
	 */
	public function testTheBulkEditorGetsTheIdsInBatches(): void {
		$ids = range(1, 2 * SearchAccountSetup::BATCH + 1);
		$this->accountSetup($this->paperless(['GET /api/documents/' => self::answer(200, (string)json_encode(['count' => count($ids), 'all' => $ids, 'results' => [['id' => 1]]]))]))
			->create(self::URL, self::SUPERUSER_TOKEN, false, false, true, $this->saves());

		$batches = array_map(
			static fn (array $request): array => $request[2]['json']['documents'],
			array_values(array_filter($this->requests, static fn (array $request): bool => $request[1] === '/api/documents/bulk_edit/')),
		);
		self::assertSame([range(1, 500), range(501, 1000), [1001]], $batches);
	}

	/**
	 * Paperless-ngx 2 on SQLite updates the documents of an edit of the bulk editor in a
	 * task of its worker, whose writes lock the database: an edit meanwhile, such as the
	 * next batch, fails with "database is locked", and Paperless answers HTTP 400 with the
	 * text of a failed edit, as the contract with Paperless-ngx 2 met it (#346). Each edit
	 * only adds the permission, so the set-up makes it again after a pause, which grows
	 * with each try, and the account works.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function editsThatPaperlessFailed(): array {
		return [
			'of the documents' => ['/api/documents/bulk_edit/', 'Error performing bulk edit, check logs for more detail.'],
			'of the names' => ['/api/bulk_edit_objects/', 'Error performing bulk permissions edit, check logs for more detail.'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('editsThatPaperlessFailed')]
	public function testAnEditThatPaperlessFailedToMakeIsMadeAgainAfterAPause(string $path, string $failed): void {
		$tries = 0;
		$account = $this->accountSetup($this->paperless([
			'POST ' . $path => static function () use (&$tries, $failed): IResponse {
				return ++$tries <= 2 ? self::answer(400, $failed) : self::answer(200, self::OK);
			},
		]))->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());

		self::assertSame(3, $account->documents);
		$edits = array_values(array_filter($this->requests, static fn (array $request): bool => $request[1] === $path));
		self::assertSame([null, 1000, 2000], array_map(static fn (array $request): ?int => $request[2]['delay'] ?? null, array_slice($edits, 0, 3)));
		// Each try is the same edit, with the time of an edit.
		self::assertSame([$edits[0][2]['json'], $edits[0][2]['json']], [$edits[1][2]['json'], $edits[2][2]['json']]);
		self::assertSame([60, 60], [$edits[1][2]['timeout'], $edits[2][2]['timeout']]);
		self::assertNotContains('DELETE', array_column($this->requests, 0));
	}

	/**
	 * An edit that Paperless keeps failing ends the set-up after five tries and about a
	 * quarter of a minute, with the account removed again, as any other failed step.
	 */
	public function testAnEditThatPaperlessKeepsFailingEndsTheSetUp(): void {
		try {
			$this->accountSetup($this->paperless(['POST /api/documents/bulk_edit/' => self::answer(400, 'Error performing bulk edit, check logs for more detail.')]))
				->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());
			self::fail('The set-up succeeded.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame([RequestFailure::Http, 400], [$exception->reason, $exception->status]);
		}

		$edits = array_values(array_filter($this->requests, static fn (array $request): bool => $request[1] === '/api/documents/bulk_edit/'));
		self::assertSame([null, 1000, 2000, 4000, 8000], array_map(static fn (array $request): ?int => $request[2]['delay'] ?? null, $edits));
		self::assertSame(['DELETE /api/workflows/4/', 'DELETE /api/users/7/'], array_slice($this->asked(), -2));
	}

	/**
	 * An edit that Paperless refuses, with its reason in JSON, or that fails otherwise,
	 * would fail again: the set-up ends at once.
	 *
	 * @return array<string, array{IResponse}>
	 */
	public static function editsThatFailForGood(): array {
		return [
			'refused as invalid' => [self::answer(400, '{"objects":["Some IDs of these objects don\'t exist."]}')],
			'with an error of the server' => [self::answer(500, 'Error performing bulk permissions edit, check logs for more detail.')],
			'without a body' => [new class() implements IResponse {
				/** @return resource */
				public function getBody() {
					return fopen('php://memory', 'rb');
				}

				public function getStatusCode(): int {
					return 400;
				}

				public function getHeader(string $key): string {
					return '';
				}

				public function getHeaders(): array {
					return [];
				}
			}],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('editsThatFailForGood')]
	public function testAnEditThatWouldFailAgainEndsTheSetUpAtOnce(IResponse $answer): void {
		try {
			$this->accountSetup($this->paperless(['POST /api/bulk_edit_objects/' => $answer]))
				->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());
			self::fail('The set-up succeeded.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame($answer->getStatusCode(), $exception->status);
		}

		self::assertSame(['POST /api/bulk_edit_objects/'], array_values(array_filter($this->asked(), static fn (string $request): bool => str_starts_with($request, 'POST /api/bulk'))));
	}

	public function testABlankTokenIsRefusedBeforeAnyRequest(): void {
		$this->expectRefusal(SettingProblem::SuperuserTokenRequired, fn () => $this->accountSetup([])->create(self::URL, " \n", false, true, true, $this->saves()));
		self::assertSame([], $this->requests);
	}

	/**
	 * Only a superuser may add users and change the permissions of what others own.
	 */
	public function testTheTokenOfAnotherAccountIsRefused(): void {
		$this->expectRefusal(SettingProblem::NotSuperuser, fn () => $this->accountSetup($this->paperless(['GET /api/ui_settings/' => self::answer(200, self::VIEWER)]))
			->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves()));
		self::assertSame(['GET /api/ui_settings/'], $this->asked());
	}

	/**
	 * A user of the name stays as it is, whatever the case of its name, and the refusal
	 * names it.
	 */
	public function testAUserOfTheNameStaysAsItIs(): void {
		$users = (string)json_encode(['count' => 2, 'results' => [['id' => 2, 'username' => 'nextcloud'], 'odd', ['id' => 9, 'username' => 'Nextcloud-Search']]]);

		$this->expectRefusal(SettingProblem::SearchAccountExists, fn () => $this->accountSetup($this->paperless(['GET /api/users/' => self::answer(200, $users)]))
			->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves()), 'Paperless has the user nextcloud-search already.');
		self::assertSame(['GET /api/ui_settings/', 'GET /api/users/'], $this->asked());
	}

	/**
	 * Other users of Paperless, and entries of its list without a name, are no user of the
	 * name: the set-up goes on.
	 */
	public function testOtherUsersAreNoUserOfTheName(): void {
		$users = (string)json_encode(['count' => 3, 'results' => [['id' => 2, 'username' => 'nextcloud'], 'odd', ['id' => 3]]]);

		$this->accountSetup($this->paperless(['GET /api/users/' => self::answer(200, $users)]))->create(self::URL, self::SUPERUSER_TOKEN, false, true, false, $this->saves());

		self::assertContains('POST /api/users/', $this->asked());
	}

	/**
	 * Paperless names each workflow once, so a workflow of the name, such as the one of an
	 * earlier set-up whose user was deleted, stays as it is, on whichever page of the
	 * workflows it is, and Paperless changes nothing; only that very name counts.
	 */
	public function testAWorkflowOfTheNameStaysAsItIs(): void {
		$others = array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Workflow ' . $id], range(1, 99));
		$pages = [
			1 => (string)json_encode(['count' => 102, 'results' => [...$others, ['id' => 100, 'name' => 'nextcloud search: view new documents']]]),
			2 => (string)json_encode(['count' => 102, 'results' => ['odd', ['id' => 101, 'name' => 'Nextcloud search: view new documents']]]),
		];
		$workflows = static fn (array $options): IResponse => self::answer(200, $pages[$options['query']['page']]);

		$this->expectRefusal(SettingProblem::SearchWorkflowExists, fn () => $this->accountSetup($this->paperless(['GET /api/workflows/' => $workflows]))
			->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves()), 'Paperless has the workflow Nextcloud search: view new documents already.');
		self::assertSame(['GET /api/ui_settings/', 'GET /api/users/', 'GET /api/workflows/', 'GET /api/workflows/'], $this->asked());
		self::assertSame([['page' => 1, 'page_size' => 100], ['page' => 2, 'page_size' => 100]], [$this->requests[2][2]['query'], $this->requests[3][2]['query']]);
	}

	/**
	 * The workflows end with their last page, once the pages held as many as Paperless
	 * counted.
	 */
	public function testTheWorkflowsEndWithTheirLastPage(): void {
		$pages = [
			1 => (string)json_encode(['count' => 102, 'results' => array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Workflow ' . $id], range(1, 100))]),
			2 => '{"count":102,"results":[{"id":101,"name":"Workflow 101"},{"id":102,"name":"Workflow 102"}]}',
		];
		$workflows = static fn (array $options): IResponse => self::answer(200, $pages[$options['query']['page']] ?? self::fail('Paperless was asked for a page after the last.'));

		$this->accountSetup($this->paperless(['GET /api/workflows/' => $workflows]))->create(self::URL, self::SUPERUSER_TOKEN, false, true, false, $this->saves());

		self::assertSame(['GET /api/workflows/', 'GET /api/workflows/', 'POST /api/users/'], array_slice($this->asked(), 2, 3));
	}

	/**
	 * The workflows end with their last page, or with an empty one, however many Paperless
	 * counted.
	 */
	public function testTheWorkflowsEndWithAnEmptyPage(): void {
		$pages = [
			1 => '{"count":5,"results":[{"id":1,"name":"Tag the invoices"}]}',
			2 => '{"count":5,"results":[]}',
		];
		$workflows = static fn (array $options): IResponse => self::answer(200, $pages[$options['query']['page']]);

		$this->accountSetup($this->paperless(['GET /api/workflows/' => $workflows]))->create(self::URL, self::SUPERUSER_TOKEN, false, true, false, $this->saves());

		self::assertSame(['GET /api/workflows/', 'GET /api/workflows/', 'POST /api/users/'], array_slice($this->asked(), 2, 3));
	}

	/**
	 * A refused token fails as the test of the connection fails, with the message that
	 * names its status, and so does a URL that answers but not as Paperless.
	 *
	 * @return array<string, array{array<string, IResponse|Throwable>, RequestFailure, ?int}>
	 */
	public static function failures(): array {
		return [
			'a refused token' => [['GET /api/ui_settings/' => self::answer(401, '{"detail":"Invalid token."}')], RequestFailure::Unauthorized, 401],
			'a redirect' => [['GET /api/ui_settings/' => self::answer(302, '')], RequestFailure::Redirect, 302],
			'no answer' => [['GET /api/ui_settings/' => new Exception('cURL error 28: Operation timed out')], RequestFailure::Timeout, null],
			'no JSON' => [['GET /api/ui_settings/' => self::answer(200, '<html>Sign in</html>')], RequestFailure::NotPaperless, null],
			'no object' => [['GET /api/ui_settings/' => self::answer(200, '"Paperless"')], RequestFailure::NotPaperless, null],
			'no user' => [['GET /api/ui_settings/' => self::answer(200, '{}')], RequestFailure::NotPaperless, null],
			'no list of users' => [['GET /api/users/' => self::answer(200, '{"detail":"none"}')], RequestFailure::NotPaperless, null],
			'no list of workflows' => [['GET /api/workflows/' => self::answer(200, '{"count":1}')], RequestFailure::NotPaperless, null],
			'workflows without their count' => [['GET /api/workflows/' => self::answer(200, '{"results":[]}')], RequestFailure::NotPaperless, null],
			'workflows that Paperless fails to list' => [['GET /api/workflows/' => self::answer(500, 'Server Error')], RequestFailure::Http, 500],
			'a user that Paperless refuses' => [['POST /api/users/' => self::answer(400, '{"username":["Enter a valid username."]}')], RequestFailure::Http, 400],
			'a user without an ID' => [['POST /api/users/' => self::answer(201, '{"username":"nextcloud-search"}')], RequestFailure::NotPaperless, null],
		];
	}

	/**
	 * @param array<string, IResponse|Throwable> $answers
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('failures')]
	public function testAFailureBeforeTheUserExistsLeavesPaperlessAsItWas(array $answers, RequestFailure $reason, ?int $status): void {
		try {
			$this->accountSetup($this->paperless($answers))->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());
			self::fail('The set-up succeeded.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame($reason, $exception->reason);
			self::assertSame($status, $exception->status);
			self::assertStringNotContainsString(self::SUPERUSER_TOKEN, $exception->getMessage());
		}

		self::assertNotContains('DELETE', array_column($this->requests, 0));
		self::assertNotContains('POST /api/workflows/', $this->asked());
	}

	public function testAnAnswerWithoutJsonKeepsItsError(): void {
		try {
			$this->accountSetup($this->paperless(['GET /api/ui_settings/' => self::answer(200, '<html>')]))->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());
			self::fail('The set-up succeeded.');
		} catch (PaperlessRequestException $exception) {
			self::assertInstanceOf(JsonException::class, $exception->getPrevious());
			self::assertSame('Paperless returned no JSON: Syntax error', $exception->getMessage());
		}
	}

	/**
	 * A list of Paperless without the IDs of everything in it ends the set-up, and the
	 * failure names the list.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function listsWithoutTheirIds(): array {
		return [
			'the documents' => ['GET /api/documents/', '{"count":1,"results":[]}', 'documents'],
			'the tags' => ['GET /api/tags/', '{"count":1,"all":["8"],"results":[]}', 'tags'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('listsWithoutTheirIds')]
	public function testAListWithoutItsIdsIsNamed(string $request, string $body, string $list): void {
		try {
			$this->accountSetup($this->paperless([$request => self::answer(200, $body)]))->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());
			self::fail('The set-up succeeded.');
		} catch (PaperlessRequestException $exception) {
			self::assertSame(RequestFailure::NotPaperless, $exception->reason);
			self::assertSame('Paperless listed no IDs of its ' . $list . '.', $exception->getMessage());
		}
	}

	/**
	 * Once Paperless has added the user, a step that fails removes it again, and the
	 * workflow once it exists, so that a second try starts afresh: the permissions that the
	 * user got go with it.
	 *
	 * @return array<string, array{array<string, IResponse|Throwable>, list<string>}>
	 */
	public static function failuresOfTheUser(): array {
		$removesTheUser = ['DELETE /api/users/7/'];
		$removesBoth = ['DELETE /api/workflows/4/', 'DELETE /api/users/7/'];

		return [
			'no token' => [['POST /api/token/' => self::answer(200, '{"token":" "}')], $removesTheUser],
			'a refused token' => [['POST /api/token/' => self::answer(400, '{"non_field_errors":["Unable to log in."]}')], $removesTheUser],
			'a refused workflow' => [['POST /api/workflows/' => self::answer(500, 'Server Error')], $removesTheUser],
			'documents without their IDs' => [['GET /api/documents/' => self::answer(200, '{"count":1,"results":[]}')], $removesBoth],
			'IDs that are no numbers' => [['GET /api/tags/' => self::answer(200, '{"count":1,"all":["8"],"results":[]}')], $removesBoth],
			'a refused bulk edit' => [['POST /api/bulk_edit_objects/' => self::answer(403, '{"detail":"Insufficient permissions"}')], $removesBoth],
			'a bulk edit without an answer' => [['POST /api/documents/bulk_edit/' => new Exception('cURL error 28: Operation timed out after 60000 milliseconds')], $removesBoth],
		];
	}

	/**
	 * @param array<string, IResponse|Throwable> $answers
	 * @param list<string> $removals
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('failuresOfTheUser')]
	public function testAFailureOnceTheUserExistsRemovesItAgain(array $answers, array $removals): void {
		$saves = 0;
		try {
			$this->accountSetup($this->paperless($answers))->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, static function () use (&$saves): PublicConfig {
				$saves++;

				return new PublicConfig(self::URL, true, false);
			});
			self::fail('The set-up succeeded.');
		} catch (PaperlessRequestException) {
		}

		self::assertSame(0, $saves);
		self::assertSame($removals, array_values(array_filter($this->asked(), static fn (string $request): bool => str_starts_with($request, 'DELETE '))));
		$removal = $this->requests[array_key_last($this->requests)][2];
		self::assertSame('Token ' . self::SUPERUSER_TOKEN, $removal['headers']['Authorization']);
		self::assertFalse($removal['allow_redirects']);
		self::assertArrayNotHasKey('delay', $removal);
	}

	/**
	 * When the test of the connection with the new token or saving the settings fails,
	 * nothing uses the account, so it goes too, and the failure of the save stays the one
	 * that the settings name.
	 */
	public function testAFailedSaveRemovesTheAccount(): void {
		$failure = new RuntimeException('The database is gone.');
		try {
			$this->accountSetup($this->paperless())->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, static fn (): PublicConfig => throw $failure);
			self::fail('The set-up succeeded.');
		} catch (RuntimeException $exception) {
			self::assertSame($failure, $exception);
		}

		self::assertSame(['DELETE /api/workflows/4/', 'DELETE /api/users/7/'], array_slice($this->asked(), -2));
	}

	/**
	 * A user or a workflow that Paperless no longer has is gone as well.
	 */
	public function testAnAccountThatIsGoneAlreadyCountsAsRemoved(): void {
		$this->expectException(PaperlessRequestException::class);

		$this->accountSetup($this->paperless([
			'POST /api/bulk_edit_objects/' => self::answer(500, ''),
			'DELETE /api/workflows/4/' => self::answer(404, '{"detail":"No Workflow matches the given query."}'),
			'DELETE /api/users/7/' => self::answer(404, '{"detail":"No User matches the given query."}'),
		]))->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());
	}

	/**
	 * A user or a workflow that can't be removed again stays in Paperless, and the failure
	 * names what stays, with the failure of the set-up as its cause; each removal is tried
	 * all the same.
	 *
	 * @return array<string, array{array<string, IResponse|Throwable>, bool, ?string}>
	 */
	public static function failedRemovals(): array {
		$workflow = 'Nextcloud search: view new documents';

		return [
			'a refused removal of the user' => [['DELETE /api/users/7/' => self::answer(403, '{"detail":"none"}')], true, null],
			'a removal of the user without an answer' => [['DELETE /api/users/7/' => new Exception('cURL error 7: Failed to connect')], true, null],
			'a refused removal of the workflow' => [['DELETE /api/workflows/4/' => self::answer(500, '')], false, $workflow],
			'refused removals of both' => [['DELETE /api/workflows/4/' => new Exception('cURL error 7: Failed to connect'), 'DELETE /api/users/7/' => self::answer(500, '')], true, $workflow],
		];
	}

	/**
	 * @param array<string, IResponse|Throwable> $answers
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('failedRemovals')]
	public function testWhatCannotBeRemovedIsNamed(array $answers, bool $userRemains, ?string $workflow): void {
		try {
			$this->accountSetup($this->paperless(['POST /api/documents/bulk_edit/' => self::answer(500, '')] + $answers))
				->create(self::URL, self::SUPERUSER_TOKEN, false, true, true, $this->saves());
			self::fail('The set-up succeeded.');
		} catch (SearchAccountUnfinished $exception) {
			self::assertSame('nextcloud-search', $exception->username);
			self::assertSame([$userRemains, $workflow], [$exception->userRemains, $exception->workflow]);
			$cause = $exception->getPrevious();
			self::assertInstanceOf(PaperlessRequestException::class, $cause);
			self::assertSame(500, $cause->status);
			self::assertStringNotContainsString(self::SUPERUSER_TOKEN, $exception->getMessage());
			self::assertSame(
				'The set-up of the search account nextcloud-search failed, and '
				. ($workflow === null ? 'the user nextcloud-search remains' : ($userRemains ? 'the user nextcloud-search and the workflow "' . $workflow . '" remain' : 'the workflow "' . $workflow . '" remains'))
				. ' in Paperless.',
				$exception->getMessage(),
			);
		}

		self::assertSame(['DELETE /api/workflows/4/', 'DELETE /api/users/7/'], array_slice($this->asked(), -2));
	}

	/**
	 * The answers of a Paperless 3.x to the set-up, with these instead.
	 *
	 * @param array<string, IResponse|Throwable|\Closure> $instead
	 * @return array<string, IResponse|Throwable|\Closure>
	 */
	private function paperless(array $instead = []): array {
		return $instead + [
			'GET /api/ui_settings/' => self::answer(200, self::SUPERUSER),
			'GET /api/users/' => self::answer(200, self::NO_USERS),
			'GET /api/workflows/' => self::answer(200, '{"count":1,"next":null,"previous":null,"all":[2],"results":[{"id":2,"name":"Tag the invoices"}]}'),
			'POST /api/users/' => self::answer(201, '{"id":7,"username":"nextcloud-search","is_superuser":false}'),
			'POST /api/token/' => self::answer(200, '{"token":" ' . self::ACCOUNT_TOKEN . '"}'),
			'POST /api/workflows/' => self::answer(201, '{"id":4,"name":"Nextcloud search: view new documents"}'),
			'GET /api/documents/' => self::answer(200, '{"count":3,"next":null,"all":[1,2,3],"results":[{"id":1}]}'),
			'POST /api/documents/bulk_edit/' => self::answer(200, self::OK),
			'GET /api/correspondents/' => self::answer(200, '{"count":1,"all":[5],"results":[]}'),
			'GET /api/document_types/' => self::answer(200, '{"count":0,"all":[],"results":[]}'),
			'GET /api/tags/' => self::answer(200, '{"count":2,"all":[8,9],"results":[]}'),
			'POST /api/bulk_edit_objects/' => self::answer(200, self::OK),
			'DELETE /api/workflows/4/' => self::answer(204, ''),
			'DELETE /api/users/7/' => self::answer(204, ''),
		];
	}

	/**
	 * @param array<string, IResponse|Throwable|\Closure> $answers by the method and the path of a request, or what answers its options
	 */
	private function accountSetup(array $answers): SearchAccountSetup {
		$client = $this->createStub(IClient::class);
		foreach (['get', 'post', 'delete'] as $verb) {
			$client->method($verb)->willReturnCallback(function (string $url, array $options) use ($verb, $answers): IResponse {
				self::assertStringStartsWith(self::URL . '/api/', $url);
				$request = strtoupper($verb) . ' ' . substr($url, strlen(self::URL));
				$this->requests[] = [strtoupper($verb), substr($url, strlen(self::URL)), $options];
				$answer = $answers[$request] ?? self::fail('Paperless got the unexpected request ' . $request . '.');
				if ($answer instanceof \Closure) {
					$answer = $answer($options);
				}
				if ($answer instanceof Throwable) {
					throw $answer;
				}

				// An answer of its own for each request, whose body goes once, as answer() says.
				return clone $answer;
			});
		}
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static function (int $length, string $characters): string {
			self::assertSame([64, ISecureRandom::CHAR_ALPHANUMERIC], [$length, $characters]);

			return self::PASSWORD;
		});
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.2.3');

		return new SearchAccountSetup($clientService, $random, $apps);
	}

	/**
	 * A save of the settings that works.
	 */
	private function saves(): \Closure {
		return static fn (string $token): PublicConfig => new PublicConfig(self::URL, $token !== '', false);
	}

	/**
	 * @return list<string> the method and the path of each request, in their order
	 */
	private function asked(): array {
		return array_map(static fn (array $request): string => $request[0] . ' ' . $request[1], $this->requests);
	}

	private function expectRefusal(SettingProblem $problem, \Closure $create, ?string $message = null): void {
		try {
			$create();
			self::fail('The set-up succeeded.');
		} catch (InvalidSettingException $exception) {
			self::assertSame($problem, $exception->problem);
			if ($message !== null) {
				self::assertSame($message, $exception->getMessage());
			}
		}
	}

	/**
	 * An answer of Paperless, which hands out its body once, as the client of Nextcloud
	 * reads the rest of the stream of the body for each getBody(): a second call gets an
	 * empty string, which the contract with a real Paperless met.
	 */
	private static function answer(int $status, string $body): IResponse {
		return new class($status, $body) implements IResponse {
			public function __construct(
				private int $status,
				private string $body,
			) {
			}

			public function getBody() {
				[$body, $this->body] = [$this->body, ''];

				return $body;
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
