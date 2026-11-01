<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\FilePictures;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessNames;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\FileCache;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IFilter;
use OCP\Search\IFilteringProvider;
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
	use UserPreferences;

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

	/** @var list<array{string, array<string, int|string>}> the list and the query of each request for names, oldest first */
	private array $nameRequests = [];

	/** @var list<array{bool|int, ?string}> the timestamp and the user of each question for a timezone, oldest first */
	private array $timeZoneRequests = [];

	/** The distributed cache of Nextcloud, once the app uses it. */
	private ?MemoryCache $cache = null;

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
						&& $options['query']['fields'] === 'id,title,created,correspondent,document_type,tags,__search_hit__';
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
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getParent')->willReturn($this->createStub(Folder::class));

		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::atLeastOnce())->method('getUserFolder')->with('dennis')->willReturn($this->userFolder([$file]));

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		// A file of Paperless has an icon of its type: the icon of the app is not needed.
		$urlGenerator->expects(self::never())->method('imagePath');
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

		$configService = new ConfigService($config, $credentials, $this->accounts('archive'), $this->createStub(LoggerInterface::class), $this->createStub(ICacheFactory::class));
		$paperlessApi = new PaperlessApiService($configService, $clientService, $this->diagnostics($config), $this->clock(), $this->appManager());
		$provider = new PaperlessSearchProvider(
			$paperlessApi,
			new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)),
			$l10n,
			$urlGenerator,
			$request,
			$this->createStub(LoggerInterface::class),
			$configService,
			$this->diagnostics($config),
			$this->clock(),
			...$this->presentation($paperlessApi, $configService, $urlGenerator),
			alwaysSearch: new AlwaysSearch($configService, $this->userConfig()),
			userSession: $this->session('dennis'),
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
		self::assertSame('Jul 31, 2026 — Gross 5,000 EUR', $result['entries'][0]->jsonSerialize()['subline']);
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

		$configService = new ConfigService($config, $this->createStub(ICredentialsManager::class), $this->accounts(), $this->createStub(LoggerInterface::class), $this->createStub(ICacheFactory::class));
		$paperlessApi = new PaperlessApiService($configService, $this->createStub(IClientService::class), $this->diagnostics($config), $this->clock(), $this->appManager());
		$provider = new PaperlessSearchProvider(
			$paperlessApi,
			new NextcloudFileLocator($this->createStub(IRootFolder::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(IL10N::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(IRequest::class),
			$this->createStub(LoggerInterface::class),
			$configService,
			$this->diagnostics($config),
			$this->clock(),
			...$this->presentation($paperlessApi, $configService),
			alwaysSearch: new AlwaysSearch($configService, $this->userConfig()),
			// Also where no user signed in, such as in occ.
			userSession: $this->session(null),
		);

		self::assertFalse($provider->isExternalProvider());
	}

	/**
	 * Nextcloud asks whether the provider is external as it lists the providers of the
	 * search for the user of the request: a user who chose to include Paperless in every
	 * search of their own finds it without the switch, everybody else with it, and nobody
	 * while the administrators don't let users choose it.
	 */
	public function testAUserWhoChoseToAlwaysSearchPaperlessIsNotGatedByTheSwitch(): void {
		$provider = $this->provider($this->createStub(IClient::class));

		self::assertTrue($provider->isExternalProvider());

		$this->preferences['dennis.' . AppConstants::APP_ID . '.always_search'] = true;
		self::assertFalse($provider->isExternalProvider());

		$this->config->setValueBool(AppConstants::APP_ID, 'personal_always_search', false);
		self::assertTrue($provider->isExternalProvider());
	}

	public function testTheChoiceOfAnotherUserLeavesTheSwitchOn(): void {
		$this->preferences['alice.' . AppConstants::APP_ID . '.always_search'] = true;

		self::assertTrue($this->provider($this->createStub(IClient::class))->isExternalProvider());
	}

	public function testDescribesItselfToTheUnifiedSearch(): void {
		$provider = $this->provider($this->createStub(IClient::class));

		self::assertSame(AppConstants::APP_ID . '_documents', $provider->getId());
		self::assertSame(PaperlessSearchProvider::ID, $provider->getId());
		self::assertSame('Paperless documents', $provider->getName());
		self::assertSame(40, $provider->getOrder('files.view.index', []));
	}

	/**
	 * Nextcloud's web search sends its date filter only to a provider that names since and
	 * until, and lists any other one under "Partial matches", searched without it.
	 */
	public function testTakesTheTermAndTheDateFilterOfTheSearch(): void {
		$provider = $this->provider($this->createStub(IClient::class));

		self::assertInstanceOf(IFilteringProvider::class, $provider);
		self::assertSame([IFilter::BUILTIN_TERM, IFilter::BUILTIN_SINCE, IFilter::BUILTIN_UNTIL], $provider->getSupportedFilters());
		self::assertSame([], $provider->getAlternateIds());
		self::assertSame([], $provider->getCustomFilters());
	}

	/**
	 * @return array<string, array{array<string, mixed>, string, array<string, string>}>
	 */
	public static function dateFilters(): array {
		return [
			'no date filter' => [[], 'America/New_York', []],
			'since alone' => [
				['since' => new DateTimeImmutable('2026-08-01T04:00:00Z')],
				'America/New_York',
				['created__date__gte' => '2026-08-01'],
			],
			'until alone' => [
				['until' => new DateTimeImmutable('2026-09-01T03:59:59.999Z')],
				'America/New_York',
				['created__date__lte' => '2026-08-31'],
			],
			'since and until' => [
				['since' => new DateTimeImmutable('2026-08-01T04:00:00Z'), 'until' => new DateTimeImmutable('2026-09-01T03:59:59.999Z')],
				'America/New_York',
				['created__date__gte' => '2026-08-01', 'created__date__lte' => '2026-08-31'],
			],
			// Midnight in Berlin is the evening before in UTC.
			'the day in the timezone of the user' => [
				['since' => new DateTimeImmutable('2026-07-31T22:00:00Z'), 'until' => new DateTimeImmutable('2026-08-31T21:59:59.999Z')],
				'Europe/Berlin',
				['created__date__gte' => '2026-08-01', 'created__date__lte' => '2026-08-31'],
			],
			'a day of the winter' => [
				['since' => new DateTimeImmutable('2026-12-31T23:00:00Z')],
				'Europe/Berlin',
				['created__date__gte' => '2027-01-01'],
			],
			'a mutable moment with an offset of its own' => [
				['since' => new DateTime('2026-08-01T00:30:00+02:00')],
				'UTC',
				['created__date__gte' => '2026-07-31'],
			],
			'filters whose values are no dates' => [['since' => '2026-08-01', 'until' => null], 'UTC', []],
		];
	}

	/**
	 * The date filter of Nextcloud's search becomes the filter of Paperless for the date of
	 * a document: each bound that the search has, as the day in the timezone of the user.
	 *
	 * @param array<string, mixed> $filters
	 * @param array<string, string> $expected
	 */
	#[DataProvider('dateFilters')]
	public function testTheDateFilterNarrowsTheDocumentsByTheirDate(array $filters, string $timeZone, array $expected): void {
		$this->provider($this->ranking(self::documentsWithIds([1, 2])), $this->files([1, 2]), timeZone: $timeZone)
			->search($this->user(), $this->query('invoice', null, 5, $filters));

		self::assertCount(1, $this->requests);
		self::assertSame($expected, self::filtersOf($this->requests[0]));
	}

	/**
	 * The timezone is the one of the user who searches, at the moment of the bound: where
	 * Nextcloud knows only the offset of the browser, the zone may differ in summer and in
	 * winter.
	 */
	public function testTheTimezoneIsTheOneOfTheUserAtTheMomentOfTheBound(): void {
		$since = new DateTimeImmutable('2026-01-01T05:00:00Z');
		$until = new DateTimeImmutable('2026-08-01T03:59:59Z');

		$this->provider($this->ranking(self::documentsWithIds([1])), $this->files([1]))
			->search($this->user(), $this->query('invoice', null, 5, ['since' => $since, 'until' => $until]));

		self::assertSame([[$since->getTimestamp(), 'dennis'], [$until->getTimestamp(), 'dennis']], $this->timeZoneRequests);
		self::assertSame(['created__date__gte' => '2026-01-01', 'created__date__lte' => '2026-07-31'], self::filtersOf($this->requests[0]));
	}

	/**
	 * Every page of Paperless that fills a page of results is asked with the date filter,
	 * so that a later page finds no document outside of the span either.
	 */
	public function testEveryPageOfPaperlessIsAskedWithTheDateFilter(): void {
		$result = $this->provider($this->ranking(self::documentsWithIds(range(1, 60))), $this->files([3, 26, 27, 51]))
			->search($this->user(), $this->query('invoice', null, 5, [
				'since' => new DateTimeImmutable('2026-08-01T04:00:00Z'),
				'until' => new DateTimeImmutable('2026-09-01T03:59:59Z'),
			]))
			->jsonSerialize();

		self::assertSame(self::titlesOf([3, 26, 27, 51]), self::titles($result['entries']));
		self::assertSame([1, 2, 3], array_column($this->requests, 'page'));
		foreach ($this->requests as $request) {
			self::assertSame(['created__date__gte' => '2026-08-01', 'created__date__lte' => '2026-08-31'], self::filtersOf($request));
		}
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
					'fields' => 'id,title,created,correspondent,document_type,tags,__search_hit__',
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
	 * @return array<string, array{string}>
	 */
	public static function refusedDocuments(): array {
		return [
			'a document that Paperless does not have' => ['Invalid more_like_id'],
			'a document that the account may not view' => ['Insufficient permissions.'],
		];
	}

	/**
	 * A marker that is wrong or of a document that the account of the token may not view
	 * finds nothing: the history neither counts it nor takes it for a search that works again.
	 */
	#[DataProvider('refusedDocuments')]
	public function testASearchForDocumentsLikeARefusedOneLeavesTheHistoryUntouched(string $body): void {
		$this->awaitRecovery();
		$before = $this->problems();
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->answer(403, $body));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$result = $this->provider($client, logger: $logger)
			->search($this->user(), $this->query('like:[P412]'))
			->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertFalse($result['isPaginated']);
		self::assertSame($before, $this->problems());
	}

	/**
	 * A token that may view no documents at all still is a problem of the search, also for
	 * this question, which Paperless refuses in other words then.
	 */
	public function testAnAccountWithoutDocumentsStillFailsASearchForSimilarOnes(): void {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturn($this->answer(403, '{"detail":"You do not have permission to perform this action."}'));

		$result = $this->provider($client)->search($this->user(), $this->query('like:412'))->jsonSerialize();

		self::assertSame([], $result['entries']);
		self::assertCount(1, $this->problems());
	}

	/**
	 * Documents similar to one fill a page as any search does: with hits of as many pages of
	 * Paperless as it takes, the filter of the search on each of them, and a next page that
	 * goes on from the cursor with the same question.
	 */
	public function testSimilarDocumentsFillAPageWithTheCursorAndTheDateFilter(): void {
		$client = $this->ranking(self::documentsWithIds(range(1, 60)));
		$provider = $this->provider($client, $this->files([3, 26, 27, 51, 52, 53]));
		$filters = ['since' => new DateTimeImmutable('2026-08-01T04:00:00Z')];

		$first = $provider->search($this->user(), $this->query('like:[P412]', null, 5, $filters))->jsonSerialize();

		self::assertSame(self::titlesOf([3, 26, 27, 51, 52]), self::titles($first['entries']));
		self::assertTrue($first['isPaginated']);
		self::assertSame([1, 2, 3], array_column($this->requests, 'page'));
		foreach ($this->requests as $request) {
			self::assertSame('412', $request['more_like_id']);
			self::assertArrayNotHasKey('query', $request);
			self::assertSame('2026-08-01', $request['created__date__gte']);
		}

		$this->requests = [];
		$second = $provider->search($this->user(), $this->query('like:[P412]', $first['cursor'], 5, $filters))->jsonSerialize();

		self::assertSame(self::titlesOf([53]), self::titles($second['entries']));
		self::assertFalse($second['isPaginated']);
		self::assertSame([3], array_column($this->requests, 'page'));
		self::assertSame('412', $this->requests[0]['more_like_id']);
	}

	/**
	 * Paperless 3 sends documents similar to one without an excerpt, so the line of a result
	 * ends with the name of its file.
	 */
	public function testASimilarDocumentWithoutAnExcerptShowsTheNameOfItsFile(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [[
			'id' => 412,
			'title' => 'Electricity invoice',
			'__search_hit__' => ['score' => 0.0, 'highlights' => '', 'note_highlights' => null, 'rank' => 1],
		]]]);

		$result = $this->provider($client, [412 => $this->file(412, 'Electricity invoice [P412].pdf')])
			->search($this->user(), $this->query('like:7'))
			->jsonSerialize();

		self::assertSame('Electricity invoice [P412].pdf', $result['entries'][0]->jsonSerialize()['subline']);
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
	 * A document, its title and its subline, and the name of its file when it isn't Scan [P7].pdf.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3?: string}>
	 */
	public static function documents(): array {
		$file = 'Scan [P7].pdf';

		return [
			'title and date' => [['title' => ' Invoice ', 'created' => '2026-05-01'], 'Invoice', 'May 1, 2026 — ' . $file],
			'blank title' => [['title' => '  '], $file, $file],
			'title that is no text' => [['title' => 42], $file, $file],
			'empty date' => [['title' => 'Invoice', 'created' => ''], 'Invoice', $file],
			'date that is no text' => [['title' => 'Invoice', 'created' => 20260501], 'Invoice', $file],
			// API versions before 9 send the start of the day in the timezone of Paperless,
			// which is that day, also for a user west of it.
			'date with a time' => [['title' => 'Invoice', 'created' => '2026-08-12T00:00:00+02:00'], 'Invoice', 'Aug 12, 2026 — ' . $file],
			'date with a time in UTC' => [['title' => 'Invoice', 'created' => '2026-08-11T22:00:00.123456Z'], 'Invoice', 'Aug 11, 2026 — ' . $file],
			'date with a time without its zone' => [['title' => 'Invoice', 'created' => '2026-08-12T09:30'], 'Invoice', 'Aug 12, 2026 — ' . $file],
			'day that does not exist' => [['title' => 'Invoice', 'created' => '2026-02-30'], 'Invoice', '2026-02-30 — ' . $file],
			'date in another form' => [['title' => 'Invoice', 'created' => '12.08.2026'], 'Invoice', '12.08.2026 — ' . $file],
			'date with more after it' => [['title' => 'Invoice', 'created' => '2026-08-12 and later'], 'Invoice', '2026-08-12 and later — ' . $file],
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
				['title' => 'Invoice', '__search_hit__' => ['highlights' => "Müller &amp; Co\n\t KG&nbsp; &#x27;24"]],
				'Invoice',
				"Müller & Co KG '24",
			],
			'long highlight' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => str_repeat('a', 200)]],
				'Invoice',
				str_repeat('a', 179) . '…',
			],
			// A search that found the term in a note only, as Paperless-ngx 3.3.0 answers it.
			'note' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => '', 'note_highlights' => 'Paid by bank <b>transfer</b>']],
				'Invoice',
				'Note: Paid by bank transfer',
			],
			'note with an excerpt of the text' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => 'Total <b>due</b>', 'note_highlights' => 'Paid by bank <b>transfer</b>']],
				'Invoice',
				'Total due',
			],
			'note of markup only' => [['title' => 'Invoice', '__search_hit__' => ['highlights' => '', 'note_highlights' => '<b></b>']], 'Invoice', $file],
			'no note' => [['title' => 'Invoice', '__search_hit__' => ['highlights' => '', 'note_highlights' => null]], 'Invoice', $file],
			// The text of a document that looks like a tag stays, as text that is no tag.
			'escaped tags' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => 'It quotes &lt;<b>script</b>&gt;alert(1)&lt;/<b>script</b>&gt; and &lt;!-- --&gt; &lt;?php']],
				'Invoice',
				'It quotes < script>alert(1)< /script> and < !-- --> < ?php',
			],
			'less-than signs that start no tag' => [
				['title' => 'Invoice', '__search_hit__' => ['highlights' => 'Below &lt;5 °C, a &lt; b, x&lt;y and z']],
				'Invoice',
				'Below <5 °C, a < b, x< y and z',
			],
			'tag in the name of the file' => [['title' => 'Invoice'], 'Invoice', 'Scan < b>[P7].pdf', 'Scan <b>[P7].pdf'],
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
	public function testTitleAndSublineComeFromTheDocument(array $document, string $title, string $subline, string $fileName = 'Scan [P7].pdf'): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7] + $document]]);

		$result = $this->provider($client, [7 => $this->file(7, $fileName)])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertCount(1, $result['entries']);
		$entry = $result['entries'][0]->jsonSerialize();
		self::assertSame($title, $entry['title']);
		self::assertSame($subline, $entry['subline']);
		// Whatever the document holds, the line below the title holds no tag of HTML: a
		// browser starts a tag at < before a letter, /, ! or ?.
		self::assertDoesNotMatchRegularExpression('/<[a-z\/!?]/i', $entry['subline']);
		self::assertSame('https://cloud.example.com/f/1007', $entry['resourceUrl']);
		self::assertSame(['fileId' => '1007', 'path' => '/Paperless/' . $fileName], $entry['attributes']);
	}

	public function testTheDateIsInTheLanguageOfTheUser(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Rechnung', 'created' => '2026-08-12']]]);

		$result = $this->provider($client, [7 => $this->file(7, 'Rechnung [P7].pdf')], locale: 'de')
			->search($this->user(), $this->query('rechnung'))
			->jsonSerialize();

		self::assertSame('12.08.2026 — Rechnung [P7].pdf', $result['entries'][0]->jsonSerialize()['subline']);
	}

	/**
	 * A file of a type of which Nextcloud draws previews shows its preview, as the
	 * search of files shows it; a preview that fails answers with the icon of the type.
	 */
	public function testAResultShowsThePreviewOfItsFile(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')], previews: true)
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		$entry = $result['entries'][0]->jsonSerialize();
		self::assertSame('https://cloud.example.com/index.php/core/preview?x=64&y=64&fileId=1007&mimeFallback=1', $entry['thumbnailUrl']);
		// The smart picker would show the icon in place of the thumbnail, as a class of CSS.
		self::assertSame('', $entry['icon']);
		self::assertFalse($entry['rounded']);
	}

	/**
	 * Nextcloud draws no previews of PDF files by default: the result shows the icon of
	 * the type of its file, as the search of files does, not one icon for every document.
	 */
	public function testWithoutAPreviewAResultShowsTheIconOfTheTypeOfItsFile(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		$entry = $result['entries'][0]->jsonSerialize();
		self::assertSame('https://cloud.example.com/core/img/filetypes/application-pdf.svg', $entry['thumbnailUrl']);
		self::assertSame('', $entry['icon']);
	}

	public function testATypeOfFileWithoutAnIconShowsTheIconOfTheApp(): void {
		$client = $this->paperless(['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice']]]);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].xyz', mimeType: 'application/x-unknown')], previews: true)
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		$entry = $result['entries'][0]->jsonSerialize();
		self::assertSame('https://cloud.example.com/apps/paperless_unified_search/img/app.svg', $entry['thumbnailUrl']);
		self::assertSame('', $entry['icon']);
	}

	/**
	 * Paperless sends the IDs of the correspondent and the type of a document; one
	 * request per kind names all of a page, and the result shows them after its date.
	 */
	public function testAResultNamesTheCorrespondentAndTheTypeOfItsDocument(): void {
		$client = $this->paperlessWithLists(['count' => 4, 'next' => null, 'results' => [
			['id' => 7, 'title' => 'Invoice', 'created' => '2026-08-12', 'correspondent' => 3, 'document_type' => 1],
			['id' => 8, 'title' => 'Renewal', 'created' => '2026-07-21', 'correspondent' => 5, 'document_type' => 2],
			['id' => 9, 'title' => 'Not shared with this user', 'correspondent' => 9, 'document_type' => 9],
			['id' => 10, 'title' => 'Bill', 'correspondent' => 3, 'document_type' => null],
		]], [
			'correspondents' => [3 => 'ACME Energy', 5 => "Harbor\n Mutual ", 9 => 'Secret'],
			'document_types' => [1 => 'Invoice', 2 => 'Insurance'],
		]);
		$files = [7 => $this->file(7, 'Invoice [P7].pdf'), 8 => $this->file(8, 'Renewal [P8].pdf'), 10 => $this->file(10, 'Bill [P10].pdf')];

		$result = $this->provider($client, $files)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertSame([
			'Aug 12, 2026 · ACME Energy · Invoice — Invoice [P7].pdf',
			'Jul 21, 2026 · Harbor Mutual · Insurance — Renewal [P8].pdf',
			'ACME Energy — Bill [P10].pdf',
		], self::sublines($result['entries']));
		// Only for the documents with a file, each ID once.
		self::assertSame([
			['correspondents', ['id__in' => '3,5', 'page_size' => 2]],
			['document_types', ['id__in' => '1,2', 'page_size' => 2]],
		], $this->nameRequests);
	}

	/**
	 * Two invoices of the same correspondent differ by their tags: a result shows up to two
	 * of them after the type, then how many more there are, and never the tag of the inbox.
	 * One request names the tags of the whole page.
	 */
	public function testAResultShowsUpToTwoTagsOfItsDocument(): void {
		$client = $this->paperlessWithLists(['count' => 3, 'next' => null, 'results' => [
			['id' => 7, 'title' => 'Invoice', 'created' => '2026-08-12', 'correspondent' => 3, 'document_type' => 1, 'tags' => [1, 4, 2]],
			['id' => 8, 'title' => 'Invoice', 'created' => '2026-07-12', 'correspondent' => 3, 'document_type' => 1, 'tags' => [5, 2, 6, 8]],
			['id' => 9, 'title' => 'Letter', 'created' => '2026-06-01', 'tags' => [1]],
		]], [
			'correspondents' => [3 => 'ACME Energy'],
			'document_types' => [1 => 'Invoice'],
			'tags' => [1 => ['name' => 'Inbox', 'is_inbox_tag' => true], 2 => 'tax 2026', 4 => 'unpaid', 5 => 'paid', 6 => 'warranty', 8 => 'scanned'],
		]);
		$files = [7 => $this->file(7, 'Invoice [P7].pdf'), 8 => $this->file(8, 'Invoice [P8].pdf'), 9 => $this->file(9, 'Letter [P9].pdf')];

		$result = $this->provider($client, $files)->search($this->user(), $this->query('invoice'))->jsonSerialize();

		self::assertSame([
			'Aug 12, 2026 · ACME Energy · Invoice · unpaid · tax 2026 — Invoice [P7].pdf',
			'Jul 12, 2026 · ACME Energy · Invoice · paid · tax 2026 · +2 — Invoice [P8].pdf',
			'Jun 1, 2026 — Letter [P9].pdf',
		], self::sublines($result['entries']));
		self::assertSame([
			['correspondents', ['id__in' => '3', 'page_size' => 1]],
			['document_types', ['id__in' => '1', 'page_size' => 1]],
			['tags', ['id__in' => '1,4,2,5,6,8', 'page_size' => 6]],
		], $this->nameRequests);
	}

	/**
	 * A token that may not view tags gets HTTP 403 for them: the result shows as before,
	 * without tags, and the search has no problem.
	 */
	public function testTagsThatPaperlessRefusesLeaveTheResultAsBefore(): void {
		$client = $this->paperlessWithLists(
			['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice', 'created' => '2026-08-12', 'correspondent' => 3, 'document_type' => 1, 'tags' => [4]]]],
			['correspondents' => [3 => 'ACME Energy'], 'document_types' => [1 => 'Invoice'], 'tags' => 403],
		);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame(['Aug 12, 2026 · ACME Energy · Invoice — Invoice [P7].pdf'], self::sublines($result['entries']));
		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * The next search takes the names from the cache, also the knowledge that Paperless
	 * has none for an ID, and asks only for the names that the cache doesn't hold.
	 */
	public function testTheNextSearchTakesTheNamesFromTheCache(): void {
		$answer = ['count' => 2, 'next' => null, 'results' => [
			['id' => 7, 'title' => 'Invoice', 'correspondent' => 3, 'document_type' => 1],
			['id' => 8, 'title' => 'Renewal', 'correspondent' => 4, 'document_type' => 1],
		]];
		$lists = ['correspondents' => [3 => 'ACME Energy'], 'document_types' => [1 => 'Invoice']];
		$files = [7 => $this->file(7, 'Invoice [P7].pdf'), 8 => $this->file(8, 'Renewal [P8].pdf')];

		$this->provider($this->paperlessWithLists($answer, $lists), $files)->search($this->user(), $this->query('invoice'));
		$answer['results'][] = ['id' => 9, 'title' => 'Letter', 'correspondent' => 6];
		$files[9] = $this->file(9, 'Letter [P9].pdf');
		$lists['correspondents'][6] = 'City of Sampletown';
		$result = $this->provider($this->paperlessWithLists($answer, $lists), $files)
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame([
			'ACME Energy · Invoice — Invoice [P7].pdf',
			'Invoice — Renewal [P8].pdf',
			'City of Sampletown — Letter [P9].pdf',
		], self::sublines($result['entries']));
		self::assertSame([
			['correspondents', ['id__in' => '3,4', 'page_size' => 2]],
			['document_types', ['id__in' => '1', 'page_size' => 1]],
			['correspondents', ['id__in' => '6', 'page_size' => 1]],
		], $this->nameRequests);
	}

	/**
	 * Names only add to a result: without an answer, the results show without them, the
	 * other kind isn't asked for, and the history of the problems of the search stays as
	 * it is, as the search worked.
	 */
	public function testALookupOfNamesWithoutAnswerLeavesOutTheNames(): void {
		$client = $this->paperlessWithLists(
			['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice', 'created' => '2026-08-12', 'correspondent' => 3, 'document_type' => 1]]],
			['correspondents' => new RuntimeException('cURL error 28: Operation timed out'), 'document_types' => [1 => 'Invoice']],
		);

		$result = $this->provider($client, [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame(['Aug 12, 2026 — Invoice [P7].pdf'], self::sublines($result['entries']));
		self::assertSame([['correspondents', ['id__in' => '3', 'page_size' => 1]]], $this->nameRequests);
		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * A token that may not view the correspondents gets HTTP 403 for them: the types are
	 * still named, and the correspondents aren't asked for again while the cache knows.
	 */
	public function testAListThatPaperlessRefusesIsNotAskedForAgain(): void {
		$answer = ['count' => 1, 'next' => null, 'results' => [['id' => 7, 'title' => 'Invoice', 'correspondent' => 3, 'document_type' => 1]]];
		$lists = ['correspondents' => 403, 'document_types' => [1 => 'Invoice']];

		$this->provider($this->paperlessWithLists($answer, $lists), [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice'));
		$result = $this->provider($this->paperlessWithLists($answer, $lists), [7 => $this->file(7, 'Invoice [P7].pdf')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame(['Invoice — Invoice [P7].pdf'], self::sublines($result['entries']));
		self::assertSame([
			['correspondents', ['id__in' => '3', 'page_size' => 1]],
			['document_types', ['id__in' => '1', 'page_size' => 1]],
		], $this->nameRequests);
		self::assertSame(self::NO_PROBLEM, $this->problems());
	}

	/**
	 * The answers of a real Paperless-ngx 3.3.0 (tests/Unit/fixtures/README.md): the
	 * excerpt is text, and what the document says in angle brackets stays without
	 * becoming a tag.
	 */
	public function testTheAnswersOfPaperless330GiveTheirSublines(): void {
		$client = $this->paperlessWithLists(self::fixture('paperless-3.3.0-search.json'), [
			'correspondents' => self::fixture('paperless-3.3.0-correspondents.json'),
			'document_types' => self::fixture('paperless-3.3.0-document-types.json'),
		]);

		$result = $this->provider($client, [1 => $this->file(1, 'electricity [P1].txt', mimeType: 'text/plain'), 3 => $this->file(3, 'markup [P3].txt', mimeType: 'text/plain')])
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame([
			'Aug 12, 2026 · ACME Energy · Invoice — Electricity invoice E-2026-0812 for August 2026. O\'Brien & Sons "Power" < GmbH> Total due 84.30 EUR by September 2',
			'Aug 12, 2026 · Harbor & Sons < Insurance> — Plain letter number 7 about the invoice of the month. Filler line 001 of a long letter without anything special. Filler line 002 of a long letter',
		], self::sublines($result['entries']));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function measuredAnswers(): array {
		return [
			// The text of the document says <script>alert(1)</script>, escaped by Paperless.
			'escaped script' => ['paperless-3.3.0-search-script.json', 'Aug 12, 2026 — line 120 of a long letter without anything special. It quotes < script>alert(1)< /script> as text for the invoice'],
			'note' => ['paperless-3.3.0-search-note.json', 'Aug 12, 2026 — Note: Paid the invoice by bank transfer, O\'Brien & < Partner'],
			'Paperless-ngx 2' => ['paperless-2.20.13-search.json', 'Aug 12, 2026 — Electricity invoice E-2026-0812 for August 2026. O\'Brien & Sons "Power" < GmbH> Total'],
			'API version 5' => ['paperless-2.20.13-search-api-5.json', 'Aug 12, 2026 — Electricity invoice E-2026-0812 for August 2026. O\'Brien & Sons "Power" < GmbH> Total'],
		];
	}

	#[DataProvider('measuredAnswers')]
	public function testAMeasuredAnswerOfPaperlessGivesItsSubline(string $fixture, string $subline): void {
		$answer = json_decode(self::fixture($fixture), true, 512, JSON_THROW_ON_ERROR);
		$files = [];
		foreach ($answer['results'] as $document) {
			$files[$document['id']] = $this->file($document['id'], $document['title'] . ' [P' . $document['id'] . '].txt', mimeType: 'text/plain');
		}

		$result = $this->provider($this->paperlessWithLists(self::fixture($fixture), ['correspondents' => '{"results":[]}']), $files)
			->search($this->user(), $this->query('invoice'))
			->jsonSerialize();

		self::assertSame([$subline], self::sublines($result['entries']));
		self::assertDoesNotMatchRegularExpression('/<[a-z\/!?]/i', $subline);
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
		bool $previews = false,
		string $locale = 'en',
		string $timeZone = 'America/New_York',
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
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/paperless_unified_search/img/app.svg');
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => match ($route) {
				'files.view.showFile' => 'https://cloud.example.com/f/' . $parameters['fileid'],
				'core.Preview.getPreviewByFileId' => 'https://cloud.example.com/index.php/core/preview?' . http_build_query($parameters),
			},
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.com' . $url);

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0');

		$configService = new ConfigService($config, $credentials, $this->accounts('archive', 'sync'), $this->createStub(LoggerInterface::class), $this->createStub(ICacheFactory::class));
		$paperlessApi = new PaperlessApiService($configService, $clientService, $this->diagnostics($config), $this->clock(), $this->appManager());

		return new PaperlessSearchProvider(
			$paperlessApi,
			new NextcloudFileLocator($root, $this->createStub(LoggerInterface::class)),
			$l10n,
			$urlGenerator,
			$request,
			$logger ?? $this->createStub(LoggerInterface::class),
			$configService,
			$this->diagnostics($config),
			$clock ?? $this->clock(),
			...$this->presentation($paperlessApi, $configService, $urlGenerator, $previews, $locale, $timeZone),
			alwaysSearch: new AlwaysSearch($configService, $this->userConfig()),
			userSession: $this->session('dennis'),
		);
	}

	/**
	 * The services with which the provider draws an entry: the names of correspondents
	 * and types, with the cache of the test; the pictures of the files, with the URLs of
	 * $urlGenerator, by default a stub: previews, which Nextcloud draws of no type of
	 * file by default, and of PDF files with $previews, and the icons of the types of files;
	 * and the dates, as Nextcloud formats them for a user in New York with the locale en
	 * or de; and the timezone of the user dennis, New York by default, and UTC for anyone
	 * else, with each question for it in $this->timeZoneRequests.
	 *
	 * @return array{PaperlessNames, FilePictures, IDateTimeFormatter, IDateTimeZone}
	 */
	private function presentation(PaperlessApiService $paperlessApi, ConfigService $configService, ?IURLGenerator $urlGenerator = null, bool $previews = false, string $locale = 'en', string $timeZone = 'America/New_York'): array {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			fn (): MemoryCache => $this->cache ??= new MemoryCache(static fn (): int => self::NOW),
		);

		$previewManager = $this->createStub(IPreview::class);
		$previewManager->method('isMimeSupported')->willReturnCallback(
			static fn (string $mimeType): bool => $previews && $mimeType === 'application/pdf',
		);

		$mimeTypeDetector = $this->createStub(IMimeTypeDetector::class);
		$mimeTypeDetector->method('mimeTypeIcon')->willReturnCallback(
			static fn (string $mimeType): string => $mimeType === 'application/pdf' ? '/core/img/filetypes/application-pdf.svg' : '',
		);

		$dateTimeFormatter = $this->createStub(IDateTimeFormatter::class);
		$dateTimeFormatter->method('formatDate')->willReturnCallback(
			static function (DateTime $date, string $format, ?DateTimeZone $timeZone = null) use ($locale): string {
				self::assertSame('medium', $format);
				$date = (clone $date)->setTimezone($timeZone ?? new DateTimeZone('America/New_York'));

				return $date->format($locale === 'de' ? 'd.m.Y' : 'M j, Y');
			},
		);

		$dateTimeZone = $this->createStub(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturnCallback(
			function (bool|int $timestamp = false, ?string $userId = null) use ($timeZone): DateTimeZone {
				$this->timeZoneRequests[] = [$timestamp, $userId];

				return new DateTimeZone($userId === 'dennis' ? $timeZone : 'UTC');
			},
		);

		return [
			new PaperlessNames($paperlessApi, $configService, $cacheFactory, $this->createStub(LoggerInterface::class)),
			new FilePictures($previewManager, $mimeTypeDetector, $urlGenerator ?? $this->createStub(IURLGenerator::class)),
			$dateTimeFormatter,
			$dateTimeZone,
		];
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
	 * A Paperless that answers a search with this answer and its lists of correspondents,
	 * types and tags as Paperless-ngx 3.3.0 does: with the names of the IDs of the
	 * parameter id__in. A list is the names by ID, or the fields of each object by ID, such
	 * as is_inbox_tag of a tag, the body of an answer, the status of an answer that refuses
	 * it, or an error of a request without answer. The list and the query of every request
	 * for names land in $this->nameRequests.
	 *
	 * @param array<string, mixed>|string $answer the answer, or its body
	 * @param array<string, array<int, string|array<string, mixed>>|string|int|Throwable> $lists by path
	 */
	private function paperlessWithLists(array|string $answer, array $lists): IClient {
		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($answer, $lists): IResponse {
			if ($url === 'https://paperless.example.com/api/documents/') {
				return is_string($answer) ? $this->answer(200, $answer) : $this->response($answer);
			}

			$list = substr($url, strlen('https://paperless.example.com/api/'), -1);
			$this->nameRequests[] = [$list, $options['query']];
			self::assertSame(3, $options['timeout']);
			$names = $lists[$list] ?? [];
			if ($names instanceof Throwable) {
				throw $names;
			}
			if (is_int($names)) {
				return $this->answer($names, '{"detail":"You do not have permission to perform this action."}');
			}
			if (is_string($names)) {
				return $this->answer(200, $names);
			}

			$results = [];
			foreach (array_map('intval', explode(',', (string)$options['query']['id__in'])) as $id) {
				if (isset($names[$id])) {
					$fields = is_array($names[$id]) ? $names[$id] : ['name' => $names[$id]];
					$results[] = ['id' => $id, 'slug' => 'slug-' . $id] + $fields + ['document_count' => 1];
				}
			}

			return $this->response(['count' => count($results), 'next' => null, 'previous' => null, 'results' => $results]);
		});

		return $client;
	}

	/**
	 * The body of an answer of a real Paperless-ngx in tests/Unit/fixtures.
	 */
	private static function fixture(string $name): string {
		$body = file_get_contents(__DIR__ . '/../fixtures/' . $name);
		self::assertIsString($body);

		return $body;
	}

	/**
	 * @param list<SearchResultEntry> $entries
	 * @return list<string>
	 */
	private static function sublines(array $entries): array {
		return array_map(static fn (SearchResultEntry $entry): string => $entry->jsonSerialize()['subline'], $entries);
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

	private function file(int $documentId, string $name, string $owner = 'archive', string $mimeType = 'application/pdf'): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/dennis/files/Paperless/' . $name);
		$file->method('getId')->willReturn(1000 + $documentId);
		$file->method('getOwner')->willReturn($this->account($owner));
		$file->method('getMimetype')->willReturn($mimeType);
		$file->method('getParent')->willReturn($this->createStub(Folder::class));

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
		$file->method('getParent')->willReturn($this->createStub(Folder::class));

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

	/**
	 * @param array<string, mixed> $filters the values of the filters of the search by their
	 *                                      names, such as since, as Nextcloud builds them
	 */
	private function query(string $term, int|string|null $cursor = null, int $limit = 10, array $filters = []): ISearchQuery {
		$query = $this->createStub(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getCursor')->willReturn($cursor);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getFilter')->willReturnCallback(function (string $name) use ($filters): ?IFilter {
			if (!array_key_exists($name, $filters)) {
				return null;
			}

			$filter = $this->createStub(IFilter::class);
			$filter->method('get')->willReturn($filters[$name]);

			return $filter;
		});

		return $query;
	}

	/**
	 * The filters of a request to Paperless, without the term, the page and the fields.
	 *
	 * @param array<string, int|string> $request
	 * @return array<string, int|string>
	 */
	private static function filtersOf(array $request): array {
		return array_diff_key($request, array_flip(['query', 'text', 'page', 'page_size', 'fields']));
	}
}
