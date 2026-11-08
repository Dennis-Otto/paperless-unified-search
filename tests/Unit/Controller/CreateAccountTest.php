<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use Exception;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Controller\SettingsController;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessNames;
use OCA\PaperlessUnifiedSearch\Service\SearchExplainer;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Owners;
use OCA\PaperlessUnifiedSearch\Tests\Unit\SearchAccountPaperless;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Create the search account in Paperless, the route of the settings page (decision 0012),
 * with the services of the app, a Paperless that answers as the contract tests find it,
 * and the settings in memory.
 */
final class CreateAccountTest extends TestCase {
	use Accounts;
	use Owners;
	use AppServices;
	use SearchAccountPaperless;

	/** @var list<array{string, string, array<string, mixed>}> what the log got: its level, its message and its context */
	private array $log = [];

	/** @var list<CriticalActionPerformedEvent> what the audit log got */
	private array $audited = [];

	/**
	 * The account is created and the settings are saved with its token, as a save with
	 * that token saves them: its account may view what the search reads, so no warning
	 * shows. The answer is the settings, with what the set-up did, and the log and the
	 * audit log name who created the account.
	 */
	public function testItCreatesTheAccountAndSavesTheSettingsWithItsToken(): void {
		$this->settings['paperless_sync.target_user'] = 'archive';

		$response = $this->controller()->createAccount(self::SETUP_URL . '/', self::SUPERUSER_TOKEN, true, true, true, '', true, false, true);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'url' => self::SETUP_URL,
			'tokenConfigured' => true,
			'alwaysSearch' => true,
			'archiveOwner' => '',
			'syncAccount' => 'archive',
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
			'allowLocal' => true,
			'paperlessVersion' => '3.3.0',
			'paperlessApiVersion' => '10',
			'personalAlwaysSearch' => false,
			'tokenWarnings' => [],
			'newDocumentAlerts' => true,
			'perUserArchives' => false,
			'searchAccount' => ['username' => 'nextcloud-search', 'workflow' => true, 'documents' => 2, 'names' => 1],
		], $this->payload($response));
		self::assertSame(self::ACCOUNT_TOKEN, $this->storedToken);
		self::assertSame(self::SETUP_URL, $this->settings[AppConstants::APP_ID . '.paperless_url']);
		// The test of the connection used the new token, the set-up the one of the superuser.
		$probes = array_filter($this->requests, static fn (array $request): bool => $request[0] === 'GET /api/documents/ ' . self::ACCOUNT_TOKEN);
		// The documents, then the names of the newest ones (PaperlessApiService::probe()).
		self::assertCount(2, $probes);
		self::assertSame([['info', '{admin} created the search account {account} in Paperless', ['app' => AppConstants::APP_ID, 'admin' => 'alex', 'account' => 'nextcloud-search']]], $this->log);
		self::assertCount(1, $this->audited);
		self::assertSame('Paperless Unified Search: %s created the search account %s in Paperless', $this->audited[0]->getLogMessage());
		self::assertSame(['admin' => 'alex', 'account' => 'nextcloud-search'], $this->audited[0]->getParameters());
	}

	/**
	 * Without a choice of the form, the account may view each new document and what
	 * Paperless has, and users keep what the settings let them do.
	 */
	public function testItLetsTheAccountViewEverythingByDefault(): void {
		$this->settings[AppConstants::APP_ID . '.personal_always_search'] = false;

		$response = $this->controller()->createAccount(self::SETUP_URL, self::SUPERUSER_TOKEN, allowLocal: true);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$payload = $this->payload($response);
		self::assertSame(['username' => 'nextcloud-search', 'workflow' => true, 'documents' => 2, 'names' => 1], $payload['searchAccount']);
		self::assertFalse($payload['personalAlwaysSearch']);
		self::assertFalse($payload['alwaysSearch']);
	}

	/**
	 * A URL or an archive account that the settings can't save changes nothing in
	 * Paperless: the set-up doesn't start.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function invalidSettings(): array {
		return [
			'no URL' => ['', 'archive', 'invalid_url', 'Enter a valid Paperless URL.'],
			'a URL with a query' => [self::SETUP_URL . '/?next=1', 'archive', 'url_extras', 'The Paperless URL must not contain credentials, a query, or a fragment.'],
			'an unknown archive account' => [self::SETUP_URL, 'nobody', 'unknown_archive_account', 'The archive account nobody does not exist.'],
		];
	}

	#[DataProvider('invalidSettings')]
	public function testSettingsThatCantBeSavedChangeNothingInPaperless(string $url, string $archiveOwner, string $code, string $message): void {
		$response = $this->controller()->createAccount($url, self::SUPERUSER_TOKEN, archiveOwner: $archiveOwner, allowLocal: true);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => $message, 'code' => $code], $this->payload($response));
		self::assertSame([], $this->requests);
		self::assertNull($this->storedToken);
	}

	/**
	 * What keeps the set-up from starting, or makes it fail, has a message that says what
	 * to do, and a code; nothing is saved.
	 *
	 * @return array<string, array{string, array<string, IResponse|Throwable>, string, string}>
	 */
	public static function refusals(): array {
		$viewer = '{"user":{"id":3,"username":"nextcloud","is_staff":false,"is_superuser":false,"groups":[]},"settings":{},"permissions":["view_document"]}';

		return [
			'no token' => [' ', [], 'superuser_token_required', 'Enter the API token of a superuser of Paperless. It sets up the search account and is not stored.'],
			'the token of another account' => [self::SUPERUSER_TOKEN, ['GET /api/ui_settings/' => self::reply(200, $viewer)], 'not_superuser', 'This API token belongs to no superuser of Paperless, so it can\'t set up the search account. Enter the token of a superuser; it is not stored.'],
			'a user of the name' => [self::SUPERUSER_TOKEN, ['GET /api/users/' => self::reply(200, '{"count":1,"results":[{"id":9,"username":"nextcloud-search"}]}')], 'search_account_exists', 'The user nextcloud-search exists in Paperless already, and stays as it is. Save the settings with its API token, or delete the user in Paperless and try again.'],
			'a refused token' => [self::SUPERUSER_TOKEN, ['GET /api/ui_settings/' => self::reply(401, '{"detail":"Invalid token."}')], 'unauthorized', 'Paperless rejected the API token (HTTP 401). Check the token, and that its account may view documents.'],
			'a refused workflow' => [self::SUPERUSER_TOKEN, ['POST /api/workflows/' => self::reply(500, '')], 'http', 'Paperless answered with an error (HTTP 500). Check its log, or try again later.'],
			'a user that stays' => [self::SUPERUSER_TOKEN, ['POST /api/workflows/' => self::reply(500, ''), 'DELETE /api/users/7/' => self::reply(500, '')], 'search_account_left', 'Paperless did not finish setting up the search account, and the user nextcloud-search remains in it. Delete the user in Paperless, then try again.'],
			'a workflow of the name' => [self::SUPERUSER_TOKEN, ['GET /api/workflows/' => self::reply(200, '{"count":1,"results":[{"id":4,"name":"Nextcloud search: view new documents"}]}')], 'search_workflow_exists', 'The workflow “Nextcloud search: view new documents” exists in Paperless already, and stays as it is. Delete it in Paperless and try again, or turn off “Let it view each new document”.'],
			'a workflow that stays' => [self::SUPERUSER_TOKEN, ['POST /api/bulk_edit_objects/' => self::reply(500, ''), 'DELETE /api/workflows/4/' => self::reply(500, '')], 'search_account_left', 'Paperless did not finish setting up the search account, and the workflow “Nextcloud search: view new documents” remains in it. Delete the workflow in Paperless, then try again.'],
			'a user and a workflow that stay' => [self::SUPERUSER_TOKEN, ['POST /api/bulk_edit_objects/' => self::reply(500, ''), 'DELETE /api/workflows/4/' => self::reply(500, ''), 'DELETE /api/users/7/' => self::reply(500, '')], 'search_account_left', 'Paperless did not finish setting up the search account, and the user nextcloud-search and the workflow “Nextcloud search: view new documents” remain in it. Delete both in Paperless, then try again.'],
		];
	}

	/**
	 * @param array<string, IResponse|Throwable> $answers
	 */
	#[DataProvider('refusals')]
	public function testARefusalSaysWhatToDoAndSavesNothing(string $token, array $answers, string $code, string $message): void {
		$this->instead = $answers;

		$response = $this->controller()->createAccount(self::SETUP_URL, $token, allowLocal: true);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => $message, 'code' => $code], $this->payload($response));
		self::assertNull($this->storedToken);
		self::assertArrayNotHasKey(AppConstants::APP_ID . '.paperless_url', $this->settings);
		self::assertSame([], $this->audited);
		// A failure in Paperless goes into the log with the code of the refusal.
		$warnings = array_values(array_filter($this->log, static fn (array $entry): bool => $entry[0] === 'warning'));
		self::assertSame(in_array($code, ['http', 'unauthorized', 'search_account_left'], true) ? [$code] : [], array_map(static fn (array $entry): string => $entry[2]['reason'], $warnings));
	}

	/**
	 * A test of the connection with the new token that fails names its cause, as a save
	 * does, and the account goes again.
	 */
	public function testAFailedTestOfTheNewTokenRemovesTheAccount(): void {
		$this->instead = ['GET /api/documents/ ' . self::ACCOUNT_TOKEN => self::reply(503, 'Service Unavailable')];

		$response = $this->controller()->createAccount(self::SETUP_URL, self::SUPERUSER_TOKEN, allowLocal: true);

		self::assertSame(['message' => 'Paperless answered with an error (HTTP 503). Check its log, or try again later.', 'code' => 'http'], $this->payload($response));
		self::assertContains('DELETE /api/users/7/', array_column($this->requests, 0));
		self::assertNull($this->storedToken);
	}

	/**
	 * What fails beyond Paperless, such as the store of the credentials, answers that the
	 * log has the details, and the account goes again.
	 */
	public function testAnInternalFailureAnswersThatTheLogHasTheDetails(): void {
		$response = $this->controller(storeFails: true)->createAccount(self::SETUP_URL, self::SUPERUSER_TOKEN, allowLocal: true);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['message' => 'The settings could not be saved. The log of Nextcloud has the details.', 'code' => 'internal'], $this->payload($response));
		self::assertContains('DELETE /api/users/7/', array_column($this->requests, 0));
		self::assertSame('warning', $this->log[0][0]);
		self::assertSame('The search account could not be set up in Paperless: {reason} ({errorType}: {detail})', $this->log[0][1]);
		self::assertSame('internal', $this->log[0][2]['reason']);
		self::assertSame(RuntimeException::class, $this->log[0][2]['errorType']);
		self::assertSame('The database is gone.', $this->log[0][2]['detail']);
	}

	/**
	 * The token of the superuser serves the set-up alone: it reaches no log, no answer,
	 * no setting and no stored credential, also where an error of a request repeats it,
	 * whether the set-up works or fails at any step.
	 *
	 * @return array<string, array{array<string, IResponse|Throwable>}>
	 */
	public static function anyOutcome(): array {
		$repeated = 'cURL error 28: Operation timed out for http://paperless:8000/api/users/ with Authorization: Token ' . self::SUPERUSER_TOKEN;

		return [
			'a set-up that works' => [[]],
			'no answer that repeats the token' => [['GET /api/ui_settings/' => new Exception($repeated)]],
			'no answer to a step' => [['POST /api/documents/bulk_edit/' => new Exception($repeated)]],
			'no answer to the removal' => [['POST /api/bulk_edit_objects/' => new Exception($repeated), 'DELETE /api/workflows/4/' => new Exception($repeated)]],
			'a refusal that repeats the token' => [['POST /api/users/' => self::reply(400, '{"detail":"Token ' . self::SUPERUSER_TOKEN . '"}')]],
			'a test of the new token without an answer' => [['GET /api/documents/ ' . self::ACCOUNT_TOKEN => new Exception($repeated)]],
		];
	}

	/**
	 * @param array<string, IResponse|Throwable> $answers
	 */
	#[DataProvider('anyOutcome')]
	public function testTheTokenOfTheSuperuserReachesNoLogNoAnswerAndNoSetting(array $answers): void {
		$this->instead = $answers;

		$response = $this->controller()->createAccount(self::SETUP_URL, self::SUPERUSER_TOKEN, allowLocal: true);

		$seen = (string)json_encode([$response->render(), $this->log, $this->settings, $this->storedToken, array_map(static fn (CriticalActionPerformedEvent $event): array => $event->getParameters(), $this->audited)]);
		self::assertStringNotContainsString(self::SUPERUSER_TOKEN, $seen);
		self::assertStringNotContainsString('SUPERUSER_VALUE', $seen);
		// The new token is in the stored credential alone, once the set-up worked.
		self::assertStringNotContainsString(self::ACCOUNT_TOKEN, (string)json_encode([$response->render(), $this->log, $this->settings]));
		// Only the requests of the set-up carry the token of the superuser.
		foreach ($this->requests as [$request, $options]) {
			$isSetUp = !str_ends_with($request, ' ' . self::ACCOUNT_TOKEN) && $request !== 'POST /api/token/';
			self::assertSame($isSetUp, ($options['headers']['Authorization'] ?? '') === 'Token ' . self::SUPERUSER_TOKEN, $request);
		}
	}

	/**
	 * The route as Nextcloud builds it, with the services of the app and a Paperless 3.3
	 * that answers the token of the superuser as a superuser and the token of the new
	 * account as an account that may view what the search reads.
	 */
	private function controller(bool $storeFails = false): SettingsController {
		$accounts = $this->accounts('archive', 'alex');
		$configService = $storeFails
			? new ConfigService($this->appConfig(), $this->failingCredentials(), $accounts, $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class))
			: $this->configService($accounts);
		$client = $this->paperlessForTheSetUp();
		$api = $this->paperlessApi($configService, $client);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($accounts->get('alex'));
		$events = $this->createStub(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			self::assertInstanceOf(CriticalActionPerformedEvent::class, $event);
			$this->audited[] = $event;
		});
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$logger = new class($this->log) extends AbstractLogger {
			/** @param list<array{string, string, array<string, mixed>}> $log */
			public function __construct(
				private array &$log,
			) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->log[] = [(string)$level, (string)$message, $context];
			}
		};

		return new SettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			$api,
			$this->diagnostics(),
			$this->messages(),
			$l10n,
			$logger,
			new SearchExplainer(new NextcloudFileLocator($this->createStub(IRootFolder::class), $logger), $accounts, $this->documentOwners($api, $configService, $accounts)),
			$accounts,
			$session,
			$events,
			new PaperlessNames($api, $configService, $this->createStub(ICacheFactory::class), $logger),
			$this->searchAccountSetup($client),
		);
	}

	private function failingCredentials(): ICredentialsManager {
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('store')->willThrowException(new RuntimeException('The database is gone.'));

		return $credentials;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(JSONResponse $response): array {
		$payload = json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($payload);

		return $payload;
	}
}
