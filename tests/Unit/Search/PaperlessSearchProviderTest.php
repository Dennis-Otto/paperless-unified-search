<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PaperlessSearchProviderTest extends TestCase {
	#[DataProvider('resourceUrlCases')]
	public function testReturnsOnlyAccessibleFilesWithPlatformCompatibleResourceUrl(
		string $userAgent,
		string $expectedResourceUrl,
	): void {
		$config = $this->settings('archive');

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode([
			'count' => 2,
			'next' => null,
			'results' => [
				[
					'id' => 123,
					'title' => 'Salary July 2026',
					'created' => '2026-07-31',
					'__search_hit__' => ['highlights' => '<span>Gross 5,000 EUR</span>'],
				],
				[
					'id' => 999,
					'title' => 'Not shared with this user',
				],
			],
		], JSON_THROW_ON_ERROR));

		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static function (array $options): bool {
					return $options['headers']['Authorization'] === 'Token TEST_VALUE'
						&& $options['headers']['User-Agent'] === 'Nextcloud-Paperless-Unified-Search/0.1'
						&& $options['query']['query'] === 'gross salary';
				}),
			)
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('2026-07-31 - Salary [P123].pdf');
		$file->method('getPath')->willReturn('/dennis/files/Paperless/Salary [P123].pdf');
		$file->method('getId')->willReturn(4711);
		$file->method('getOwner')->willReturn($this->account('archive'));

		$folder = $this->createMock(Folder::class);
		$folder->method('search')->willReturnCallback(
			static fn (string $marker): array => $marker === '[P123]' ? [$file] : [],
		);
		$folder->method('getRelativePath')
			->with('/dennis/files/Paperless/Salary [P123].pdf')
			->willReturn('/Paperless/Salary [P123].pdf');

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('dennis')->willReturn($folder);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->with(AppConstants::APP_ID, 'app.svg')
			->willReturn('/apps/paperless_unified_search/img/app.svg');
		$urlGenerator->expects(self::once())
			->method('linkToRouteAbsolute')
			->with('files.view.showFile', ['fileid' => 4711])
			->willReturn('https://cloud.example.com/f/4711');

		$request = $this->createMock(IRequest::class);
		$request->expects(self::once())
			->method('getHeader')
			->with('User-Agent')
			->willReturn($userAgent);

		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('gross salary');
		$query->method('getLimit')->willReturn(10);
		$query->method('getCursor')->willReturn(null);

		$configService = new ConfigService($config, $credentials);
		$provider = new PaperlessSearchProvider(
			new PaperlessApiService($configService, $clientService),
			new NextcloudFileLocator($root),
			$l10n,
			$urlGenerator,
			$request,
			$this->createMock(LoggerInterface::class),
			$configService,
		);

		self::assertTrue($provider->isExternalProvider());
		$result = $provider->search($user, $query)->jsonSerialize();

		self::assertFalse($result['isPaginated']);
		self::assertCount(1, $result['entries']);
		self::assertSame('Salary July 2026', $result['entries'][0]->jsonSerialize()['title']);
		self::assertSame($expectedResourceUrl, $result['entries'][0]->jsonSerialize()['resourceUrl']);
		self::assertSame([
			'fileId' => '4711',
			'path' => '/Paperless/Salary [P123].pdf',
		], $result['entries'][0]->jsonSerialize()['attributes']);
		self::assertSame('2026-07-31 · Gross 5,000 EUR', $result['entries'][0]->jsonSerialize()['subline']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function resourceUrlCases(): array {
		return [
			'browser' => [
				'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15',
				'https://cloud.example.com/f/4711',
			],
			'nextcloud iOS' => [
				'Mozilla/5.0 (iOS) Nextcloud-iOS/7.1.0',
				'nextcloud://open-file?user=dennis&link=https%3A%2F%2Fcloud.example.com%2Ff%2F4711',
			],
			'nextcloud Android' => [
				'Mozilla/5.0 (Android) Nextcloud-android/20260390',
				'https://cloud.example.com/f/4711',
			],
		];
	}

	public function testTrustedPaperlessIsNotGatedByConnectedServicesSwitch(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueBool')
			->with(AppConstants::APP_ID, 'always_search', false)
			->willReturn(true);

		$configService = new ConfigService($config, $this->createStub(ICredentialsManager::class));
		$provider = new PaperlessSearchProvider(
			new PaperlessApiService($configService, $this->createStub(IClientService::class)),
			new NextcloudFileLocator($this->createStub(IRootFolder::class)),
			$this->createStub(IL10N::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(IRequest::class),
			$this->createStub(LoggerInterface::class),
			$configService,
		);

		self::assertFalse($provider->isExternalProvider());
	}

	public function testDescribesItselfToTheUnifiedSearch(): void {
		$provider = $this->provider($this->createStub(IClient::class));

		self::assertSame(AppConstants::APP_ID . '_documents', $provider->getId());
		self::assertSame('Paperless documents', $provider->getName());
		self::assertSame(40, $provider->getOrder('files.view.index', []));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function searchesWithoutPaperless(): array {
		return [
			'blank term' => [" \t ", 'TEST_VALUE'],
			'no token' => ['invoice', ''],
		];
	}

	#[DataProvider('searchesWithoutPaperless')]
	public function testAsksPaperlessOnlyForATermWithCompleteSettings(string $term, string $token): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$result = $this->provider($client, token: $token)->search($this->user(), $this->query($term))->jsonSerialize();

		self::assertSame('Paperless documents', $result['name']);
		self::assertFalse($result['isPaginated']);
		self::assertSame([], $result['entries']);
	}

	/**
	 * @return array<string, array{int|string|null, int, int, int}>
	 */
	public static function pages(): array {
		return [
			'first search' => [null, 10, 1, 10],
			'cursor of the next page' => [3, 10, 3, 10],
			'cursor below one' => [0, 10, 1, 10],
			'negative cursor' => [-2, 10, 1, 10],
			'cursor as text' => ['4', 10, 4, 10],
			'cursor zero as text' => ['0', 10, 1, 10],
			'negative cursor as text' => ['-3', 10, 1, 10],
			'cursor that is no number' => ['next', 10, 1, 10],
			'limit above fifty' => [null, 100, 1, 50],
			'limit below one' => [null, 0, 1, 1],
		];
	}

	#[DataProvider('pages')]
	public function testTheCursorAndTheLimitChooseThePageOfPaperless(int|string|null $cursor, int $limit, int $page, int $pageSize): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static fn (array $options): bool => $options['query'] === [
					'query' => 'invoice',
					'page' => $page,
					'page_size' => $pageSize,
				]),
			)
			->willReturn($this->response(['count' => 0, 'next' => null, 'results' => []]));

		$this->provider($client)->search($this->user(), $this->query(' invoice ', $cursor, $limit));
	}

	/**
	 * @return array<string, array{int|string|null, int}>
	 */
	public static function nextPages(): array {
		return [
			'after the first page' => [null, 2],
			'after a later page' => [5, 6],
			'after a later page as text' => ['2', 3],
			'after a cursor below one' => [0, 2],
			'after a cursor zero as text' => ['0', 2],
		];
	}

	#[DataProvider('nextPages')]
	public function testMoreDocumentsAtPaperlessContinueOnTheNextPage(int|string|null $cursor, int $nextCursor): void {
		$client = $this->paperless([
			'count' => 61,
			'next' => 'https://paperless.example.com/api/documents/?page=3&query=invoice',
			'results' => [['id' => 7, 'title' => 'Invoice']],
		]);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice', $cursor))
			->jsonSerialize();

		self::assertTrue($result['isPaginated']);
		self::assertSame($nextCursor, $result['cursor']);
		self::assertCount(1, $result['entries']);
	}

	public function testAFailedSearchIsLoggedWithoutItsDetails(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('cURL error 7 for TEST_VALUE and invoice'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
			]);

		$result = $this->provider($client, logger: $logger)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertFalse($result['isPaginated']);
		self::assertSame([], $result['entries']);
	}

	public function testDocumentsWithoutAUsableIdAreLeftOut(): void {
		$client = $this->paperless([
			'count' => 5,
			'next' => null,
			'results' => [
				['title' => 'No id'],
				['id' => null, 'title' => 'Empty id'],
				['id' => 'P7', 'title' => 'Id that is no number'],
				['id' => 0, 'title' => 'Id zero'],
				['id' => '7', 'title' => 'Id as text'],
			],
		]);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertCount(1, $result['entries']);
		self::assertSame('Id as text', $result['entries'][0]->jsonSerialize()['title']);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string, string}>
	 */
	public static function documents(): array {
		$file = 'Scan [P7].pdf';

		return [
			'title and date' => [['title' => ' Invoice ', 'created' => '2026-05-01'], 'Invoice', '2026-05-01 · ' . $file],
			'blank title' => [['title' => '  '], $file, $file],
			'title that is no text' => [['title' => 42], $file, $file],
			'empty date' => [['title' => 'Invoice', 'created' => ''], 'Invoice', $file],
			'date that is no text' => [['title' => 'Invoice', 'created' => 20260501], 'Invoice', $file],
			'search hit that is no object' => [['title' => 'Invoice', '__search_hit__' => 'invoice'], 'Invoice', $file],
			'search hit without highlights' => [['title' => 'Invoice', '__search_hit__' => ['score' => 1.5]], 'Invoice', $file],
			'highlights that are no text' => [['title' => 'Invoice', '__search_hit__' => ['highlights' => 42]], 'Invoice', $file],
			'highlights of markup only' => [['title' => 'Invoice', '__search_hit__' => ['highlights' => '<span></span>']], 'Invoice', $file],
			'list of highlights' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => ['<b>Invoice</b> 42', 7, 'paid']]],
				'Invoice',
				'Invoice 42 paid',
			],
			'entities and whitespace' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => "Müller &amp; Co\n\t KG"]],
				'Invoice',
				'Müller & Co KG',
			],
			'long highlight' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => str_repeat('a', 200)]],
				'Invoice',
				str_repeat('a', 179) . '…',
			],
		];
	}

	/**
	 * @param array<string, mixed> $document
	 */
	#[DataProvider('documents')]
	public function testTitleAndSublineComeFromTheDocument(array $document, string $title, string $subline): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7] + $document]]);

		$result = $this->provider($client, [7 => $this->file(7, 'Scan [P7].pdf')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertCount(1, $result['entries']);
		$entry = $result['entries'][0]->jsonSerialize();
		self::assertSame($title, $entry['title']);
		self::assertSame($subline, $entry['subline']);
		self::assertSame('https://cloud.example.com/f/1007', $entry['resourceUrl']);
		self::assertSame(['fileId' => '1007', 'path' => '/Paperless/Scan [P7].pdf'], $entry['attributes']);
	}

	public function testWithoutAnArchiveAccountPaperlessIsNotAsked(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')], archiveOwner: '')
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
	}

	/**
	 * A file that only carries the marker in its name, such as one the searching user named
	 * so, stands for no document.
	 */
	public function testAFileOfAnotherAccountShowsNoDocument(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Payslip']]]);

		$result = $this->provider($client, [7 => $this->file(7, 'x [P7].txt', 'dennis')])
			->search($this->user(), $this->query('payslip'))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
	}

	public function testTheAccountOfPaperlessSyncIsTheArchiveAccountByDefault(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf', 'sync')], archiveOwner: '', syncAccount: 'sync')
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertCount(1, $result['entries']);
	}

	/**
	 * Builds the provider on a Paperless behind the given client and a user who can see the given files.
	 *
	 * @param array<int, File> $files by Paperless document id
	 */
	private function provider(
		IClient $client,
		array $files = [],
		string $token = 'TEST_VALUE',
		?LoggerInterface $logger = null,
		string $archiveOwner = 'archive',
		string $syncAccount = '',
	): PaperlessSearchProvider {
		$config = $this->settings($archiveOwner, $syncAccount);

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn($token);

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$folder = $this->createStub(Folder::class);
		$folder->method('search')->willReturnCallback(static function (string $marker) use ($files): array {
			foreach ($files as $documentId => $file) {
				if ($marker === '[P' . $documentId . ']') {
					return [$file];
				}
			}

			return [];
		});
		$folder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): string => substr($path, strlen('/dennis/files')),
		);

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/paperless_unified_search/img/app.svg');
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => 'https://cloud.example.com/f/' . $parameters['fileid'],
		);

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0');

		$configService = new ConfigService($config, $credentials);

		return new PaperlessSearchProvider(
			new PaperlessApiService($configService, $clientService),
			new NextcloudFileLocator($root),
			$l10n,
			$urlGenerator,
			$request,
			$logger ?? $this->createStub(LoggerInterface::class),
			$configService,
		);
	}

	/**
	 * @param array<string, mixed> $answer
	 */
	private function paperless(array $answer): IClient {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->response($answer));

		return $client;
	}

	/**
	 * @param array<string, mixed> $answer
	 */
	private function response(array $answer): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode($answer, JSON_THROW_ON_ERROR));

		return $response;
	}

	private function file(int $documentId, string $name, string $owner = 'archive'): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/dennis/files/Paperless/' . $name);
		$file->method('getId')->willReturn(1000 + $documentId);
		$file->method('getOwner')->willReturn($this->account($owner));

		return $file;
	}

	private function account(string $uid): IUser {
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn($uid);

		return $account;
	}

	/**
	 * The settings of a Paperless with a token, by app and key.
	 */
	private function settings(string $archiveOwner, string $syncAccount = ''): IAppConfig {
		$values = [
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.archive_owner' => $archiveOwner,
			'paperless_sync.target_user' => $syncAccount,
		];
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $values[$app . '.' . $key] ?? '',
		);

		return $config;
	}

	private function user(): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		return $user;
	}

	private function query(string $term, int|string|null $cursor = null, int $limit = 10): ISearchQuery {
		$query = $this->createStub(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getCursor')->willReturn($cursor);
		$query->method('getLimit')->willReturn($limit);

		return $query;
	}
}
