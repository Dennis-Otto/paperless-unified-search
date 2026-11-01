<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use DateTimeImmutable;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The services of the app, as Nextcloud builds them, with its settings in memory and a
 * Paperless whose answers each test sets: for the tests of the commands of occ and of the
 * setup check, which use them all.
 */
trait AppServices {
	use AppSettings;

	/** @var list<array{string, array<string, mixed>}> the requests to Paperless so far, with their options */
	private array $requests = [];

	/**
	 * What Paperless answers to /api/ui_settings/, what the account of the token may do,
	 * or the error of that request: by default, that it may view documents,
	 * correspondents, document types and tags, and nothing else.
	 */
	private IResponse|Throwable|null $privileges = null;

	/** The headers with which Paperless-ngx 3.3.0 names its version. */
	private const VERSION_HEADERS = ['X-Version' => '3.3.0', 'X-Api-Version' => '10'];

	/**
	 * Settings of a Paperless that the app is connected to, with the archive account.
	 */
	private function connected(string $archiveOwner = 'archive', string $url = 'https://paperless.example.com'): void {
		$this->settings[AppConstants::APP_ID . '.paperless_url'] = $url;
		$this->settings[AppConstants::APP_ID . '.archive_owner'] = $archiveOwner;
		$this->storedToken = 'TEST_VALUE';
	}

	private function configService(IUserManager $accounts): ConfigService {
		return new ConfigService($this->appConfig(), $this->credentialsManager(), $accounts, $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));
	}

	private function diagnostics(): SearchDiagnostics {
		return new SearchDiagnostics($this->appConfig(), $this->clock(), $this->createStub(ICacheFactory::class));
	}

	private function paperlessApi(ConfigService $configService, IClient $client): PaperlessApiService {
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.5.1');

		return new PaperlessApiService($configService, $clientService, $this->diagnostics(), $this->clock(), $appManager);
	}

	/**
	 * A Paperless that gives these answers to the requests in turn, and throws an error
	 * instead of answering for a Throwable; it answers the question of the test of the
	 * connection for what the account of the token may do with $this->privileges. The
	 * requests land in $this->requests.
	 */
	private function paperless(IResponse|Throwable ...$answers): IClient {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$answers): IResponse {
			$this->requests[] = [$url, $options];
			$answer = str_ends_with($url, '/api/ui_settings/')
				? $this->privileges ?? $this->uiSettings(['view_document', 'view_correspondent', 'view_documenttype', 'view_tag'])
				: array_shift($answers);
			self::assertNotNull($answer, 'Paperless got more requests than the test expects.');
			if ($answer instanceof Throwable) {
				throw $answer;
			}

			return $answer;
		});

		return $client;
	}

	/**
	 * An answer of Paperless with the documents, the number of all documents that match,
	 * the IDs of these in the field all, unless null, and the version in its headers.
	 *
	 * @param list<array<string, mixed>> $documents
	 * @param ?list<int> $all
	 * @param array<string, string> $headers
	 */
	private function documents(array $documents, ?int $count = null, ?array $all = null, array $headers = self::VERSION_HEADERS): IResponse {
		$body = ['count' => $count ?? count($documents), 'next' => null, 'previous' => null, 'results' => $documents];
		if ($all !== null) {
			$body['all'] = $all;
		}

		return $this->answer(200, (string)json_encode($body), $headers);
	}

	/**
	 * The answer of /api/ui_settings/ for an account with these permissions, as Paperless
	 * names them, which is a superuser or staff when the flags say so.
	 *
	 * @param list<string> $permissions
	 */
	private function uiSettings(array $permissions, bool $superuser = false, bool $staff = false): IResponse {
		return $this->answer(200, (string)json_encode([
			'user' => ['id' => 3, 'username' => 'nextcloud', 'is_staff' => $staff, 'is_superuser' => $superuser, 'groups' => []],
			'settings' => [],
			'permissions' => $permissions,
		]));
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function answer(int $status, string $body, array $headers = self::VERSION_HEADERS): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');

		return $response;
	}

	/**
	 * The texts of the app as written, which is what occ shows in English.
	 */
	private function messages(): SettingsMessages {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new SettingsMessages($l10n);
	}

	private function clock(): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1791500000);
		$clock->method('now')->willReturn(new DateTimeImmutable('@1791500000'));

		return $clock;
	}
}
