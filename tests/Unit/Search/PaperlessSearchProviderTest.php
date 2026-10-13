<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\FileCache;
use OCP\AppFramework\Utility\ITimeFactory;
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
use OCP\Search\SearchResultEntry;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PaperlessSearchProviderTest extends TestCase {
	use Accounts;
	use FileCache;

	private const NOW = 1791500000;

	/** @var array<string, mixed> the values that the app stored, by key */
	private array $stored = [];

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
						&& $options['query']['query'] === 'gross salary'
						&& $options['query']['fields'] === 'id,title,created,correspondent,document_type,__search_hit__';
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

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('dennis')->willReturn($this->userFolder([$file]));

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

		$configService = new ConfigService($config, $credentials, $this->accounts('archive'), $this->createStub(LoggerInterface::class));
		$provider = new PaperlessSearchProvider(
			new PaperlessApiService($configService, $clientService, new SearchDiagnostics($config, $this->clock())),
			new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)),
			$l10n,
			$urlGenerator,
			$request,
			$this->createMock(LoggerInterface::class),
			$configService,
			new SearchDiagnostics($config, $this->clock()),
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

		$configService = new ConfigService($config, $this->createStub(ICredentialsManager::class), $this->accounts(), $this->createStub(LoggerInterface::class));
		$provider = new PaperlessSearchProvider(
			new PaperlessApiService($configService, $this->createStub(IClientService::class), new SearchDiagnostics($config, $this->clock())),
			new NextcloudFileLocator($this->createStub(IRootFolder::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(IL10N::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(IRequest::class),
			$this->createStub(LoggerInterface::class),
			$configService,
			new SearchDiagnostics($config, $this->clock()),
		);

		self::assertFalse($provider->isExternalProvider());
	}

	public function testDescribesItselfToTheUnifiedSearch(): void {
		$provider = $this->provider($this->createStub(IClient::class));

		self::assertSame(AppConstants::APP_ID . '_documents', $provider->getId());
		self::assertSame(PaperlessSearchProvider::ID, $provider->getId());
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
					'fields' => 'id,title,created,correspondent,document_type,__search_hit__',
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

	public function testAFailedRequestToPaperlessGoesIntoTheHistory(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException(
			'cURL error 28: Connection timed out after 3001 milliseconds for https://paperless.example.com/api/documents/?query=invoice&page=1',
		));

		$result = $this->provider($client)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertSame([], $result['entries']);
		$history = $this->history();
		self::assertSame(1, $history->failed);
		self::assertSame(0, $history->retried);
		self::assertSame(self::NOW, $history->lastFailureAt);
		self::assertCount(1, $history->events);
		$event = $history->events[0];
		self::assertSame(self::NOW, $event->time);
		self::assertSame(SearchEvent::KIND_FAILED, $event->kind);
		self::assertSame(SearchEvent::STEP_PAPERLESS, $event->step);
		self::assertSame('RuntimeException', $event->error);
		self::assertSame('cURL error 28: Connection timed out after 3001 milliseconds for https://paperless.example.com/api/documents/', $event->message);
		self::assertGreaterThanOrEqual(0, $event->durationMs);
	}

	public function testAFailedSearchLeavesOutTheTokenAndTheTerm(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('Token TEST_VALUE refused for Invoice'));

		$this->provider($client)->search($this->user(), $this->query(' invoice '));

		self::assertSame('Token … refused for …', $this->history()->events[0]->message);
	}

	public function testARequestThatOnlyItsSecondTryAnswersGoesIntoTheHistory(): void {
		$answer = $this->response(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);
		$calls = 0;
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(static function () use (&$calls, $answer): IResponse {
			if (++$calls === 1) {
				throw new RuntimeException('cURL error 6: Could not resolve host: paperless.example.com for https://paperless.example.com/api/documents/?query=invoice');
			}

			return $answer;
		});

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertCount(1, $result['entries']);
		$history = $this->history();
		self::assertSame(0, $history->failed);
		self::assertSame(1, $history->retried);
		self::assertSame(SearchEvent::KIND_RETRIED, $history->events[0]->kind);
		self::assertSame(SearchEvent::STEP_PAPERLESS, $history->events[0]->step);
		self::assertSame('cURL error 6: Could not resolve host: paperless.example.com for https://paperless.example.com/api/documents/', $history->events[0]->message);
	}

	public function testAFailedLookupOfTheFilesGoesIntoTheHistoryAsSuch(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('search')->willThrowException(new RuntimeException('Storage unavailable'));

		$result = $this->provider($client, userFolder: $userFolder)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertSame(SearchEvent::STEP_FILES, $this->history()->events[0]->step);
		self::assertSame('Storage unavailable', $this->history()->events[0]->message);
	}

	/**
	 * Nextcloud's web search asks for 5 results and for 5 more with every "Load more", its
	 * apps for more at once: a search of the file cache per document scanned the names of
	 * all files of the user once per result.
	 */
	public function testTheFilesOfAPageAreFoundInOneSearch(): void {
		$client = $this->paperless(['count' => 4, 'next' => null, 'results' => [
			['id' => 12, 'title' => 'Payslip'],
			['id' => 7, 'title' => 'Invoice'],
			['id' => 999, 'title' => 'Not shared with this user'],
			['id' => 1, 'title' => 'Contract'],
		]]);
		$files = [
			1 => $this->file(1, 'Contract [P1].pdf'),
			7 => $this->file(7, 'Invoice [P7].pdf'),
			12 => $this->file(12, 'Payslip [P12].pdf'),
		];

		$result = $this->provider($client, $files)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertCount(1, $this->searches);
		self::assertSame(
			['Payslip', 'Invoice', 'Contract'],
			array_map(static fn (SearchResultEntry $entry): string => $entry->jsonSerialize()['title'], $result['entries']),
		);
	}

	/**
	 * A file that fails to answer costs only its own entry, and the log says so once,
	 * without the names of the files.
	 */
	public function testAResultThatFailsCostsOnlyItsOwnEntry(): void {
		$client = $this->paperless(['count' => 3, 'next' => null, 'results' => [
			['id' => 7, 'title' => 'Invoice'],
			['id' => 8, 'title' => 'Receipt'],
			['id' => 9, 'title' => 'Letter'],
		]]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search left out {count} result(s) it could not show ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 2,
				'errorType' => RuntimeException::class,
			]);

		$result = $this->provider($client, [
			7 => $this->brokenFile('Invoice [P7].pdf'),
			8 => $this->file(8, 'Receipt [P8].pdf'),
			9 => $this->brokenFile('Letter [P9].pdf'),
		], logger: $logger)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertCount(1, $result['entries']);
		self::assertSame('Receipt', $result['entries'][0]->jsonSerialize()['title']);
		// The search worked, so the history of its problems stays as it is.
		self::assertSame([], $this->stored);
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function failingFiles(): array {
		return [
			'when the file locator reads them' => [true],
			'when their entries are built' => [false],
		];
	}

	/**
	 * When every file that the user could open fails to answer, as all files of an archive
	 * account on a storage that is gone do, the user sees no document: the history notes a
	 * failed search, and doesn't count it as one that works again.
	 */
	#[DataProvider('failingFiles')]
	public function testASearchWhoseFilesAllFailGoesIntoTheHistory(bool $unreadable): void {
		$client = $this->paperless(['count' => 2, 'next' => null, 'results' => [
			['id' => 7, 'title' => 'Invoice'],
			['id' => 8, 'title' => 'Receipt'],
		]]);
		$files = [];
		foreach ([7 => 'Invoice [P7].pdf', 8 => 'Receipt [P8].pdf'] as $documentId => $name) {
			$files[$documentId] = $unreadable ? $this->unreadableFile($name) : $this->brokenFile($name);
		}

		$result = $this->provider($client, $files)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertSame([], $result['entries']);
		$history = $this->history();
		self::assertSame(1, $history->failed);
		self::assertTrue($history->awaitsRecovery());
		self::assertSame(SearchEvent::KIND_FAILED, $history->events[0]->kind);
		self::assertSame(SearchEvent::STEP_FILES, $history->events[0]->step);
		self::assertSame('RuntimeException', $history->events[0]->error);
		self::assertSame('Storage unavailable', $history->events[0]->message);
	}

	public function testTheFirstSearchThatWorksAgainIsTheRecovery(): void {
		$answer = $this->response(['count' => 0, 'next' => null, 'results' => []]);
		$calls = 0;
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(static function () use (&$calls, $answer): IResponse {
			// The first search fails twice, its request and the second try of it.
			if (++$calls <= 2) {
				throw new RuntimeException('cURL error 7: Failed to connect');
			}

			return $answer;
		});
		$provider = $this->provider($client);

		$provider->search($this->user(), $this->query('invoice'));
		self::assertTrue($this->history()->awaitsRecovery());

		$provider->search($this->user(), $this->query('invoice'));
		self::assertSame(self::NOW, $this->history()->recoveredAt);
		self::assertSame(SearchEvent::KIND_FAILED, $this->history()->events[0]->kind);
	}

	public function testASearchThatWorksStoresNothing(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);

		$this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])->search($this->user(), $this->query('invoice'));

		self::assertSame([], $this->stored);
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
	 * @return array<string, array{string, string, string}>
	 */
	public static function archiveAccountsInAnotherCase(): array {
		return [
			'setting stored before #99' => ['Archive', '', 'archive'],
			'account of Paperless Sync, which stores it as typed' => ['', 'SYNC', 'sync'],
		];
	}

	/**
	 * Nextcloud accepts an account whatever the case of its ID, but the owner of a file
	 * carries the exact ID (#99).
	 */
	#[DataProvider('archiveAccountsInAnotherCase')]
	public function testAnArchiveAccountInAnotherCaseFindsItsFiles(string $archiveOwner, string $syncAccount, string $owner): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf', $owner)], archiveOwner: $archiveOwner, syncAccount: $syncAccount)
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertCount(1, $result['entries']);
	}

	public function testWithAnArchiveAccountThatDoesNotExistPaperlessIsNotAsked(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf', 'deleted')], archiveOwner: 'deleted')
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
	}

	/**
	 * After a move or a restore of Nextcloud with another secret in config.php, the stored
	 * token can't be decrypted. Every search failed with HTTP 500 then (#99).
	 */
	public function testAStoredTokenThatCannotBeDecryptedShowsNoDocumentsInsteadOfFailing(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willThrowException(new RuntimeException('HMAC does not match.'));

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')], credentials: $credentials)
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertFalse($result['isPaginated']);
		self::assertSame([], $result['entries']);
		self::assertSame([], $this->stored);
	}

	/**
	 * The credentials manager decrypts the token on every call; a failed search asked for
	 * it three times.
	 */
	public function testTheTokenIsDecryptedOncePerSearch(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('cURL error 7: Failed to connect'));
		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->expects(self::once())->method('retrieve')->willReturn('TEST_VALUE');

		$this->provider($client, credentials: $credentials)->search($this->user(), $this->query('invoice'));

		self::assertSame(1, $this->history()->failed);
	}

	/**
	 * Builds the provider on a Paperless behind the given client and a user who can see the given files,
	 * in a Nextcloud with the accounts archive and sync.
	 *
	 * @param array<int, File> $files by Paperless document id
	 * @param ?ICredentialsManager $credentials the one that stores the token, by default one that holds $token
	 * @param ?Folder $userFolder the folder of the user, by default one with the files
	 */
	private function provider(
		IClient $client,
		array $files = [],
		string $token = 'TEST_VALUE',
		?LoggerInterface $logger = null,
		string $archiveOwner = 'archive',
		string $syncAccount = '',
		?ICredentialsManager $credentials = null,
		?Folder $userFolder = null,
	): PaperlessSearchProvider {
		$config = $this->settings($archiveOwner, $syncAccount);

		if ($credentials === null) {
			$credentials = $this->createStub(ICredentialsManager::class);
			$credentials->method('retrieve')->willReturn($token);
		}

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder ?? $this->userFolder(array_values($files)));

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/paperless_unified_search/img/app.svg');
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => 'https://cloud.example.com/f/' . $parameters['fileid'],
		);

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0');

		$configService = new ConfigService($config, $credentials, $this->accounts('archive', 'sync'), $this->createStub(LoggerInterface::class));

		return new PaperlessSearchProvider(
			new PaperlessApiService($configService, $clientService, new SearchDiagnostics($config, $this->clock())),
			new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)),
			$l10n,
			$urlGenerator,
			$request,
			$logger ?? $this->createStub(LoggerInterface::class),
			$configService,
			new SearchDiagnostics($config, $this->clock()),
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

	/**
	 * A file of the archive account that fails as soon as its entry needs it.
	 */
	private function brokenFile(string $name): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/dennis/files/Paperless/' . $name);
		$file->method('getId')->willThrowException(new RuntimeException('Storage unavailable'));
		$file->method('getOwner')->willReturn($this->account('archive'));

		return $file;
	}

	/**
	 * A file that fails as soon as the file locator asks for its owner.
	 */
	private function unreadableFile(string $name): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/dennis/files/Paperless/' . $name);
		$file->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));

		return $file;
	}

	private function account(string $uid): IUser {
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn($uid);

		return $account;
	}

	private function history(): DiagnosticsHistory {
		$history = DiagnosticsHistory::fromArray(is_array($this->stored['diagnostics'] ?? null) ? $this->stored['diagnostics'] : []);
		self::assertNotNull($history, 'The diagnostics recorded no problem.');

		return $history;
	}

	private function clock(): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);

		return $clock;
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
		// What the diagnostics store lands in $this->stored.
		$config->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('getValueArray')->willReturnCallback(function (string $app, string $key, array $default): array {
			$value = $this->stored[$key] ?? $default;

			return is_array($value) ? $value : $default;
		});

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
