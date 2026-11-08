<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCA\PaperlessUnifiedSearch\Service\SearchAccountSetup;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ISecureRandom;
use Throwable;

/**
 * A Paperless-ngx 3.3 for the set-up of the search account (decision 0012), at
 * SETUP_URL, which answers the token of a superuser as a superuser, and the token that it
 * gives the new account as an account that may view what the search reads, for the test of
 * the connection. It needs the traits AppServices, for the answers of the documents and of
 * the UI settings, and $this->requests.
 */
trait SearchAccountPaperless {
	/** @var array<string, IResponse|Throwable> what Paperless answers instead, by the method and the path of a request, and the token of the account where it has it */
	private array $instead = [];

	private const SETUP_URL = 'http://paperless:8000';
	private const SUPERUSER_TOKEN = 'SUPERUSER_VALUE_7f3a';
	private const ACCOUNT_TOKEN = 'ACCOUNT_VALUE_2c9d';

	/**
	 * The set-up of the search account with this Paperless and a random password.
	 */
	private function searchAccountSetup(IClient $client): SearchAccountSetup {
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn('RANDOM_PASSWORD_VALUE');
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.5.1');

		return new SearchAccountSetup($clientService, $random, $appManager);
	}

	/**
	 * The Paperless, with the answers of $this->instead by the method and the path of a
	 * request, and for a request with the token of the new account by its method, its path
	 * and that token. The requests land in $this->requests, named so, with their options.
	 */
	private function paperlessForTheSetUp(): IClient {
		$account = self::ACCOUNT_TOKEN;
		$answers = [
			'GET /api/ui_settings/' => $this->uiSettings(['add_user', 'view_document'], superuser: true, staff: true),
			'GET /api/users/' => self::reply(200, '{"count":0,"results":[]}'),
			'GET /api/workflows/' => self::reply(200, '{"count":0,"results":[]}'),
			'POST /api/users/' => self::reply(201, '{"id":7,"username":"nextcloud-search"}'),
			'POST /api/token/' => self::reply(200, '{"token":"' . $account . '"}'),
			'POST /api/workflows/' => self::reply(201, '{"id":4}'),
			'GET /api/documents/' => $this->documents([['id' => 1]], 2, [1, 2]),
			'POST /api/documents/bulk_edit/' => self::reply(200, '{"result":"OK"}'),
			'GET /api/correspondents/' => self::reply(200, '{"count":1,"all":[5],"results":[]}'),
			'GET /api/document_types/' => self::reply(200, '{"count":0,"all":[],"results":[]}'),
			'GET /api/tags/' => self::reply(200, '{"count":0,"all":[],"results":[]}'),
			'POST /api/bulk_edit_objects/' => self::reply(200, '{"result":"OK"}'),
			'DELETE /api/workflows/4/' => self::reply(204, ''),
			'DELETE /api/users/7/' => self::reply(204, ''),
			// The test of the connection with the new token, and what its account may do.
			'GET /api/documents/ ' . $account => $this->documents([['id' => 1]], 2, [1, 2]),
			'GET /api/ui_settings/ ' . $account => $this->uiSettings(SearchAccountSetup::PERMISSIONS),
		];
		$client = $this->createStub(IClient::class);
		foreach (['get', 'post', 'delete'] as $verb) {
			$client->method($verb)->willReturnCallback(function (string $url, array $options) use ($verb, $answers, $account): IResponse {
				self::assertStringStartsWith(self::SETUP_URL . '/api/', $url);
				$request = strtoupper($verb) . ' ' . substr($url, strlen(self::SETUP_URL));
				if (($options['headers']['Authorization'] ?? '') === 'Token ' . $account) {
					$request .= ' ' . $account;
				}
				$this->requests[] = [$request, $options];
				$answer = $this->instead[$request] ?? $answers[$request] ?? self::fail('Paperless got the unexpected request ' . $request . '.');
				if ($answer instanceof Throwable) {
					throw $answer;
				}

				return $answer;
			});
		}

		return $client;
	}

	/**
	 * An answer of Paperless without the headers of its version, also for data providers.
	 */
	private static function reply(int $status, string $body): IResponse {
		return new class($status, $body) implements IResponse {
			public function __construct(
				private int $status,
				private string $body,
			) {
			}

			public function getBody() {
				return $this->body;
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
