<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search;

use DateTimeImmutable;
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
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICacheFactory;
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

	/**
	 * What the app keeps while no problem happens: the mark that no failure waits for a
	 * search that works again, which the first search after the update to 0.5.1 stores.
	 */
	private const NO_PROBLEM = ['diagnostics_pending' => false];

	/** The configuration of the apps, with what the diagnostics store. */
	private MemoryAppConfig $config;

	/** @var list<array<string, int|string>> the queries of the requests that reached Paperless, oldest first */
	private array $requests = [];

	protected function setUp(): void {
		$this->config = new MemoryAppConfig([AppConstants::APP_ID . '.diagnostics_pending' => false]);
	}

	#[DataProvider('resourceUrlCases')]
	public function testReturnsOnlyAccessibleFilesWithPlatformCompatibleResourceUrl(
		string $userAgent,
		string $expectedResourceUrl,
	): void {
		$config = $this->settings('archive');

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$response = $this->createStub(IResponse::class);
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
						&& $options['headers']['User-Agent'] === 'Nextcloud-Paperless-Unified-Search/0.5.1'
						&& $options['query']['query'] === 'gross salary'
						&& $options['query']['fields'] === 'id,title,created,correspondent,document_type,__search_hit__';
				}),
			)
			->willReturn($response);

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn('2026-07-31 - Salary [P123].pdf');
		$file->method('getPath')->willReturn('/dennis/files/Paperless/Salary [P123].pdf');
		$file->method('getId')->willReturn(4711);
		$file->method('getOwner')->willReturn($this->account('archive'));

		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::atLeastOnce())->method('getUserFolder')->with('dennis')->willReturn($this->userFolder([$file]));

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::once())
			->method('imagePath')
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

		$query = $this->createStub(ISearchQuery::class);
		$query->method('getTerm')->willReturn('gross salary');
		$query->method('getLimit')->willReturn(10);
		$query->method('getCursor')->willReturn(null);

		$configService = new ConfigService($config, $credentials, $this->accounts('archive'), $this->createStub(LoggerInterface::class));
		$provider = new PaperlessSearchProvider(
			new PaperlessApiService($configService, $clientService, $this->diagnostics($config), $this->clock(), $this->appManager()),
			new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)),
			$l10n,
			$urlGenerator,
			$request,
			$this->createStub(LoggerInterface::class),
			$configService,
			$this->diagnostics($config),
			$this->clock(),
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
			// Nextcloud's pattern of its iOS app matches the one of ownCloud too, which
			// opens no nextcloud:// link.
			'ownCloud iOS' => [
				'Mozilla/5.0 (iOS) ownCloud-iOS/12.4.0',
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
			new PaperlessApiService($configService, $this->createStub(IClientService::class), $this->diagnostics($config), $this->clock(), $this->appManager()),
			new NextcloudFileLocator($this->createStub(IRootFolder::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(IL10N::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(IRequest::class),
			$this->createStub(LoggerInterface::class),
			$configService,
			$this->diagnostics($config),
			$this->clock(),
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
	 * @return array<string, array{int|string|null, int}>
	 */
	public static function cursors(): array {
		return [
			'first search' => [null, 1],
			'cursor zero' => [0, 1],
			'cursor in the first page' => [24, 1],
			'cursor at the start of the second page' => [25, 2],
			'cursor in the second page, as text' => ['30', 2],
			'cursor zero as text' => ['0', 1],
			'negative cursor' => [-2, 1],
			'negative cursor as text' => ['-3', 1],
			'cursor that is no number' => ['next', 1],
			'last cursor that a search pages to' => [9999, 400],
		];
	}

	/**
	 * The cursor is the number of hits of Paperless that the earlier pages went through,
	 * and Paperless is asked for pages of 25 hits, whatever the limit.
	 */
	#[DataProvider('cursors')]
	public function testTheCursorChoosesThePageOfPaperless(int|string|null $cursor, int $page): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static fn (array $options): bool => $options['query'] === [
					'query' => 'invoice',
					'page' => $page,
					'page_size' => 25,
					'fields' => 'id,title,created,correspondent,document_type,__search_hit__',
				]),
			)
			->willReturn($this->response(['count' => 0, 'next' => null, 'results' => []]));

		$this->provider($client)->search($this->user(), $this->query(' invoice ', $cursor, 5));
	}

	/**
	 * @return array<string, array{int|string}>
	 */
	public static function cursorsPastTheEnd(): array {
		return [
			'number' => [10000],
			'text' => ['10000'],
			'text beyond the largest integer' => ['99999999999999999999'],
		];
	}

	#[DataProvider('cursorsPastTheEnd')]
	public function testACursorPastTheEndAsksPaperlessNothing(int|string $cursor): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$result = $this->provider($client)->search($this->user(), $this->query('invoice', $cursor, 5))->jsonSerialize();

		self::assertFalse($result['isPaginated']);
		self::assertSame([], $result['entries']);
	}

	/**
	 * A search pages through the first 10,000 hits of Paperless: the page that holds the
	 * last of them is the last page.
	 */
	public function testASearchEndsAfterTheFirstTenThousandHits(): void {
		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 10030))), $this->files([10001]))
			->search($this->user(), $this->query('invoice', 9990, 5))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertSame([400], array_column($this->requests, 'page'));
		self::assertFalse($result['isPaginated']);
	}

	/**
	 * Nextcloud's web search shows Paperless documents only when its first answer holds
	 * some: documents that the user can't open hid those further down, and all of them
	 * when the first five hits were such documents.
	 */
	public function testDocumentsThatTheUserCannotOpenDoNotHideTheOnesFurtherDown(): void {
		$client = $this->ranking(self::documentsWithIds(range(1, 26)));

		$result = $this->provider($client, $this->files([26]))
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame(['Document 26'], self::titles($result['entries']));
		self::assertFalse($result['isPaginated']);
		self::assertSame([1, 2], array_column($this->requests, 'page'));
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function limits(): array {
		return [
			'the first answer of the web search' => [5],
			'after one "Load more"' => [10],
			'after two' => [15],
		];
	}

	/**
	 * Nextcloud's web search offers "Load more results" only when a page holds as many
	 * results as it asked for, and asks for 5 more each time, from the start.
	 */
	#[DataProvider('limits')]
	public function testAPageHoldsAsManyResultsAsAskedForWhileThereAreMore(int $limit): void {
		$ids = range(1, 60);
		$accessible = self::everyFifthLeftOut($ids);

		$result = $this->provider($this->ranking(self::documentsWithIds($ids)), $this->files($accessible))
			->search($this->user(), $this->query('invoice', null, $limit))
			->jsonSerialize();

		self::assertSame(self::titlesOf(array_slice($accessible, 0, $limit)), self::titles($result['entries']));
		self::assertTrue($result['isPaginated']);
		self::assertSame([25], array_unique(array_column($this->requests, 'page_size')));
		// The first page of Paperless fills the page, which asks for no other one.
		self::assertSame([1], array_column($this->requests, 'page'));
	}

	/**
	 * A later page of Paperless adds only the results that the pages before left to fill.
	 */
	public function testALaterPageAddsOnlyTheResultsThatAreStillMissing(): void {
		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 60))), $this->files([3, 7, 26, 27, 28, 29, 30, 31]))
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame(self::titlesOf([3, 7, 26, 27, 28]), self::titles($result['entries']));
		self::assertSame([1, 2], array_column($this->requests, 'page'));
		self::assertTrue($result['isPaginated']);
		// Hits 26 to 28 are gone through on the second page of Paperless.
		self::assertSame(28, $result['cursor']);
	}

	/**
	 * The apps of Nextcloud ask for the next page with the cursor of the last one: each
	 * page goes on with the hit after the last one that the page before went through,
	 * also in the middle of a page of Paperless.
	 */
	public function testTheNextPageGoesOnWithoutRepeatingOrMissingADocument(): void {
		$ids = range(1, 60);
		$accessible = self::everyFifthLeftOut($ids);
		$provider = $this->provider($this->ranking(self::documentsWithIds($ids)), $this->files($accessible));

		$first = $provider->search($this->user(), $this->query('invoice', null, 5))->jsonSerialize();
		self::assertSame(self::titlesOf([1, 2, 3, 4, 6]), self::titles($first['entries']));
		self::assertTrue($first['isPaginated']);
		// Hits 1 to 6 are gone through, document 5 without a file among them.
		self::assertSame(6, $first['cursor']);

		$shown = self::titles($first['entries']);
		$result = $first;
		for ($pages = 1; $result['isPaginated'] && $pages < 20; $pages++) {
			$result = $provider->search($this->user(), $this->query('invoice', (string)$result['cursor'], 5))->jsonSerialize();
			$shown = [...$shown, ...self::titles($result['entries'])];
		}

		self::assertFalse($result['isPaginated'], 'The search never came to an end.');
		self::assertSame(self::titlesOf($accessible), $shown);
	}

	/**
	 * A page full of results whose last hit is the last one of Paperless leaves nothing to
	 * page to.
	 */
	public function testAPageThatTakesTheLastHitIsTheLastPage(): void {
		$result = $this->provider($this->ranking(self::documentsWithIds([1, 2, 3])), $this->files([1, 2, 3]))
			->search($this->user(), $this->query('invoice', null, 3))
			->jsonSerialize();

		self::assertCount(3, $result['entries']);
		self::assertFalse($result['isPaginated']);
	}

	/**
	 * Hits that a page doesn't need are left for the next one, even when they are the last
	 * ones of Paperless.
	 */
	public function testHitsThatAPageDoesNotNeedAreLeftForTheNextOne(): void {
		$result = $this->provider($this->ranking(self::documentsWithIds([1, 2, 3])), $this->files([1, 2, 3]))
			->search($this->user(), $this->query('invoice', null, 2))
			->jsonSerialize();

		self::assertSame(self::titlesOf([1, 2]), self::titles($result['entries']));
		self::assertTrue($result['isPaginated']);
		self::assertSame(2, $result['cursor']);
		// A full page asks Paperless nothing more.
		self::assertCount(1, $this->requests);
	}

	/**
	 * However few of the hits the user can open, a search asks Paperless for at most four
	 * pages, and the next one goes on after them.
	 */
	public function testASearchAsksPaperlessForAtMostFourPages(): void {
		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 200))), $this->files([150]))
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertSame([1, 2, 3, 4], array_column($this->requests, 'page'));
		self::assertTrue($result['isPaginated']);
		self::assertSame(100, $result['cursor']);
		// Four requests to Paperless, one search of the file cache each, and one search.
		self::assertCount(4, $this->searches);
		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * @return array<string, array{float}>
	 */
	public static function slowPages(): array {
		return [
			'4 seconds after two pages' => [2.0],
			'exactly 3 seconds after two pages' => [1.5],
		];
	}

	/**
	 * A slow Paperless gets no further request once the search has taken three seconds.
	 *
	 * @param float $seconds how much later every look at the clock finds it
	 */
	#[DataProvider('slowPages')]
	public function testASlowPaperlessIsAskedForNoFurtherPageAfterThreeSeconds(float $seconds): void {
		$clock = $this->readings($seconds, 2 * $seconds, 3 * $seconds, 4 * $seconds);

		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 200))), clock: $clock)
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame([1, 2], array_column($this->requests, 'page'));
		self::assertTrue($result['isPaginated']);
		self::assertSame(50, $result['cursor']);
	}

	/**
	 * A later page of Paperless that fails costs the search only the results it would
	 * have added; the next search tries that page again.
	 */
	public function testALaterPageThatFailsKeepsTheResultsOfTheEarlierOnes(): void {
		$client = $this->ranking(self::documentsWithIds(range(1, 30)), failingPage: 2);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
			]);

		$result = $this->provider($client, $this->files([3, 7, 28]), logger: $logger)
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame(self::titlesOf([3, 7]), self::titles($result['entries']));
		self::assertTrue($result['isPaginated']);
		self::assertSame(25, $result['cursor']);
		// The user sees documents, so the search worked.
		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * A search that shows nothing because a later page failed is one failed search.
	 */
	public function testALaterPageThatFailsBeforeAnyResultFailsTheSearch(): void {
		$client = $this->ranking(self::documentsWithIds(range(1, 30)), failingPage: 2);

		$result = $this->provider($client, $this->files([28]))
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertFalse($result['isPaginated']);
		$history = $this->history();
		self::assertSame(1, $history->failed);
		self::assertCount(1, $history->events);
		self::assertSame(SearchEvent::STEP_PAPERLESS, $history->events[0]->step);
		self::assertSame('Paperless is gone', $history->events[0]->message);
	}

	/**
	 * However many pages of Paperless a search asks for, it is one search in the
	 * diagnostics, and so is its failure, with the error of the first file that failed.
	 */
	public function testASearchOfSeveralPagesIsOneSearchInTheDiagnostics(): void {
		$files = [
			3 => $this->unreadableFile('Document [P3].pdf', 'Storage of page 1 unavailable'),
			28 => $this->brokenFile('Document [P28].pdf', 'Storage of page 2 unavailable'),
		];

		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 30))), $files)
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertSame([1, 2], array_column($this->requests, 'page'));
		$history = $this->history();
		self::assertSame(1, $history->failed);
		self::assertCount(1, $history->events);
		self::assertSame(SearchEvent::STEP_FILES, $history->events[0]->step);
		self::assertSame('Storage of page 1 unavailable', $history->events[0]->message);
	}

	/**
	 * @return array<string, array{array<int, string>, string}>
	 */
	public static function filesThatFailOnOnePage(): array {
		return [
			// The file locator reads the files of the page before their entries are built.
			'when the locator reads one and the entry of another is built' => [[3 => 'unreadable', 4 => 'broken'], 'The file of document 3 failed'],
			'when the entries of two are built' => [[3 => 'broken', 4 => 'broken'], 'The file of document 3 failed'],
		];
	}

	/**
	 * The history notes the error of the file that failed first.
	 *
	 * @param array<int, string> $failures how the file of each document fails, by its ID
	 */
	#[DataProvider('filesThatFailOnOnePage')]
	public function testTheHistoryNotesTheFileThatFailedFirst(array $failures, string $message): void {
		$files = [];
		foreach ($failures as $documentId => $failure) {
			$name = 'Document [P' . $documentId . '].pdf';
			$error = 'The file of document ' . $documentId . ' failed';
			$files[$documentId] = $failure === 'unreadable' ? $this->unreadableFile($name, $error) : $this->brokenFile($name, $error);
		}

		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 10))), $files)
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertSame($message, $this->history()->events[0]->message);
	}

	/**
	 * A result that a file costs on an earlier page is logged, also when a later page
	 * fills the results without any.
	 */
	public function testAResultThatFailsOnAnEarlierPageIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search left out {count} result(s) it could not show ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 1,
				'errorType' => RuntimeException::class,
			]);

		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 30))), [
			3 => $this->brokenFile('Document [P3].pdf'),
			...$this->files([26, 27]),
		], logger: $logger)->search($this->user(), $this->query('invoice', null, 5))->jsonSerialize();

		self::assertSame(self::titlesOf([26, 27]), self::titles($result['entries']));
		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * Of all files that fail to answer on the pages of one search, the log notes how many
	 * results they cost, once.
	 */
	public function testResultsThatFailOnSeveralPagesAreLoggedOnce(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('Paperless unified search left out {count} result(s) it could not show ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 2,
				'errorType' => RuntimeException::class,
			]);

		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 30))), [
			3 => $this->brokenFile('Document [P3].pdf'),
			4 => $this->file(4, 'Document [P4].pdf'),
			28 => $this->brokenFile('Document [P28].pdf'),
		], logger: $logger)->search($this->user(), $this->query('invoice', null, 5))->jsonSerialize();

		self::assertSame(self::titlesOf([4]), self::titles($result['entries']));
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
		self::assertSame(0, $event->durationMs);
	}

	/**
	 * @return array<string, array{float, int}>
	 */
	public static function durations(): array {
		return [
			'less than half a millisecond more' => [3.0012, 3001],
			'more than half a millisecond more' => [3.0017, 3002],
		];
	}

	/**
	 * The history notes how long a failed search took, to the millisecond.
	 */
	#[DataProvider('durations')]
	public function testTheHistoryNotesHowLongAFailedSearchTook(float $seconds, int $durationMs): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('cURL error 28: Operation timed out'));

		$this->provider($client, clock: $this->readings(0.0, $seconds))->search($this->user(), $this->query('invoice'));

		self::assertSame($durationMs, $this->history()->events[0]->durationMs);
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
		self::assertSame(self::NO_PROBLEM, $this->problems());
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

		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * The first search that works after the update to 0.5.1 stores only the mark that no
	 * failure waits, after which searches that work read nothing else (#128).
	 */
	public function testTheFirstSearchAfterTheUpdateStoresOnlyTheMark(): void {
		$this->config = new MemoryAppConfig();
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);

		$this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])->search($this->user(), $this->query('invoice'));

		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * @return array<string, array{int, string, array<string, string>, int|string|null}>
	 */
	public static function rejectedSearches(): array {
		return [
			// Paperless 2 never gets the term in the simple mode, which it doesn't have.
			'a query that Paperless can\'t parse' => [400, '{"query":["Invalid date value \'[2026\' for field \'created\'."]}', ['X-Version' => '2.20.13', 'X-Api-Version' => '9'], null],
			'a query too long for Paperless' => [400, '{"query":["The search query is too long (4097 characters). The maximum allowed length is 4096 characters."]}', ['X-Version' => '3.3.0', 'X-Api-Version' => '10'], null],
			'a page past the last one' => [404, '{"detail":"Invalid page."}', [], '25'],
		];
	}

	/**
	 * Nextcloud searches while the user types: a query that Paperless refuses, such as a
	 * range of dates that isn't typed to its end, finds nothing, and the history of the
	 * problems neither counts it nor takes it for a search that works again. Every call of
	 * the API wrote the history, which any user could fill with false failures (#128).
	 *
	 * @param array<string, string> $headers
	 */
	#[DataProvider('rejectedSearches')]
	public function testASearchThatPaperlessRejectsLeavesTheHistoryUntouched(int $status, string $body, array $headers, int|string|null $cursor): void {
		$this->awaitRecovery();
		$before = $this->problems();
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->answer($status, $body, $headers));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::once())
			->method('debug')
			->with('Paperless rejected the query of a search with HTTP {status}', [
				'app' => AppConstants::APP_ID,
				'status' => $status,
			]);

		$result = $this->provider($client, logger: $logger)
			->search($this->user(), $this->query('created:[2026 to', $cursor))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertFalse($result['isPaginated']);
		self::assertSame($before, $this->problems());
	}

	/**
	 * A later page of Paperless past the last one, as when documents went away between two
	 * pages, ends the results with those of the pages before (#128).
	 */
	public function testARejectedLaterPageKeepsTheResultsOfTheEarlierOnes(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
			return $options['query']['page'] === 1
				? $this->response(['count' => 30, 'next' => 'https://paperless.example.com/api/documents/?page=2', 'results' => self::documentsWithIds(range(1, 25))])
				: $this->answer(404, '{"detail":"Invalid page."}');
		});

		$result = $this->provider($client, $this->files([3, 7]))
			->search($this->user(), $this->query('invoice', null, 5))
			->jsonSerialize();

		self::assertSame(self::titlesOf([3, 7]), self::titles($result['entries']));
		self::assertFalse($result['isPaginated']);
		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function failingAnswers(): array {
		return [
			'a refused token' => [401],
			'a server error' => [500],
		];
	}

	/**
	 * Answers that aren't the query of the user stay failures in the history (#128).
	 */
	#[DataProvider('failingAnswers')]
	public function testAnswersThatFailStillGoIntoTheHistory(int $status): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->answer($status, '{"detail":"Failed."}', ['X-Version' => '3.3.0', 'X-Api-Version' => '10']));

		$this->provider($client)->search($this->user(), $this->query('created:[2026 to'));

		$history = $this->history();
		self::assertSame(1, $history->failed);
		self::assertSame('Paperless returned HTTP ' . $status . '.', $history->events[0]->message);
		// Without the mark, the next search that works reads the history and notes the recovery.
		self::assertNull($this->config->stored(AppConstants::APP_ID, 'diagnostics_pending'));
	}

	/**
	 * Paperless 3 finds the documents of an unfinished query in its simple mode: a search
	 * that works, which ends a failure of the history.
	 */
	public function testAnUnfinishedQueryFindsDocumentsInTheSimpleModeOfPaperless3(): void {
		$this->awaitRecovery();
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
			return isset($options['query']['query'])
				? $this->answer(400, '{"query":["Invalid date value \'[2026\' for field \'created\'."]}', ['X-Version' => '3.3.0', 'X-Api-Version' => '10'])
				: $this->response(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);
		});

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice created:[2026 to'))
			->jsonSerialize();

		self::assertCount(1, $result['entries']);
		self::assertSame(self::NOW, $this->history()->recoveredAt);
		self::assertFalse($this->config->stored(AppConstants::APP_ID, 'diagnostics_pending'));
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
			// Quotes of HTML 4, of HTML5 and as numbers.
			'quotes' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => '&quot;Paid&quot; &apos;in full&apos; &#x27;2026&#x27;']],
				'Invoice',
				'"Paid" \'in full\' \'2026\'',
			],
			'blanks around the highlights' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => " \n <b>Paid</b> in full \t"]],
				'Invoice',
				'Paid in full',
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
		self::assertSame([
			'thumbnailUrl' => '/apps/paperless_unified_search/img/app.svg',
			'title' => $title,
			'subline' => $subline,
			'resourceUrl' => 'https://cloud.example.com/f/1007',
			'icon' => '',
			// The icon of the app, which a round frame would cut off.
			'rounded' => false,
			'attributes' => ['fileId' => '1007', 'path' => '/Paperless/Scan [P7].pdf'],
		], $result['entries'][0]->jsonSerialize());
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
		self::assertSame(self::NO_PROBLEM, $this->problems());
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
	 * @param ?ITimeFactory $clock the clock of the search, by default one that stands still
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
		?ITimeFactory $clock = null,
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
			new PaperlessApiService($configService, $clientService, $this->diagnostics($config), $this->clock(), $this->appManager()),
			new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)),
			$l10n,
			$urlGenerator,
			$request,
			$logger ?? $this->createStub(LoggerInterface::class),
			$configService,
			$this->diagnostics($config),
			$clock ?? $this->clock(),
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

	/**
	 * An answer of Paperless with this status and body.
	 *
	 * @param array<string, string> $headers by name
	 */
	private function answer(int $status, string $body, array $headers = []): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');

		return $response;
	}

	/**
	 * A Paperless whose full-text search ranks these documents in this order and answers
	 * every page as Paperless does: with the documents of the page, and the link to the
	 * next page while more documents follow. The queries of the requests land in
	 * $this->requests.
	 *
	 * @param list<array<string, mixed>> $documents
	 * @param ?int $failingPage a page whose every request gets no answer
	 */
	private function ranking(array $documents, ?int $failingPage = null): IClient {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($documents, $failingPage): IResponse {
			$query = $options['query'];
			$this->requests[] = $query;
			$page = $query['page'];
			$size = $query['page_size'];
			if ($page === $failingPage) {
				throw new RuntimeException('Paperless is gone');
			}

			return $this->response([
				'count' => count($documents),
				'next' => count($documents) > $page * $size ? 'https://paperless.example.com/api/documents/?page=' . ($page + 1) : null,
				'results' => array_slice($documents, ($page - 1) * $size, $size),
			]);
		});

		return $client;
	}

	/**
	 * The documents "Document <ID>" with these IDs, in this order.
	 *
	 * @param list<int> $ids
	 * @return list<array{id: int, title: string}>
	 */
	private static function documentsWithIds(array $ids): array {
		return array_map(static fn (int $id): array => ['id' => $id, 'title' => 'Document ' . $id], $ids);
	}

	/**
	 * The files of the archive account of the documents with these IDs, by document ID.
	 *
	 * @param list<int> $ids
	 * @return array<int, File>
	 */
	private function files(array $ids): array {
		$files = [];
		foreach ($ids as $id) {
			$files[$id] = $this->file($id, 'Document [P' . $id . '].pdf');
		}

		return $files;
	}

	/**
	 * These IDs without every fifth: the documents whose files the user can open.
	 *
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private static function everyFifthLeftOut(array $ids): array {
		return array_values(array_filter($ids, static fn (int $id): bool => $id % 5 !== 0));
	}

	/**
	 * @param list<int> $ids
	 * @return list<string>
	 */
	private static function titlesOf(array $ids): array {
		return array_map(static fn (int $id): string => 'Document ' . $id, $ids);
	}

	/**
	 * @param list<SearchResultEntry> $entries
	 * @return list<string>
	 */
	private static function titles(array $entries): array {
		return array_map(static fn (SearchResultEntry $entry): string => $entry->jsonSerialize()['title'], $entries);
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
	private function brokenFile(string $name, string $message = 'Storage unavailable'): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/dennis/files/Paperless/' . $name);
		$file->method('getId')->willThrowException(new RuntimeException($message));
		$file->method('getOwner')->willReturn($this->account('archive'));

		return $file;
	}

	/**
	 * A file that fails as soon as the file locator asks for its owner.
	 */
	private function unreadableFile(string $name, string $message = 'Storage unavailable'): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/dennis/files/Paperless/' . $name);
		$file->method('getOwner')->willThrowException(new RuntimeException($message));

		return $file;
	}

	private function account(string $uid): IUser {
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn($uid);

		return $account;
	}

	private function history(): DiagnosticsHistory {
		$stored = $this->config->stored(AppConstants::APP_ID, 'diagnostics');
		$history = DiagnosticsHistory::fromArray(is_array($stored) ? $stored : []);
		self::assertNotNull($history, 'The diagnostics recorded no problem.');

		return $history;
	}

	private function diagnostics(IAppConfig $config): SearchDiagnostics {
		return new SearchDiagnostics($config, $this->clock(), $this->createStub(ICacheFactory::class));
	}

	private function appManager(): IAppManager {
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.5.1');

		return $appManager;
	}

	private function clock(): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);
		$clock->method('now')->willReturn(new DateTimeImmutable('@' . self::NOW));

		return $clock;
	}

	/**
	 * A clock that reads these seconds after NOW one after the other, to the microsecond,
	 * and then stands still at the last of them.
	 */
	private function readings(float ...$seconds): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);
		$clock->method('now')->willReturnCallback(static function () use (&$seconds): DateTimeImmutable {
			$reading = count($seconds) > 1 ? array_shift($seconds) : $seconds[0];
			$time = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', self::NOW + $reading));
			self::assertInstanceOf(DateTimeImmutable::class, $time);

			return $time;
		});

		return $clock;
	}

	/**
	 * The configuration of the apps with the settings of a Paperless, as an administrator
	 * and Paperless Sync stored them.
	 */
	private function settings(string $archiveOwner, string $syncAccount = ''): IAppConfig {
		return $this->config
			->seed(AppConstants::APP_ID, 'paperless_url', 'https://paperless.example.com')
			->seed(AppConstants::APP_ID, 'archive_owner', $archiveOwner)
			->seed('paperless_sync', 'target_user', $syncAccount);
	}

	/**
	 * What the diagnostics stored: the history and the mark that a failure waits for a
	 * search that works, by key.
	 *
	 * @return array<string, mixed>
	 */
	private function problems(): array {
		$problems = array_intersect_key($this->config->storedValues(AppConstants::APP_ID), ['diagnostics' => true, 'diagnostics_pending' => true]);
		ksort($problems);

		return $problems;
	}

	/**
	 * A history with a failure that waits for a search that works again.
	 */
	private function awaitRecovery(): void {
		$this->config
			->seed(AppConstants::APP_ID, 'diagnostics', ['since' => self::NOW, 'failed' => 1, 'retried' => 0, 'lastFailureAt' => self::NOW, 'recoveredAt' => null, 'events' => []], true)
			->seed(AppConstants::APP_ID, 'diagnostics_pending', true);
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
