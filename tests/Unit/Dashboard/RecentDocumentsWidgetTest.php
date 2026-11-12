<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Dashboard;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Dashboard\RecentDocumentsWidget;
use OCA\PaperlessUnifiedSearch\Service\FilePictures;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessNames;
use OCA\PaperlessUnifiedSearch\Service\RecentDocuments;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Owners;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RecentDocumentsWidgetTest extends TestCase {
	use Accounts;
	use Owners;
	use AppServices;
	use Files;

	private const NOW = 1791500000;

	/**
	 * The newest documents of Paperless, newest first, as it sends them with the fields of
	 * the dashboard: jamie may open the files of 412 and 389, no file stands for 999, and
	 * the file of 371 belongs to another account than the archive account.
	 */
	private const DOCUMENTS = [
		['id' => 412, 'title' => 'Electricity invoice - August 2026', 'added' => '2026-08-13T09:12:45.118731+02:00', 'correspondent' => 3, 'document_type' => 1],
		['id' => 999, 'title' => 'Not synchronized yet', 'added' => '2026-08-01T08:00:00+02:00', 'correspondent' => 9, 'document_type' => 1],
		['id' => 389, 'title' => 'Home insurance renewal', 'added' => '2026-07-22T10:00:00+02:00', 'correspondent' => 5, 'document_type' => 2],
		['id' => 371, 'title' => 'Internet invoice - July 2026', 'added' => '2026-07-04T10:00:00+02:00', 'correspondent' => 4, 'document_type' => 1],
	];

	/** The names that Paperless lists, by the path of their list. */
	private const NAMES = [
		'correspondents' => [3 => 'Northstar Energy', 4 => 'Fiberlink', 5 => 'Harbor Mutual', 9 => 'Hidden'],
		'document_types' => [1 => 'Bill', 2 => 'Insurance'],
	];

	/** The item of document 412 for jamie, as the dashboard's API sends it. */
	private const ELECTRICITY = [
		'subtitle' => 'Aug 13, 2026 · Northstar Energy · Bill',
		'title' => 'Electricity invoice - August 2026',
		'link' => 'https://cloud.example.com/f/2001',
		'iconUrl' => 'https://cloud.example.com/index.php/core/preview?x=64&y=64&fileId=2001&mimeFallback=1',
		'overlayIconUrl' => '',
		'sinceId' => '2026-08-13T09:12:45.118731+02:00',
	];

	/** The item of document 389 for jamie. */
	private const INSURANCE = [
		'subtitle' => 'Jul 22, 2026 · Harbor Mutual · Insurance',
		'title' => 'Home insurance renewal',
		'link' => 'https://cloud.example.com/f/2002',
		'iconUrl' => 'https://cloud.example.com/index.php/core/preview?x=64&y=64&fileId=2002&mimeFallback=1',
		'overlayIconUrl' => '',
		'sinceId' => '2026-07-22T10:00:00+02:00',
	];

	private MemoryCache $cache;

	/** @var list<array{DateTime, string, ?DateTimeZone}> each date that the dashboard formatted, with its format and timezone */
	private array $formatted = [];

	protected function setUp(): void {
		$this->cache = new MemoryCache(static fn (): int => self::NOW);
		$this->connected('archive');
		$this->pdf('/jamie/files/Paperless/Northstar Energy/Bill/2026/Electricity invoice - August 2026 [P412].pdf', 2001);
		$this->pdf('/jamie/files/Paperless/Harbor Mutual/Insurance/2026/Home insurance renewal [P389].pdf', 2002);
		$this->pdf('/jamie/files/Internet invoice [P371].pdf', 2003, owner: 'jamie');
	}

	public function testDescribesItselfToTheDashboard(): void {
		$widget = $this->widget($this->paperless());

		self::assertSame('paperless_unified_search-recent', $widget->getId());
		self::assertSame(RecentDocumentsWidget::ID, $widget->getId());
		self::assertSame('New in Paperless', $widget->getTitle());
		self::assertSame(30, $widget->getOrder());
		self::assertSame('icon-files-dark', $widget->getIconClass());
		// A dark icon, which the dark theme and the apps for phones invert.
		self::assertSame('https://cloud.example.com/apps/paperless_unified_search/img/app-dark.svg', $widget->getIconUrl());
		self::assertNull($widget->getUrl());
		// Nextcloud draws the widget from its items: it loads no script.
		$widget->load();
		self::assertSame([], $this->requests);
	}

	/**
	 * @return array<string, array{string, ?string, string, bool}>
	 */
	public static function setups(): array {
		return [
			'set up' => ['https://paperless.example.com', 'TEST_VALUE', 'archive', true],
			'without Paperless' => ['', 'TEST_VALUE', 'archive', false],
			'without a token' => ['https://paperless.example.com', null, 'archive', false],
			'without an archive account' => ['https://paperless.example.com', 'TEST_VALUE', '', false],
			'with an archive account that does not exist' => ['https://paperless.example.com', 'TEST_VALUE', 'removed', false],
		];
	}

	/**
	 * Nextcloud offers the widget only where the search works: with a Paperless to ask and
	 * an archive account whose files stand for its documents.
	 */
	#[DataProvider('setups')]
	public function testIsOfferedOnceTheAppIsSetUp(string $url, ?string $token, string $archiveOwner, bool $offered): void {
		$this->settings[AppConstants::APP_ID . '.paperless_url'] = $url;
		$this->settings[AppConstants::APP_ID . '.archive_owner'] = $archiveOwner;
		$this->storedToken = $token;

		self::assertSame($offered, $this->widget($this->paperless())->isEnabled());
		self::assertSame([], $this->requests);
	}

	/**
	 * The documents that Paperless added last, newest first, of which the user sees those
	 * whose file of the archive account they can open, each with the preview of its file,
	 * the day that Paperless added it, its correspondent, its type and its tags.
	 */
	public function testListsTheNewestDocumentsThatTheUserCanOpen(): void {
		$items = $this->widget($this->paperless(...$this->answers(self::DOCUMENTS)))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame(['items' => [self::ELECTRICITY, self::INSURANCE], 'emptyContentMessage' => '', 'halfEmptyContentMessage' => ''], $items);
		// The names of the documents shown, one request per kind.
		self::assertSame([
			'https://paperless.example.com/api/documents/',
			'https://paperless.example.com/api/correspondents/',
			'https://paperless.example.com/api/document_types/',
		], array_column($this->requests, 0));
		self::assertSame(['id__in' => '3,5', 'page_size' => 2], $this->requests[1][1]['query']);
		self::assertSame(['id__in' => '1,2', 'page_size' => 2], $this->requests[2][1]['query']);
		// The day of a moment in the timezone of the user, which Nextcloud's formatter knows.
		self::assertSame([['2026-08-13T09:12:45+02:00', 'medium', null], ['2026-07-22T10:00:00+02:00', 'medium', null]], array_map(
			static fn (array $call): array => [$call[0]->format(DATE_ATOM), $call[1], $call[2]],
			$this->formatted,
		));
	}

	/**
	 * When Paperless Sync gives each owner an archive (decision 0013), jamie's own file of
	 * 371 stands for it, as Paperless names jamie its owner, with one request for the users
	 * of Paperless.
	 */
	public function testListsTheOwnFilesOfTheUserOfTheOwner(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$documents = self::DOCUMENTS;
		$documents[3]['owner'] = 5;
		[$newest, $correspondents, $types] = $this->answers($documents, self::NAMES);
		$users = $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie']]]));

		$items = $this->widget($this->paperless($newest, $users, $correspondents, $types))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame(['Electricity invoice - August 2026', 'Home insurance renewal', 'Internet invoice - July 2026'], array_column($items['items'], 'title'));
		self::assertSame('https://paperless.example.com/api/users/', $this->requests[1][0]);
	}

	/**
	 * The newest documents are the same for every user: the dashboards of a minute cost
	 * one request to Paperless, and its names come from the cache too.
	 */
	public function testTheDashboardsOfAMinuteCostOneRequest(): void {
		$this->pdf('/dennis/files/Electricity invoice - August 2026 [P412].pdf', 3001);
		$client = $this->paperless(...$this->answers(self::DOCUMENTS));

		$this->widget($client)->getItemsV2('jamie');
		$items = $this->widget($client)->getItemsV2('dennis')->jsonSerialize();

		self::assertSame(['Electricity invoice - August 2026'], array_column($items['items'], 'title'));
		self::assertCount(3, $this->requests);
	}

	/**
	 * @return array<string, array{int, list<string>}>
	 */
	public static function limits(): array {
		return [
			'one' => [1, ['Electricity invoice - August 2026']],
			'none, which is one' => [0, ['Electricity invoice - August 2026']],
			'more than there are' => [30, ['Electricity invoice - August 2026', 'Home insurance renewal']],
		];
	}

	/**
	 * @param list<string> $titles
	 */
	#[DataProvider('limits')]
	public function testShowsAtMostAsManyAsTheDashboardAsksFor(int $limit, array $titles): void {
		$items = $this->widget($this->paperless(...$this->answers(self::DOCUMENTS)))->getItemsV2('jamie', null, $limit)->jsonSerialize();

		self::assertSame($titles, array_column($items['items'], 'title'));
	}

	/**
	 * The dashboard asks for 7 items unless it names another number, as Nextcloud's web
	 * page does.
	 */
	public function testShowsSevenDocumentsByDefault(): void {
		$documents = [];
		foreach (range(501, 509) as $id) {
			$documents[] = ['id' => $id, 'title' => 'Document ' . $id, 'added' => '2026-09-01T10:00:00+02:00'];
			$this->pdf('/jamie/files/Paperless/Document [P' . $id . '].pdf', $id);
		}

		$items = $this->widget($this->paperless($this->documents($documents)))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame(['Document 501', 'Document 502', 'Document 503', 'Document 504', 'Document 505', 'Document 506', 'Document 507'], array_column($items['items'], 'title'));
	}

	/**
	 * A user whose documents are older than a page of documents of others, such as after one
	 * import of 25 documents by another user, still sees them: while too few of the newest
	 * documents have a file that the user can open, the dashboard asks for the next page.
	 */
	public function testAPageOfDocumentsOfOthersDoesNotEmptyTheWidget(): void {
		[, $correspondents, $types] = $this->answers(self::DOCUMENTS);
		$client = $this->paperless($this->page(self::others(1001), true), $this->documents(self::DOCUMENTS), $correspondents, $types);

		$items = $this->widget($client)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([self::ELECTRICITY, self::INSURANCE], $items['items']);
		self::assertSame([1, 2], array_map(static fn (array $request): mixed => $request[1]['query']['page'], array_slice($this->requests, 0, 2)));
	}

	/**
	 * A page that holds as many documents as the dashboard shows, which the user can open,
	 * costs no further page.
	 */
	public function testAsksForNoFurtherPageOnceAPageHoldsEnough(): void {
		$client = $this->paperless($this->page(self::DOCUMENTS, true), ...array_slice($this->answers(self::DOCUMENTS), 1));

		$items = $this->widget($client)->getItemsV2('jamie', null, 2)->jsonSerialize();

		self::assertSame([self::ELECTRICITY, self::INSURANCE], $items['items']);
		self::assertCount(3, $this->requests);
	}

	/**
	 * The dashboard asks for the 100 newest documents at most, four pages, and then says
	 * that none of them has a file that the user can open.
	 */
	public function testAsksForFourPagesAtMost(): void {
		$client = $this->paperless(...array_map(fn (int $page): IResponse => $this->page(self::others(1001 + 25 * $page), true), range(0, 3)));

		$items = $this->widget($client)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertSame('None of the newest Paperless documents has a file that you can open.', $items['emptyContentMessage']);
		self::assertSame(4, RecentDocuments::MOST_PAGES);
		self::assertSame([1, 2, 3, 4], array_map(static fn (array $request): mixed => $request[1]['query']['page'], $this->requests));
	}

	/**
	 * A slow Paperless keeps nobody waiting for four pages in turn: the dashboard asks for no
	 * further page once five seconds went, and shows what it found until then.
	 */
	public function testAsksForNoFurtherPageAfterFiveSeconds(): void {
		$client = $this->paperless(...array_map(fn (int $page): IResponse => $this->page(self::others(1001 + 25 * $page), true), range(0, 1)));

		$items = $this->widget($client, clock: $this->slowClock())->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertSame('None of the newest Paperless documents has a file that you can open.', $items['emptyContentMessage']);
		self::assertSame([1, 2], array_map(static fn (array $request): mixed => $request[1]['query']['page'], $this->requests));
	}

	/**
	 * Once five seconds went, the dashboard doesn't ask for the newest documents of the
	 * user's owner either.
	 */
	public function testAsksNoOwnerAfterFiveSeconds(): void {
		$this->eachOwnerHasAnArchive();
		$users = $this->answer(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie'], ['id' => 6, 'username' => 'sam']]]));
		$pages = array_map(fn (int $page): IResponse => $this->page(self::others(1001 + 25 * $page, ['owner' => 6]), true), range(0, 1));

		$items = $this->widget($this->paperless($pages[0], $users, $pages[1]), clock: $this->slowClock())->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertCount(3, $this->requests);
		self::assertArrayNotHasKey('owner__id__in', $this->requests[2][1]['query']);
	}

	/**
	 * A page further that Paperless doesn't send ends the list with the documents found so
	 * far, without a message that Paperless can't be reached.
	 */
	public function testAPageFurtherThatFailsEndsTheList(): void {
		$client = $this->paperless(
			$this->page([self::DOCUMENTS[0]], true),
			$this->answer(500, '<h1>Server Error (500)</h1>'),
			...array_slice($this->answers([self::DOCUMENTS[0]]), 1),
		);

		self::assertSame(
			['items' => [self::ELECTRICITY], 'emptyContentMessage' => '', 'halfEmptyContentMessage' => ''],
			$this->widget($client)->getItemsV2('jamie')->jsonSerialize(),
		);
		self::assertCount(4, $this->requests);
	}

	/**
	 * With the sinceId of an item, a page that holds a document that Paperless didn't add
	 * after it is the last that the dashboard asks for: every further one is older.
	 */
	public function testNoPageFurtherThanADocumentAddedBeforeTheSinceId(): void {
		$client = $this->paperless($this->page(self::DOCUMENTS, true), ...array_slice($this->answers(self::DOCUMENTS), 1));

		$items = $this->widget($client)->getItemsV2('jamie', '2026-08-01T00:00:00+02:00')->jsonSerialize();

		self::assertSame([self::ELECTRICITY], $items['items']);
		self::assertCount(3, $this->requests);
	}

	/**
	 * When Paperless Sync gives each owner an archive (decision 0013), a user who can open
	 * too few of the 100 newest documents gets the newest of their own owner as well, which
	 * Paperless finds with a filter by the owner: here jamie, who is 5 in Paperless.
	 */
	public function testAfterFourPagesTheNewestDocumentsOfTheOwnerOfTheUserFollow(): void {
		$this->eachOwnerHasAnArchive();
		$users = $this->answer(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie'], ['id' => 6, 'username' => 'sam']]]));
		$pages = array_map(fn (int $page): IResponse => $this->page(self::others(1001 + 25 * $page, ['owner' => 6]), true), range(0, 3));
		[$own, $correspondents, $types] = $this->answers([['owner' => 5] + self::DOCUMENTS[0], ['owner' => 5] + self::DOCUMENTS[3]]);

		$client = $this->paperless($pages[0], $users, $pages[1], $pages[2], $pages[3], $own, $correspondents, $types);
		$items = $this->widget($client)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame(['Electricity invoice - August 2026', 'Internet invoice - July 2026'], array_column($items['items'], 'title'));
		self::assertSame('https://paperless.example.com/api/users/', $this->requests[1][0]);
		self::assertArrayNotHasKey('owner__id__in', $this->requests[4][1]['query']);
		self::assertSame('5', $this->requests[5][1]['query']['owner__id__in']);
		self::assertSame(1, $this->requests[5][1]['query']['page']);
		self::assertCount(8, $this->requests);
	}

	/**
	 * A page further that Paperless doesn't send ends the list there: no further page, and no
	 * documents of the user's own owner, which would come after the documents of that page.
	 */
	public function testAPageFurtherThatFailsAsksForNoDocumentsOfAnOwner(): void {
		$this->eachOwnerHasAnArchive();
		$users = $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie']]]));

		$client = $this->paperless($this->page(self::others(1001, ['owner' => 5]), true), $users, $this->answer(500, '<h1>Server Error (500)</h1>'));
		$items = $this->widget($client)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertSame('None of the newest Paperless documents has a file that you can open.', $items['emptyContentMessage']);
		self::assertCount(3, $this->requests);
	}

	/**
	 * A user who can open as many of the newest documents as the dashboard shows gets no
	 * documents of their own owner, even when Paperless has older ones.
	 */
	public function testEnoughOfTheNewestDocumentsAskForNoDocumentsOfAnOwner(): void {
		$this->eachOwnerHasAnArchive();

		$client = $this->paperless($this->page([self::DOCUMENTS[0]], true), ...array_slice($this->answers([self::DOCUMENTS[0]]), 1));
		$items = $this->widget($client)->getItemsV2('jamie', null, 1)->jsonSerialize();

		self::assertSame([self::ELECTRICITY], $items['items']);
		self::assertCount(3, $this->requests);
	}

	/**
	 * A user without a user of Paperless gets no request by an owner.
	 */
	public function testAUserWithoutAUserOfPaperlessGetsNoDocumentsOfAnOwner(): void {
		$this->eachOwnerHasAnArchive();
		$users = $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 6, 'username' => 'sam']]]));
		$pages = array_map(fn (int $page): IResponse => $this->page(self::others(1001 + 25 * $page, ['owner' => 6]), true), range(0, 3));

		$items = $this->widget($this->paperless($pages[0], $users, $pages[1], $pages[2], $pages[3]))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertCount(5, $this->requests);
	}

	/**
	 * A user with tags gets the documents with one of them whoever owns them (decision 0015):
	 * the pages of those documents, and no request by an owner.
	 */
	public function testAUserWithTagsGetsNoDocumentsOfAnOwner(): void {
		$this->eachOwnerHasAnArchive();
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$tags = $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 8, 'name' => 'Katja']]]));
		$pages = array_map(fn (int $page): IResponse => $this->page(self::others(1001 + 25 * $page, ['owner' => 5, 'tags' => [8]]), true), range(0, 3));

		$items = $this->widget($this->paperless($tags, ...$pages))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertCount(5, $this->requests);
		self::assertSame(['8', '8', '8', '8'], array_map(static fn (array $request): mixed => $request[1]['query']['tags__id__in'], array_slice($this->requests, 1)));
	}

	/**
	 * A user whom Paperless Sync lets see only the documents with some tags (decision 0015)
	 * gets the newest of those, through the files of the archive account or their own
	 * copies, which Paperless Sync writes while the archive account is its target user. A
	 * document without one of their tags stays out, also when Paperless sends it anyway.
	 */
	public function testAUserWithTagsGetsTheNewestDocumentsWithOneOfThem(): void {
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$tags = $this->answer(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [['id' => 1, 'name' => 'Inbox'], ['id' => 8, 'name' => 'Katja']]]));
		$documents = [
			['tags' => [8]] + self::DOCUMENTS[0],
			['tags' => [1]] + self::DOCUMENTS[2],
			['tags' => [1, 8]] + self::DOCUMENTS[3],
		];

		$items = $this->widget($this->paperless($tags, ...$this->answers($documents)))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame(['Electricity invoice - August 2026', 'Internet invoice - July 2026'], array_column($items['items'], 'title'));
		self::assertSame('8', $this->requests[1][1]['query']['tags__id__in']);
	}

	/**
	 * While Paperless has none of their tags, such a user gets no document, and Paperless
	 * isn't asked for its newest documents.
	 */
	public function testAUserWithTagsThatPaperlessDoesNotHaveGetsNothing(): void {
		$this->settings['paperless_sync.user_tags'] = 'jamie = Kattja';
		$tags = $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 8, 'name' => 'Katja']]]));

		$items = $this->widget($this->paperless($tags))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertSame('None of the newest Paperless documents has a file that you can open.', $items['emptyContentMessage']);
		self::assertCount(1, $this->requests);
	}

	/**
	 * A document that Paperless sends without an ID as a number stands for no file.
	 */
	public function testADocumentWithoutANumberAsItsIdHasNoItem(): void {
		$documents = [
			['title' => 'No ID'] + self::DOCUMENTS[0],
			['id' => '412'] + self::DOCUMENTS[0],
			self::DOCUMENTS[2],
		];
		unset($documents[0]['id']);

		$items = $this->widget($this->paperless(...$this->answers($documents)))->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([self::INSURANCE], $items['items']);
	}

	public function testTheNamesAreOnlyThoseOfTheDocumentsShown(): void {
		$this->widget($this->paperless(...$this->answers(self::DOCUMENTS)))->getItemsV2('jamie', null, 1);

		self::assertSame(['id__in' => '3', 'page_size' => 1], $this->requests[1][1]['query']);
		self::assertSame(['id__in' => '1', 'page_size' => 1], $this->requests[2][1]['query']);
	}

	/**
	 * A user who can open none of the newest documents sees a widget that says so, and
	 * costs no request for names.
	 */
	public function testAUserWithoutSharedDocumentsSeesAnEmptyWidget(): void {
		$items = $this->widget($this->paperless($this->documents(self::DOCUMENTS)))->getItemsV2('dennis')->jsonSerialize();

		self::assertSame([
			'items' => [],
			'emptyContentMessage' => 'None of the newest Paperless documents has a file that you can open.',
			'halfEmptyContentMessage' => '',
		], $items);
		self::assertCount(1, $this->requests);
	}

	public function testAPaperlessWithoutDocumentsLooksForNoFile(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::never())->method('getUserFolder');

		$items = $this->widget($this->paperless($this->documents([])), root: $root)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertSame('None of the newest Paperless documents has a file that you can open.', $items['emptyContentMessage']);
	}

	/**
	 * A Paperless that fails costs the dashboard no more than an empty widget that says
	 * so, and the next minute no further request.
	 */
	public function testAPaperlessThatFailsShowsAnEmptyWidgetThatSaysSo(): void {
		$client = $this->paperless($this->answer(500, '<h1>Server Error (500)</h1>'));

		foreach (['jamie', 'dennis'] as $user) {
			self::assertSame([
				'items' => [],
				'emptyContentMessage' => 'Paperless can\'t be reached right now.',
				'halfEmptyContentMessage' => '',
			], $this->widget($client)->getItemsV2($user)->jsonSerialize());
		}
		self::assertCount(1, $this->requests);
	}

	public function testFilesThatFailShowAnEmptyWidgetThatSaysSo(): void {
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willThrowException(new RuntimeException('The storage is not available.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('The dashboard could not show the newest Paperless documents ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => RuntimeException::class,
			]);

		$items = $this->widget($this->paperless($this->documents(self::DOCUMENTS)), $logger, $root)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
		self::assertSame('Paperless can\'t be reached right now.', $items['emptyContentMessage']);
	}

	/**
	 * A file that fails to answer costs only its own item, which the log counts.
	 */
	public function testAFileThatFailsCostsOnlyItsItem(): void {
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Electricity invoice [P412].pdf');
		$broken->method('getPath')->willReturn('/jamie/files/A/Electricity invoice [P412].pdf');
		$broken->method('getOwner')->willReturn($this->user('archive'));
		$broken->method('getMimetype')->willThrowException(new RuntimeException('The storage is not available.'));
		$broken->method('getParent')->willReturn($this->createStub(Folder::class));
		$this->visible['jamie'][] = $broken;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('The dashboard left out {count} Paperless document(s) whose files failed to answer ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => 1,
				'errorType' => RuntimeException::class,
			]);

		$items = $this->widget($this->paperless(...$this->answers(self::DOCUMENTS)), $logger)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([self::INSURANCE], $items['items']);
	}

	/**
	 * Of several files that fail, the log names the error of the first.
	 */
	public function testTheLogNamesTheErrorOfTheFirstFileThatFails(): void {
		foreach ([412 => new RuntimeException('The storage is not available.'), 389 => new LogicException('No such file.')] as $id => $error) {
			$broken = $this->createStub(File::class);
			$broken->method('getName')->willReturn('Broken [P' . $id . '].pdf');
			$broken->method('getPath')->willReturn('/jamie/files/A/Broken [P' . $id . '].pdf');
			$broken->method('getOwner')->willReturn($this->user('archive'));
			$broken->method('getMimetype')->willThrowException($error);
			$broken->method('getParent')->willReturn($this->createStub(Folder::class));
			$this->visible['jamie'][] = $broken;
		}
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(self::anything(), [
				'app' => AppConstants::APP_ID,
				'count' => 2,
				'errorType' => RuntimeException::class,
			]);

		$items = $this->widget($this->paperless(...$this->answers(self::DOCUMENTS)), $logger)->getItemsV2('jamie')->jsonSerialize();

		self::assertSame([], $items['items']);
	}

	/**
	 * @return array<string, array{?string, list<string>}>
	 */
	public static function moments(): array {
		$all = ['Electricity invoice - August 2026', 'Home insurance renewal'];

		return [
			'none' => [null, $all],
			'empty, as the dashboard sends none' => ['', $all],
			'between the two' => ['2026-08-01T00:00:00+02:00', ['Electricity invoice - August 2026']],
			'the moment of the newest' => ['2026-08-13T09:12:45.118731+02:00', []],
			'in another timezone' => ['2026-07-22T07:59:59Z', $all],
			'no moment' => ['yesterday', $all],
		];
	}

	/**
	 * With the sinceId of an item, the dashboard asks only for the documents that Paperless
	 * added after it.
	 *
	 * @param list<string> $titles
	 */
	#[DataProvider('moments')]
	public function testOnlyDocumentsAddedAfterTheSinceIdOfAnItem(?string $since, array $titles): void {
		$client = $this->paperless(...($titles === [] ? [$this->documents(self::DOCUMENTS)] : $this->answers(self::DOCUMENTS)));

		$items = $this->widget($client)->getItemsV2('jamie', $since)->jsonSerialize();

		self::assertSame($titles, array_column($items['items'], 'title'));
	}

	/**
	 * @return array<string, array{array<string, mixed>, string, string, string}>
	 */
	public static function documentsWithLess(): array {
		return [
			'no title' => [['title' => ' '], 'Electricity invoice - August 2026 [P412].pdf', 'Aug 13, 2026 · Northstar Energy · Bill', '2026-08-13T09:12:45.118731+02:00'],
			'a title that is no text' => [['title' => 42], 'Electricity invoice - August 2026 [P412].pdf', 'Aug 13, 2026 · Northstar Energy · Bill', '2026-08-13T09:12:45.118731+02:00'],
			'a title on two lines' => [['title' => "Electricity invoice\n August 2026"], 'Electricity invoice August 2026', 'Aug 13, 2026 · Northstar Energy · Bill', '2026-08-13T09:12:45.118731+02:00'],
			'no moment' => [['added' => null], 'Electricity invoice - August 2026', 'Northstar Energy · Bill', ''],
			'a moment in UTC' => [['added' => '2026-08-13T07:12:45Z'], 'Electricity invoice - August 2026', 'Aug 13, 2026 · Northstar Energy · Bill', '2026-08-13T07:12:45Z'],
			'a day that does not exist' => [['added' => '2026-02-30T10:00:00+02:00'], 'Electricity invoice - August 2026', 'Northstar Energy · Bill', ''],
			'a month that does not exist' => [['added' => '2026-13-01T10:00:00+02:00'], 'Electricity invoice - August 2026', 'Northstar Energy · Bill', ''],
			'a day without a time' => [['added' => '2026-08-13'], 'Electricity invoice - August 2026', 'Northstar Energy · Bill', ''],
			'no correspondent and no type' => [['correspondent' => null, 'document_type' => null], 'Electricity invoice - August 2026', 'Aug 13, 2026', '2026-08-13T09:12:45.118731+02:00'],
			'a correspondent without a name' => [['correspondent' => 77], 'Electricity invoice - August 2026', 'Aug 13, 2026 · Bill', '2026-08-13T09:12:45.118731+02:00'],
		];
	}

	/**
	 * What Paperless doesn't send, the item leaves out: the title becomes the name of the
	 * file, and the line names what is known.
	 *
	 * @param array<string, mixed> $changes
	 */
	#[DataProvider('documentsWithLess')]
	public function testAnItemShowsWhatPaperlessSends(array $changes, string $title, string $subtitle, string $sinceId): void {
		$document = $changes + self::DOCUMENTS[0];
		$names = [
			'correspondents' => [3 => 'Northstar Energy'],
			'document_types' => [1 => 'Bill'],
		];

		$item = $this->widget($this->paperless(...$this->answers([$document], $names)))->getItemsV2('jamie')->jsonSerialize()['items'][0];

		self::assertSame([$title, $subtitle, $sinceId], [$item['title'], $item['subtitle'], $item['sinceId']]);
	}

	/**
	 * The line of an item shows up to two tags of its document after its type, then how
	 * many more there are, as a result of the search does.
	 */
	public function testAnItemShowsUpToTwoTagsOfItsDocument(): void {
		$document = ['tags' => [4, 2, 6]] + self::DOCUMENTS[0];
		$names = [
			'correspondents' => [3 => 'Northstar Energy'],
			'document_types' => [1 => 'Bill'],
			'tags' => [2 => 'tax 2026', 4 => 'unpaid', 6 => 'warranty'],
		];

		$item = $this->widget($this->paperless(...$this->answers([$document], $names)))->getItemsV2('jamie')->jsonSerialize()['items'][0];

		self::assertSame('Aug 13, 2026 · Northstar Energy · Bill · unpaid · tax 2026 · +1', $item['subtitle']);
		self::assertStringStartsWith('https://paperless.example.com/api/tags/', $this->requests[3][0]);
	}

	/**
	 * Names come from Paperless as its users typed them: on one line, and without a tag of
	 * HTML, for any client of the dashboard.
	 */
	public function testNamesShowAsTextOnOneLine(): void {
		$names = [
			'correspondents' => [3 => "Harbor &\n Sons <Insurance>"],
			'document_types' => [1 => '<b>Bill</b>'],
		];

		$item = $this->widget($this->paperless(...$this->answers([self::DOCUMENTS[0]], $names)))->getItemsV2('jamie')->jsonSerialize()['items'][0];

		self::assertSame('Aug 13, 2026 · Harbor & Sons < Insurance> · < b>Bill< /b>', $item['subtitle']);
	}

	/**
	 * The user and the settings are known before Paperless is asked: an account that
	 * doesn't exist, or an app that is no longer set up, sees nothing.
	 */
	public function testAsksPaperlessOnlyForAUserOfASetUpApp(): void {
		$nothing = ['items' => [], 'emptyContentMessage' => '', 'halfEmptyContentMessage' => ''];
		self::assertSame($nothing, $this->widget($this->paperless())->getItemsV2('nobody')->jsonSerialize());

		$this->storedToken = null;
		self::assertSame($nothing, $this->widget($this->paperless())->getItemsV2('jamie')->jsonSerialize());

		$this->connected('');
		self::assertSame($nothing, $this->widget($this->paperless())->getItemsV2('jamie')->jsonSerialize());
		self::assertSame([], $this->requests);
	}

	/**
	 * More of the documents that others shared with a user are in Files; the archive
	 * account owns them all, so it gets no such link.
	 */
	public function testLinksTheFilesSharedWithTheUser(): void {
		$buttons = $this->widget($this->paperless())->getWidgetButtons('jamie');

		self::assertCount(1, $buttons);
		self::assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
		self::assertSame('https://cloud.example.com/apps/files/sharingin', $buttons[0]->getLink());
		self::assertSame('Files shared with you', $buttons[0]->getText());
		self::assertSame([], $this->widget($this->paperless())->getWidgetButtons('archive'));
	}

	/**
	 * A PDF file at a path of a user, which the archive account owns unless $owner says
	 * otherwise, with its ID.
	 */
	private function pdf(string $path, int $id, string $owner = 'archive'): void {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getPath')->willReturn($path);
		$file->method('getId')->willReturn($id);
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getOwner')->willReturn($this->user($owner));
		$file->method('getParent')->willReturn($this->createStub(Folder::class));
		$this->visible[explode('/', $path)[1]][] = $file;
	}

	/**
	 * The answers of Paperless to the dashboard: its newest documents, then the names of
	 * their correspondents and their types, as far as the request names them.
	 *
	 * @param list<array<string, mixed>> $documents
	 * @param array<string, array<int, string>> $names
	 * @return list<IResponse>
	 */
	private function answers(array $documents, array $names = self::NAMES): array {
		$answers = [$this->documents($documents)];
		foreach ($names as $list) {
			$answers[] = $this->answer(200, (string)json_encode(['count' => count($list), 'next' => null, 'previous' => null, 'results' => array_map(
				static fn (int $id, string $name): array => ['id' => $id, 'name' => $name],
				array_keys($list),
				array_values($list),
			)]));
		}

		return $answers;
	}

	/**
	 * Paperless Sync gives each owner an archive, with the archive account as its target user
	 * (decision 0013).
	 */
	private function eachOwnerHasAnArchive(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
	}

	/**
	 * A page of the newest documents, after which Paperless has another one when $more says so.
	 *
	 * @param list<array<string, mixed>> $documents
	 */
	private function page(array $documents, bool $more): IResponse {
		return $this->answer(200, (string)json_encode([
			'count' => 200,
			'next' => $more ? 'https://paperless.example.com/api/documents/?page=2' : null,
			'previous' => null,
			'results' => $documents,
		]));
	}

	/**
	 * A page of 25 documents of others, from the ID $from on, newer than DOCUMENTS, of which
	 * jamie can open none.
	 *
	 * @param array<string, mixed> $fields further fields of each document
	 * @return list<array<string, mixed>>
	 */
	private static function others(int $from, array $fields = []): array {
		return array_map(
			static fn (int $id): array => ['id' => $id, 'title' => 'Scan ' . $id, 'added' => '2026-09-01T10:00:00+02:00', 'correspondent' => null, 'document_type' => null] + $fields,
			range($from, $from + 24),
		);
	}

	/**
	 * A clock whose every look comes three seconds after the one before, as with a Paperless
	 * that takes three seconds for each answer.
	 */
	private function slowClock(): ITimeFactory {
		$seconds = self::NOW;
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('now')->willReturnCallback(static function () use (&$seconds): DateTimeImmutable {
			$now = new DateTimeImmutable('@' . $seconds);
			$seconds += 3;

			return $now;
		});

		return $clock;
	}

	private function widget(IClient $client, ?LoggerInterface $logger = null, ?IRootFolder $root = null, ?ITimeFactory $clock = null): RecentDocumentsWidget {
		$accounts = $this->accounts('archive', 'jamie', 'dennis');
		$configService = $this->configService($accounts);
		$paperlessApi = $this->paperlessApi($configService, $client);
		$logger ??= $this->createStub(LoggerInterface::class);

		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters): string => match ($route) {
				'files.view.showFile' => 'https://cloud.example.com/f/' . $parameters['fileid'],
				'files.view.indexView' => 'https://cloud.example.com/apps/files/' . $parameters['view'],
				'core.Preview.getPreviewByFileId' => 'https://cloud.example.com/index.php/core/preview?' . http_build_query($parameters),
			},
		);
		$urlGenerator->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/apps/' . $app . '/img/' . $image);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.com' . $url);

		$previewManager = $this->createStub(IPreview::class);
		$previewManager->method('isMimeSupported')->willReturn(true);

		// Nextcloud's formatter of dates for a user in Berlin, in English.
		$dateTimeFormatter = $this->createStub(IDateTimeFormatter::class);
		$dateTimeFormatter->method('formatDate')->willReturnCallback(
			function (DateTime $date, string $format = 'long', ?DateTimeZone $timeZone = null): string {
				$this->formatted[] = [$date, $format, $timeZone];

				return (clone $date)->setTimezone($timeZone ?? new DateTimeZone('Europe/Berlin'))->format('M j, Y');
			},
		);

		return new RecentDocumentsWidget(
			$l10n,
			$urlGenerator,
			$accounts,
			$configService,
			new RecentDocuments($paperlessApi, $configService, $cacheFactory, $logger),
			new NextcloudFileLocator($root ?? $this->rootFolder(), $logger),
			new PaperlessNames($paperlessApi, $configService, $cacheFactory, $logger),
			new FilePictures($previewManager, $this->createStub(IMimeTypeDetector::class), $urlGenerator),
			$dateTimeFormatter,
			$logger,
			$this->documentOwners($paperlessApi, $configService, $accounts, $cacheFactory),
			$this->userTags($paperlessApi, $configService, $cacheFactory),
			$clock ?? $this->clock(),
		);
	}
}
