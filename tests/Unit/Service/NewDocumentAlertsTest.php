<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use ArrayObject;
use DateTime;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Notification\Notifier;
use OCA\PaperlessUnifiedSearch\Service\NewDocumentAlerts;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Owners;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One run of the job of the notifications about new documents, with a Paperless whose
 * answers each test sets, the files of the users in memory and the notifications that
 * Nextcloud would send: what it asks Paperless, whom it notifies, and what it remembers.
 */
final class NewDocumentAlertsTest extends TestCase {
	use AppServices;
	use Owners;
	use Files;
	use UserPreferences;

	private const NOW = 1791500000;

	/** The day of NOW minus a day, on which the next request starts, in UTC. */
	private const FROM = '2026-10-07';

	private const PROGRESS = AppConstants::APP_ID . '.' . NewDocumentAlerts::PROGRESS;

	private int $now = self::NOW;

	/** @var list<array<string, mixed>> the notifications that went out */
	private array $sent = [];

	/** @var list<array{string, string, array<string, mixed>}> what went into the log: its level, its message and its context */
	private array $logged = [];

	/** @var list<string> the users whose files fail to answer */
	private array $brokenFiles = [];

	/** @var list<string> the accounts that Nextcloud has disabled */
	private array $disabled = [];

	/** @var array<int, ArrayObject<string, mixed>> what each notification holds, by its object */
	private array $pendingNotifications = [];

	protected function setUp(): void {
		$this->connected();
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
	}

	/**
	 * @return array<string, array{array<string, mixed>, ?string}>
	 */
	public static function pauses(): array {
		return [
			// settings that differ from an allowed feature of a connected app, the token
			'not allowed' => [['new_document_alerts' => false], 'TEST_VALUE'],
			'without a URL' => [['paperless_url' => ''], 'TEST_VALUE'],
			'without a token' => [[], null],
			'without an archive account' => [['archive_owner' => ''], 'TEST_VALUE'],
		];
	}

	/**
	 * While the feature can't work, the job asks nobody anything, and it forgets how far it
	 * came: whatever arrives during the pause isn't news when it starts again.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider('pauses')]
	public function testAPauseAsksNothingAndForgetsTheProgress(array $settings, ?string $token): void {
		foreach ($settings as $key => $value) {
			$this->settings[AppConstants::APP_ID . '.' . $key] = $value;
		}
		$this->storedToken = $token;
		$this->wants('jamie', true);
		$this->seen(412);

		$this->runJob($this->paperless());

		self::assertSame([], $this->requests);
		self::assertArrayNotHasKey(self::PROGRESS, $this->settings);
	}

	public function testWithoutAnyoneWhoAsksNothingIsAskedAndTheProgressIsForgotten(): void {
		$this->preferences['jamie.' . AppConstants::APP_ID . '.' . SavedSearches::PREFERENCE] = ['everyDocument' => false, 'terms' => []];
		$this->seen(412);

		$this->runJob($this->paperless());

		self::assertSame([], $this->requests);
		self::assertArrayNotHasKey(self::PROGRESS, $this->settings);
	}

	/**
	 * The first run only learns how far the IDs go, so that nobody hears about an archive of
	 * years: one short request for one document, and no notification.
	 */
	public function testTheFirstRunNotifiesNobodyAboutWhatTheArchiveHolds(): void {
		$this->wants('jamie', true);
		$this->file('/archive/files/Old [P412].pdf');

		$this->runJob($this->paperless($this->documents([['id' => 412, 'title' => 'Old']])));

		self::assertCount(1, $this->requests);
		self::assertSame([
			'ordering' => '-id',
			'page' => 1,
			'page_size' => 1,
			'fields' => 'id,title,owner',
		], $this->requests[0][1]['query']);
		self::assertSame([], $this->sent);
		self::assertSame(412, $this->stored()['lastSeen']);
		self::assertSame(self::NOW, $this->stored()['checkedAt']);
	}

	public function testAnEmptyArchiveStartsFromTheBeginning(): void {
		$this->wants('jamie', true);

		$this->runJob($this->paperless($this->documents([])));

		self::assertSame(0, $this->stored()['lastSeen']);

		// So the very first document is news.
		$this->file('/archive/files/First [P1].pdf');
		$this->file('/jamie/files/First [P1].pdf');
		$this->runJob($this->paperless($this->documents([['id' => 1, 'title' => 'First']])));
		self::assertCount(1, $this->sent);
	}

	public function testAnotherServerStartsAnew(): void {
		$this->wants('jamie', true);
		$this->seen(412);
		$this->settings[AppConstants::APP_ID . '.paperless_url'] = 'https://other.example.com';
		$this->file('/archive/files/Other [P500].pdf');

		$this->runJob($this->paperless($this->documents([['id' => 500, 'title' => 'Other']])));

		self::assertSame([], $this->sent);
		self::assertSame(500, $this->stored()['lastSeen']);
		self::assertSame(hash('sha256', 'https://other.example.com'), $this->stored()['server']);
	}

	/**
	 * With nothing new, Paperless gets one short request, limited to the last days, so that
	 * its answer doesn't list the IDs of the whole archive. No search goes out.
	 */
	public function testNothingNewCostsOneRequest(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->seen(412, checkedAt: self::NOW - 300);

		$this->runJob($this->paperless($this->documents([['id' => 412, 'title' => 'Seen'], ['id' => 389, 'title' => 'Older']])));

		self::assertCount(1, $this->requests);
		self::assertSame([
			'ordering' => '-id',
			'page' => 1,
			'page_size' => 100,
			'fields' => 'id,title,owner',
			'added__date__gte' => gmdate('Y-m-d', self::NOW - 300 - 86400),
		], $this->requests[0][1]['query']);
		self::assertSame([], $this->sent);
		self::assertSame(412, $this->stored()['lastSeen']);
		self::assertSame(self::NOW - 300, $this->stored()['checkedAt']);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * A pause without a progress deletes nothing: Nextcloud empties the cache of the
	 * configuration of all apps with every deletion, also of a key that isn't there, and the
	 * job runs every five minutes on every instance, also on one where nobody asks.
	 */
	public function testAPauseWithoutAProgressWritesNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('hasKey')
			->with(AppConstants::APP_ID, NewDocumentAlerts::PROGRESS, true)
			->willReturn(false);
		$config->expects(self::never())->method('deleteKey');
		$config->expects(self::never())->method('setValueArray');

		$this->runJob($this->paperless(), appConfig: $config);

		self::assertSame([], $this->requests);
	}

	/**
	 * A run that finds nothing new writes nothing, so that the configuration of the apps
	 * keeps its cache: the moment of the last answer alone moves only in steps of an hour,
	 * since the next request asks from the day before it anyway.
	 */
	public function testARunWithoutNewsWritesNothing(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturn([
			'server' => hash('sha256', 'https://paperless.example.com'),
			'lastSeen' => 413,
			'checkedAt' => self::NOW - 3599,
			'waiting' => [['id' => 413, 'title' => 'Waiting', 'since' => self::NOW - 600]],
			'failures' => 0,
			'restingUntil' => 0,
		]);
		$config->expects(self::never())->method('setValueArray');
		$config->expects(self::never())->method('deleteKey');

		$this->runJob($this->paperless($this->documents([['id' => 413, 'title' => 'Waiting'], ['id' => 412, 'title' => 'Seen']])), appConfig: $config);

		self::assertCount(1, $this->requests);
		self::assertSame([], $this->sent);
	}

	public function testAnHourLaterARunWithoutNewsStoresTheMomentOfTheAnswer(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->seen(412, checkedAt: self::NOW - 3600);

		$this->runJob($this->paperless($this->documents([['id' => 412, 'title' => 'Seen']])));

		self::assertSame(self::NOW, $this->stored()['checkedAt']);
		self::assertSame(412, $this->stored()['lastSeen']);
	}

	/**
	 * A new document has no file until Paperless Sync writes it. Until then it waits, and
	 * Paperless gets no search for it; the title comes from the run that first saw it.
	 */
	public function testADocumentWaitsForItsFileWithoutASearch(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->seen(412);

		$this->runJob($this->paperless($this->documents([['id' => 413, 'title' => " New\n letter "]])));

		self::assertCount(1, $this->requests);
		self::assertSame([], $this->sent);
		self::assertSame([['id' => 413, 'title' => 'New letter', 'since' => self::NOW]], $this->stored()['waiting']);
		self::assertSame(413, $this->stored()['lastSeen']);

		// The next run finds the file, and asks for the search.
		$this->requests = [];
		$this->now += 300;
		$this->file('/archive/files/Letter [P413].pdf');
		$this->file('/jamie/files/Letter [P413].pdf');
		$this->runJob($this->paperless($this->documents([['id' => 413, 'title' => 'New letter']]), $this->documents([['id' => 413]])));

		self::assertCount(2, $this->requests);
		self::assertSame([
			'query' => 'tag:unpaid',
			'id__in' => '413',
			'page' => 1,
			'page_size' => 1,
			'fields' => 'id',
		], $this->requests[1][1]['query']);
		self::assertCount(1, $this->sent);
		self::assertSame('New letter', $this->sent[0]['subject']['documents'][0]['title']);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * When Paperless Sync gives each owner an archive (decision 0013), a new document of
	 * jamie's waits for its file in jamie's own folders, not in those of the archive
	 * account, and its owner waits with it from one run to the next: jamie hears about it,
	 * and sam, with a file renamed after it, doesn't.
	 */
	public function testANewDocumentOfAnOwnerArrivesInTheFilesOfTheirUser(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->wants('jamie', true);
		$this->wants('sam', true);
		$this->seen(412);
		$users = fn (): IResponse => $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie']]]));

		$this->runJob($this->paperless($this->documents([['id' => 413, 'title' => 'Letter', 'owner' => 5]]), $users()));

		self::assertSame([], $this->sent);
		self::assertSame([['id' => 413, 'title' => 'Letter', 'since' => self::NOW, 'owner' => 5]], $this->stored()['waiting']);

		$this->now += 300;
		$this->file('/jamie/files/Paperless/Letter [P413].pdf', 'jamie');
		$this->file('/sam/files/Renamed [P413].pdf', 'sam');
		$this->runJob($this->paperless($this->documents([['id' => 413, 'title' => 'Letter', 'owner' => 5]]), $users()));

		self::assertSame(['jamie'], array_column($this->sent, 'user'));
		self::assertSame('/Paperless/Letter [P413].pdf', $this->sent[0]['subject']['documents'][0]['path']);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * A user whom Paperless Sync lets see only the documents with some tags (decision 0015)
	 * hears only about the new documents with one of them, as Paperless tags them when their
	 * files arrive, through their own copies; a share of the archive that shows them more
	 * changes nothing. Everyone else hears about every document they can open, as before.
	 */
	public function testAUserWithTagsHearsOnlyAboutTheDocumentsWithOneOfThem(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$this->wants('jamie', true);
		$this->wants('sam', true);
		$this->seen(100);
		$this->file('/archive/files/Payslip [P101].pdf');
		$this->file('/archive/files/Household [P102].pdf');
		$this->file('/jamie/files/Paperless/Payslip [P101].pdf', 'jamie');
		$this->file('/jamie/files/Archive/Household [P102].pdf');
		$this->file('/sam/files/Archive/Payslip [P101].pdf');
		$this->file('/sam/files/Archive/Household [P102].pdf');

		$this->runJob($this->paperless(
			$this->documents([['id' => 102, 'title' => 'Household'], ['id' => 101, 'title' => 'Payslip']]),
			$this->documents([['id' => 102, 'owner' => null, 'tags' => [1]], ['id' => 101, 'owner' => null, 'tags' => [1, 8]]]),
			$this->answer(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [['id' => 1, 'name' => 'Inbox'], ['id' => 8, 'name' => 'Katja']]])),
		));

		self::assertSame(['jamie', 'sam'], array_column($this->sent, 'user'));
		self::assertSame(1, $this->sent[0]['subject']['count']);
		self::assertSame('/Paperless/Payslip [P101].pdf', $this->sent[0]['subject']['documents'][0]['path']);
		self::assertSame(2, $this->sent[1]['subject']['count']);
		self::assertSame(['id__in' => '102,101', 'page' => 1, 'page_size' => 2, 'fields' => 'id,owner,tags'], $this->requests[1][1]['query']);
		self::assertSame('https://paperless.example.com/api/tags/', $this->requests[2][0]);
	}

	/**
	 * When Paperless Sync gives each owner an archive and an owner may see only the
	 * documents with some tags (decisions 0013 and 0015), it writes a document of theirs
	 * with one of their tags into their own folders only: the job reads the tags of the
	 * waiting documents before it looks for their files, finds the file there, and jamie
	 * hears about it.
	 */
	public function testATaggedDocumentOfAnOwnerWithTagsArrivesInTheFilesOfTheirUser(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$this->wants('jamie', true);
		$this->seen(412, [['id' => 413, 'title' => 'Letter', 'since' => self::NOW - 300, 'owner' => 5]]);
		$this->file('/jamie/files/Paperless/Letter [P413].pdf', 'jamie');

		$this->runJob($this->paperless(
			$this->documents([]),
			$this->documents([['id' => 413, 'owner' => 5, 'tags' => [8]]]),
			$this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie']]])),
			$this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 8, 'name' => 'Katja']]])),
		));

		self::assertSame(['jamie'], array_column($this->sent, 'user'));
		self::assertSame('/Paperless/Letter [P413].pdf', $this->sent[0]['subject']['documents'][0]['path']);
		self::assertSame(['id__in' => '413', 'page' => 1, 'page_size' => 1, 'fields' => 'id,owner,tags'], $this->requests[1][1]['query']);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * A document of the same owner without one of their tags goes to the archive account
	 * instead: its file arrives there, and jamie, who may see only the documents with
	 * their tags, hears nothing about it.
	 */
	public function testAnUntaggedDocumentOfAnOwnerWithTagsArrivesInTheArchiveWithoutNotifyingThem(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->settings['paperless_sync.user_tags'] = 'jamie = Katja';
		$this->wants('jamie', true);
		$this->seen(412, [['id' => 413, 'title' => 'Letter', 'since' => self::NOW - 300, 'owner' => 5]]);
		$this->file('/archive/files/Letter [P413].pdf');
		$this->file('/jamie/files/Archive/Letter [P413].pdf');

		$this->runJob($this->paperless(
			$this->documents([]),
			$this->documents([['id' => 413, 'owner' => 5, 'tags' => [1]]]),
			$this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie']]])),
			$this->answer(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [['id' => 1, 'name' => 'Inbox'], ['id' => 8, 'name' => 'Katja']]])),
		));

		self::assertSame([], $this->sent);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * One notification for all documents of a run, the newest first, which names the first
	 * three and links their files, as the user sees them.
	 */
	public function testOneNotificationNamesTheNewestDocuments(): void {
		$this->wants('jamie', true);
		$this->seen(100);
		foreach ([101, 102, 103, 104] as $id) {
			$this->file("/archive/files/Doc [P{$id}].pdf");
			$this->file("/jamie/files/Shared/Doc [P{$id}].pdf");
		}

		$this->runJob($this->paperless($this->documents([
			['id' => 104, 'title' => 'Fourth'],
			['id' => 103, 'title' => 'Third'],
			['id' => 102, 'title' => ''],
			['id' => 101, 'title' => 'First'],
		])));

		self::assertCount(1, $this->requests, 'Every document asks for no search.');
		self::assertCount(1, $this->sent);
		$sent = $this->sent[0];
		self::assertSame(AppConstants::APP_ID, $sent['app']);
		self::assertSame('jamie', $sent['user']);
		self::assertSame(self::NOW, $sent['dateTime']->getTimestamp());
		self::assertSame([Notifier::OBJECT_TYPE, '104'], $sent['object']);
		self::assertSame(Notifier::SUBJECT, $sent['subjectName']);
		self::assertSame([
			'count' => 4,
			'documents' => [
				['title' => 'Fourth', 'fileId' => crc32('/jamie/files/Shared/Doc [P104].pdf'), 'path' => '/Shared/Doc [P104].pdf'],
				['title' => 'Third', 'fileId' => crc32('/jamie/files/Shared/Doc [P103].pdf'), 'path' => '/Shared/Doc [P103].pdf'],
				// Without a title, the name of the file.
				['title' => 'Doc [P102].pdf', 'fileId' => crc32('/jamie/files/Shared/Doc [P102].pdf'), 'path' => '/Shared/Doc [P102].pdf'],
			],
		], $sent['subject']);
		self::assertSame(104, $this->stored()['lastSeen']);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * A user who can't open the file of a document hears nothing about it, whatever they
	 * asked for, and a user who can hears about no other.
	 */
	public function testAUserHearsOnlyAboutFilesThatTheyCanOpen(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->wants('sam', false, 'tag:unpaid');
		$this->wants('alex', true);
		$this->seen(100);
		$this->file('/archive/files/Shared [P101].pdf');
		$this->file('/archive/files/Private [P102].pdf');
		$this->file('/jamie/files/Shared [P101].pdf');
		// Alex may change this share, and could give any file the marker.
		$this->file('/alex/files/Shared [P101].pdf', writable: true);

		$this->runJob($this->paperless(
			$this->documents([['id' => 102, 'title' => 'Private'], ['id' => 101, 'title' => 'Shared']]),
			$this->documents([['id' => 102], ['id' => 101]]),
		));

		self::assertSame(['jamie'], array_column($this->sent, 'user'));
		self::assertSame(1, $this->sent[0]['subject']['count']);
		self::assertSame('Shared', $this->sent[0]['subject']['documents'][0]['title']);
	}

	/**
	 * Every distinct search costs one request, however many users saved it; a user who asks
	 * for every document costs none, and hears about a document once, whichever of their
	 * searches find it.
	 */
	public function testEachDistinctSearchCostsOneRequest(): void {
		$this->wants('jamie', false, 'tag:unpaid', 'tax');
		$this->wants('dennis', false, 'tax', 'electricity');
		$this->wants('alex', true, 'ignored while every document counts');
		$this->seen(100);
		foreach (['archive', 'jamie', 'dennis', 'alex'] as $user) {
			$this->file("/{$user}/files/A [P101].pdf");
			$this->file("/{$user}/files/B [P102].pdf");
		}

		$this->runJob($this->paperless(
			$this->documents([['id' => 102, 'title' => 'B'], ['id' => 101, 'title' => 'A']]),
			$this->documents([['id' => 101]]),
			$this->documents([['id' => 102]]),
			$this->documents([]),
		));

		self::assertSame([null, 'tag:unpaid', 'tax', 'electricity'], array_map(static fn (array $request): ?string => $request[1]['query']['query'] ?? null, $this->requests));
		self::assertSame('102,101', $this->requests[1][1]['query']['id__in']);
		self::assertSame(2, $this->requests[1][1]['query']['page_size']);
		self::assertSame(['jamie', 'dennis', 'alex'], array_column($this->sent, 'user'));
		// Each of the searches of jamie finds another document, in the order of the documents.
		self::assertSame(['B', 'A'], array_column($this->sent[0]['subject']['documents'], 'title'));
		self::assertSame(['B'], array_column($this->sent[1]['subject']['documents'], 'title'));
		self::assertSame(['B', 'A'], array_column($this->sent[2]['subject']['documents'], 'title'));
	}

	/**
	 * A user whose searches find nothing among documents that others hear about hears
	 * nothing, whatever files the user can see.
	 */
	public function testAUserWhoseSearchesFindNothingHearsNothing(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->wants('alex', true);
		$this->seen(100);
		foreach (['archive', 'jamie', 'alex'] as $user) {
			$this->file("/{$user}/files/A [P101].pdf");
		}

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']]), $this->documents([])));

		self::assertSame(['alex'], array_column($this->sent, 'user'));
	}

	/**
	 * The highest IDs come first, whatever order the answer has, so that those beyond the
	 * limit are the oldest.
	 */
	public function testTheHighestIdsComeFirst(): void {
		$this->wants('jamie', true);
		$this->seen(100);

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A'], ['id' => 103, 'title' => 'C'], ['id' => 102, 'title' => 'B']])));

		self::assertSame([103, 102, 101], array_column($this->stored()['waiting'], 'id'));
		self::assertSame(103, $this->stored()['lastSeen']);
	}

	/**
	 * Only the job reads the progress, so Nextcloud doesn't load it with every request.
	 */
	public function testTheProgressIsALazyValue(): void {
		$this->wants('jamie', true);
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueArray')
			->with(AppConstants::APP_ID, NewDocumentAlerts::PROGRESS, [], true)
			->willReturn([]);
		$config->expects(self::once())
			->method('setValueArray')
			->with(AppConstants::APP_ID, NewDocumentAlerts::PROGRESS, self::isArray(), true)
			->willReturn(true);

		$this->runJob($this->paperless($this->documents([])), appConfig: $config);
	}

	public function testASearchThatFindsNothingNotifiesNobody(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']]), $this->documents([])));

		self::assertSame([], $this->sent);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * A search that Paperless refuses finds nothing and costs no rest: it isn't a failure of
	 * Paperless. The log says so, with the users whose searches in use it refused, so that
	 * an administrator can tell them, and without the words of the users: sam asks for
	 * every document, which leaves the same search unused.
	 */
	public function testARefusedSearchFindsNothingAndTheOthersGoOn(): void {
		$this->wants('jamie', false, 'created:[2026 to', 'tag:unpaid');
		$this->wants('alex', false, 'created:[2026 to');
		$this->wants('sam', true, 'created:[2026 to');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			$this->answer(400, '{"query": ["Invalid"]}', []),
			$this->documents([['id' => 101]]),
		));

		self::assertSame(['jamie'], array_column($this->sent, 'user'));
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame(0, $this->stored()['restingUntil']);
		self::assertSame([[
			'info',
			'Paperless refused {count} saved search(es) of the notifications about new documents, of the users {users}: they find nothing until the users correct them in their personal settings',
			['app' => AppConstants::APP_ID, 'count' => 1, 'users' => 'jamie, alex'],
		]], $this->logged);
	}

	/**
	 * One user's search that Paperless fails to answer, such as a wildcard that takes too
	 * long, stops nobody else: it finds nothing, the log counts it and names its user without
	 * its words, and the job doesn't rest, since Paperless answered the run.
	 */
	public function testAFailedSearchStopsNobodyElse(): void {
		$this->wants('jamie', false, 'title:*secret*');
		$this->wants('sam', false, 'tag:unpaid');
		$this->seen(100, failures: 2);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');
		$this->file('/sam/files/A [P101].pdf');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			new RuntimeException('Timeout'),
			$this->documents([['id' => 101]]),
		));

		self::assertCount(1, $this->sent);
		self::assertSame('sam', $this->sent[0]['user']);
		self::assertSame([], $this->stored()['waiting']);
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame(0, $this->stored()['restingUntil']);
		self::assertSame([[
			'warning',
			'{count} saved search(es) of the notifications about new Paperless documents failed ({errorType}), of the users {users}: they find nothing in this run',
			['app' => AppConstants::APP_ID, 'count' => 1, 'errorType' => RuntimeException::class, 'users' => 'jamie'],
		]], $this->logged);
	}

	/**
	 * A search that Paperless refuses is an answer: with one that fails beside it, Paperless
	 * still works, so the job goes on instead of resting.
	 */
	public function testARefusedSearchShowsThatPaperlessAnswersBesideAFailedOne(): void {
		$this->wants('jamie', true);
		$this->wants('sam', false, 'created:[2026 to', 'title:*secret*');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			$this->answer(400, '{"query": ["Invalid"]}', []),
			new RuntimeException('Timeout'),
		));

		self::assertCount(1, $this->sent);
		self::assertSame('jamie', $this->sent[0]['user']);
		self::assertSame(0, $this->stored()['failures']);
	}

	/**
	 * When every saved search fails, Paperless has still answered this run, with the new
	 * documents, which hold no words of a user: so it is the searches that fail, not
	 * Paperless. They find nothing, the log names their users without their words, and
	 * whoever asked for every document gets their notification, without a rest of the job
	 * (#414). One search that Paperless never answered in time, on an instance with no
	 * other, stopped the notifications of every user before, until the documents gave up
	 * waiting after two days.
	 */
	public function testEverySearchFailingStopsNobodyElse(): void {
		$this->wants('jamie', true);
		$this->wants('sam', false, 'tag:unpaid', 'tag:bills');
		$this->seen(100, failures: 2);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');
		$this->file('/sam/files/A [P101].pdf');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			new RuntimeException('Timeout'),
			new RuntimeException('Timeout'),
		));

		self::assertSame(['jamie'], array_column($this->sent, 'user'));
		self::assertSame([], $this->stored()['waiting']);
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame(0, $this->stored()['restingUntil']);
		self::assertSame([[
			'warning',
			'{count} saved search(es) of the notifications about new Paperless documents failed ({errorType}), of the users {users}: they find nothing in this run',
			['app' => AppConstants::APP_ID, 'count' => 2, 'errorType' => RuntimeException::class, 'users' => 'sam'],
		]], $this->logged);
	}

	/**
	 * The only saved search of an instance, which Paperless answers with an error, finds
	 * nothing, and the next run asks for the documents that are new by then instead of
	 * resting: the request for the new documents has answered, so Paperless is up.
	 */
	public function testTheOnlySearchFailingFindsNothingAndTheJobGoesOn(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->seen(100, failures: 1);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']]), $this->answer(500, 'Server Error', [])));

		self::assertSame([], $this->sent);
		self::assertSame(101, $this->stored()['lastSeen']);
		self::assertSame(self::NOW, $this->stored()['checkedAt']);
		self::assertSame([], $this->stored()['waiting']);
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame(0, $this->stored()['restingUntil']);
		self::assertCount(1, $this->logged);
		self::assertSame(['warning', 1, 'jamie'], [$this->logged[0][0], $this->logged[0][2]['count'], $this->logged[0][2]['users']]);
	}

	/**
	 * Each document of a notification keeps the first of the user's saved searches that
	 * found it, in the order in which the user saved them, so that the notification can say
	 * why it came (#413); a document of a user who asked for every document keeps none.
	 */
	public function testEachDocumentKeepsTheFirstSearchOfItsUserThatFoundIt(): void {
		$this->wants('jamie', false, 'tag:unpaid', 'tax');
		$this->wants('sam', false, 'tax');
		$this->wants('alex', true);
		$this->seen(100);
		foreach (['archive', 'jamie', 'sam', 'alex'] as $user) {
			$this->file("/{$user}/files/A [P101].pdf");
			$this->file("/{$user}/files/B [P102].pdf");
		}

		$this->runJob($this->paperless(
			$this->documents([['id' => 102, 'title' => 'B'], ['id' => 101, 'title' => 'A']]),
			$this->documents([['id' => 101]]),
			$this->documents([['id' => 102], ['id' => 101]]),
		));

		self::assertSame(['jamie', 'sam', 'alex'], array_column($this->sent, 'user'));
		self::assertSame([['B', 'tax'], ['A', 'tag:unpaid']], array_map(static fn (array $document): array => [$document['title'], $document['search']], $this->sent[0]['subject']['documents']));
		self::assertSame(['tax', 'tax'], array_column($this->sent[1]['subject']['documents'], 'search'));
		foreach ($this->sent[2]['subject']['documents'] as $document) {
			self::assertArrayNotHasKey('search', $document);
		}
	}

	/**
	 * A Paperless that stops answering in the middle of a run, as it may while it consumes
	 * the new documents, gets no further search after a few in a row without an answer: the
	 * run rests as for any other failure, the documents keep waiting for the next run, and
	 * the log holds neither the message of the request nor the words of a user (#418).
	 * Before, every distinct search of every user waited for its timeout of ten seconds.
	 */
	public function testSearchesWithoutAnAnswerInARowRestTheRun(): void {
		$this->wants('jamie', true);
		$this->wants('sam', false, 'tag:unpaid', 'tag:bills', 'tag:tax', 'tag:car', 'tag:never asked');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');
		$timeout = new RuntimeException('cURL error 28: Operation timed out after 10001 milliseconds for https://paperless.example.com/api/documents/?query=tag%3Asecret');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			$this->documents([]),
			$timeout,
			$timeout,
			$timeout,
		));

		self::assertCount(1 + 1 + NewDocumentAlerts::MOST_UNANSWERED, $this->requests, 'The fifth search is never asked.');
		self::assertSame([], $this->sent);
		self::assertSame(101, $this->stored()['lastSeen']);
		self::assertSame([101], array_column($this->stored()['waiting'], 'id'));
		self::assertSame(1, $this->stored()['failures']);
		self::assertSame(self::NOW + 600, $this->stored()['restingUntil']);
		self::assertSame([['warning', 'The notifications about new Paperless documents try again in {seconds} seconds ({error})', [
			'app' => AppConstants::APP_ID,
			'seconds' => 600,
			'error' => '3 saved searches in a row got no answer (' . RuntimeException::class . ').',
		]]], $this->logged);
	}

	/**
	 * An answer between searches without one shows that Paperless still works, and the count
	 * starts again: a list of documents, a refusal and an answer that isn't a list of
	 * documents alike.
	 */
	public function testAnAnswerBetweenSearchesWithoutOneLetsTheRunGoOn(): void {
		$this->wants('jamie', false, 'a', 'b', 'c', 'd', 'e', 'f');
		$this->wants('sam', false, 'g', 'h', 'i', 'j', 'k', 'l');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/sam/files/A [P101].pdf');
		$timeout = new RuntimeException('Timeout');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			$timeout,
			$timeout,
			$this->documents([]),
			$timeout,
			$timeout,
			$this->answer(400, '{"query": ["Invalid"]}', []),
			$timeout,
			$timeout,
			$this->answer(500, 'Server Error', []),
			$timeout,
			$timeout,
			$this->documents([['id' => 101]]),
		));

		self::assertSame(['sam'], array_column($this->sent, 'user'));
		self::assertSame(['l'], array_column($this->sent[0]['subject']['documents'], 'search'));
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame(9, $this->logged[0][2]['count']);
	}

	/**
	 * Once a search got no answer, the searches of a run go on for SEARCH_SECONDS at most,
	 * also when answers between the ones without keep the count of MOST_UNANSWERED from
	 * filling: then the run rests, the documents wait, and the next run asks again.
	 */
	public function testTheSearchesOfARunEndAMinuteAfterOneGotNoAnswer(): void {
		$this->wants('jamie', false, 'a', 'b', 'c', 'd', 'e');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');
		$timeout = new RuntimeException('Timeout');

		$this->runJob($this->slow(30, $this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			$timeout,
			$this->documents([]),
			$timeout,
		)));

		self::assertCount(4, $this->requests, 'The fourth search would start 60 seconds after the first without an answer and is never asked.');
		self::assertSame([], $this->sent);
		self::assertSame([101], array_column($this->stored()['waiting'], 'id'));
		self::assertSame(1, $this->stored()['failures']);
		self::assertSame('The saved searches went on for 60 seconds or more after one got no answer.', $this->logged[0][2]['error']);
	}

	/**
	 * A Paperless that answers every search, if slowly, gets them all asked, however long
	 * they take together: a run cut off by time would keep nothing of them, so the next one
	 * would start from the first search again and never get further, and no notification
	 * would go out on an instance with many saved searches.
	 */
	public function testAPaperlessThatAnswersSlowlyGetsEverySearchAsked(): void {
		$this->wants('jamie', false, 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->slow(9, $this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			$this->documents([]),
			$this->documents([]),
			$this->documents([]),
			$this->documents([]),
			$this->documents([]),
			$this->documents([]),
			$this->documents([]),
			$this->documents([['id' => 101]]),
		)));

		self::assertCount(9, $this->requests, 'Eight searches of nine seconds each, 72 in all, are all asked.');
		self::assertSame(['jamie'], array_column($this->sent, 'user'));
		self::assertSame(['h'], array_column($this->sent[0]['subject']['documents'], 'search'));
		self::assertSame(0, $this->stored()['failures']);
	}

	/**
	 * A client of Paperless that takes these seconds for each request after the first.
	 */
	private function slow(int $seconds, IClient $client): IClient {
		$slow = $this->createStub(IClient::class);
		$first = true;
		$slow->method('get')->willReturnCallback(function (string $url, array $options) use ($client, $seconds, &$first): IResponse {
			if (!$first) {
				$this->now += $seconds;
			}
			$first = false;

			return $client->get($url, $options);
		});

		return $slow;
	}

	/**
	 * PHP keeps a user ID like 12345, as from LDAP or a list of employees, as a number in the
	 * keys of an array; that user gets their notification, and so does everyone after them.
	 */
	public function testAUserIdThatLooksLikeANumberStopsNobody(): void {
		$this->wants('12345', true);
		$this->wants('jamie', true);
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/12345/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']])), accounts: $this->enabledAccounts('archive', '12345', 'jamie'));

		self::assertSame(['12345', 'jamie'], array_column($this->sent, 'user'));
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function rests(): array {
		return [
			// failures so far, seconds of rest
			'the first' => [0, 600],
			'the second' => [1, 1200],
			'the third' => [2, 2400],
			'the fourth' => [3, 3600],
			'any later one' => [4, 3600],
		];
	}

	/**
	 * While Paperless is down, the job asks less and less often, and what it has seen so far
	 * stays. The log says why, and the history of the problems of the search stays out of it.
	 */
	#[DataProvider('rests')]
	public function testAFailureRestsLongerEachTime(int $failures, int $seconds): void {
		$this->wants('jamie', true);
		$this->seen(100, waiting: [['id' => 99, 'title' => 'Waiting', 'since' => self::NOW - 600]], failures: $failures);

		$this->runJob($this->paperless(new RuntimeException('cURL error for https://paperless/?query=secret')));

		self::assertSame($failures + 1, $this->stored()['failures']);
		self::assertSame(self::NOW + $seconds, $this->stored()['restingUntil']);
		self::assertSame(100, $this->stored()['lastSeen']);
		self::assertSame([['id' => 99, 'title' => 'Waiting', 'since' => self::NOW - 600]], $this->stored()['waiting']);
		self::assertSame([['warning', 'The notifications about new Paperless documents try again in {seconds} seconds ({error})', [
			'app' => AppConstants::APP_ID,
			'seconds' => $seconds,
			// Only the kind of a failed request, whose message may hold the words of a user.
			'error' => RuntimeException::class,
		]]], $this->logged);
		self::assertArrayNotHasKey(AppConstants::APP_ID . '.diagnostics', $this->settings);
	}

	public function testAnAnswerOfPaperlessNamesItsStatusInTheLog(): void {
		$this->wants('jamie', true);
		$this->seen(100);

		$this->runJob($this->paperless($this->answer(500, 'Server Error', [])));

		self::assertStringContainsString('500', (string)$this->logged[0][2]['error']);
	}

	public function testWhileItRestsNothingIsAsked(): void {
		$this->wants('jamie', true);
		$this->seen(100, failures: 1, restingUntil: self::NOW + 1);

		$this->runJob($this->paperless());

		self::assertSame([], $this->requests);
		self::assertSame(self::NOW + 1, $this->stored()['restingUntil']);
	}

	public function testTheFirstMomentAfterTheRestAsksAgainAndAnAnswerEndsTheFailures(): void {
		$this->wants('jamie', true);
		$this->seen(100, failures: 3, restingUntil: self::NOW);

		$this->runJob($this->paperless($this->documents([])));

		self::assertCount(1, $this->requests);
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame(0, $this->stored()['restingUntil']);
	}

	/**
	 * A first run that fails starts anew after its rest: no document of the archive counts
	 * as new for it.
	 */
	public function testAFailedFirstRunStartsAnew(): void {
		$this->wants('jamie', true);
		$this->file('/archive/files/Old [P412].pdf');
		$this->file('/jamie/files/Old [P412].pdf');

		$this->runJob($this->paperless(new RuntimeException('down')));

		self::assertNull($this->stored()['lastSeen']);
		self::assertSame(1, $this->stored()['failures']);

		$this->now += 600;
		$this->runJob($this->paperless($this->documents([['id' => 412, 'title' => 'Old']])));

		self::assertSame([], $this->sent);
		self::assertSame(412, $this->stored()['lastSeen']);
		self::assertSame(0, $this->stored()['failures']);
	}

	/**
	 * A document that gets no file within two days never counts, such as one that the rules
	 * of Paperless Sync leave out; one second earlier it still waits.
	 */
	public function testADocumentWithoutAFileGivesUpAfterTwoDays(): void {
		$this->wants('jamie', true);
		$this->seen(100, waiting: [
			['id' => 100, 'title' => 'Still waits', 'since' => self::NOW - 2 * 86400 + 1],
			['id' => 99, 'title' => 'Gives up', 'since' => self::NOW - 2 * 86400],
		]);

		$this->runJob($this->paperless($this->documents([])));

		self::assertSame([['id' => 100, 'title' => 'Still waits', 'since' => self::NOW - 2 * 86400 + 1]], $this->stored()['waiting']);
	}

	/**
	 * Of an import larger than one request, only the newest documents count, the highest
	 * first, and the log says so.
	 */
	public function testOfALargeImportTheNewestWaitAndTheLogSaysSo(): void {
		$this->wants('jamie', true);
		$this->seen(1000, waiting: array_map(
			static fn (int $id): array => ['id' => $id, 'title' => 'Old', 'since' => self::NOW - 60],
			range(999, 800),
		));
		$new = array_map(static fn (int $id): array => ['id' => $id, 'title' => 'New'], range(1100, 1001));

		$this->runJob($this->paperless($this->documents($new)));

		$waiting = array_column($this->stored()['waiting'], 'id');
		self::assertCount(NewDocumentAlerts::MOST_WAITING, $waiting);
		self::assertSame(1100, $waiting[0]);
		self::assertSame(1001, $waiting[99]);
		self::assertSame(999, $waiting[100]);
		self::assertSame(900, $waiting[199]);
		self::assertSame(1100, $this->stored()['lastSeen']);
		self::assertSame('info', $this->logged[0][0]);
		self::assertSame(['app' => AppConstants::APP_ID, 'count' => 100], $this->logged[0][2]);
	}

	public function testOneDocumentLessThanARequestHoldsSaysNothingInTheLog(): void {
		$this->wants('jamie', true);
		$this->seen(1000);

		$this->runJob($this->paperless($this->documents(array_map(static fn (int $id): array => ['id' => $id, 'title' => 'New'], range(1099, 1001)))));

		self::assertSame([], $this->logged);
	}

	/**
	 * What isn't a document, or has no ID that counts, is left out, and a document without a
	 * title in the answer gets the name of its file.
	 */
	public function testAnAnswerThatHoldsSomethingElseLeavesItOut(): void {
		$this->wants('jamie', true);
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless($this->documents([['id' => '200', 'title' => 'Text'], ['title' => 'None'], ['id' => 101, 'title' => 7]])));

		self::assertSame(101, $this->stored()['lastSeen']);
		self::assertSame(1, $this->sent[0]['subject']['count']);
		self::assertSame('A [P101].pdf', $this->sent[0]['subject']['documents'][0]['title']);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function answersWithoutAnId(): array {
		return [
			'no document' => [[]],
			'an ID as text' => [[['id' => '412']]],
			'no ID' => [[['title' => 'None']]],
		];
	}

	/**
	 * @param list<array<string, mixed>> $documents
	 */
	#[DataProvider('answersWithoutAnId')]
	public function testAFirstAnswerWithoutAnIdStartsFromTheBeginning(array $documents): void {
		$this->wants('jamie', true);

		$this->runJob($this->paperless($this->documents($documents)));

		self::assertSame(0, $this->stored()['lastSeen']);
	}

	public function testAnAccountThatIsGoneOrDisabledHearsNothing(): void {
		$this->wants('jamie', true);
		$this->wants('sam', true);
		$this->wants('gone', true);
		$this->disabled = ['sam'];
		$this->seen(100);
		foreach (['archive', 'jamie', 'sam', 'gone'] as $user) {
			$this->file("/{$user}/files/A [P101].pdf");
		}

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']])));

		self::assertSame(['jamie'], array_column($this->sent, 'user'));
	}

	/**
	 * A user whose files fail to answer costs only their own notification, and the log names
	 * only the kind of the failure.
	 */
	public function testAUserWhoseFilesFailCostsOnlyTheirOwnNotification(): void {
		$this->wants('sam', true);
		$this->wants('jamie', true);
		$this->brokenFiles = ['sam'];
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']])));

		self::assertSame(['jamie'], array_column($this->sent, 'user'));
		self::assertSame([['warning', 'A user got no notification about new Paperless documents ({errorType})', [
			'app' => AppConstants::APP_ID,
			'errorType' => RuntimeException::class,
		]]], $this->logged);
	}

	/**
	 * The progress is stored before the first notification goes out: a run that dies halfway
	 * costs a notification, but repeats none for those who got theirs.
	 */
	public function testTheProgressIsStoredBeforeTheFirstNotification(): void {
		$this->wants('jamie', true);
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');
		$stored = null;
		$notifications = $this->notifications(function () use (&$stored): void {
			$stored = $this->stored();
		});

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']])), $notifications);

		self::assertSame([], $stored['waiting'] ?? null);
		self::assertSame(101, $stored['lastSeen'] ?? null);
	}

	/**
	 * When Paperless Sync gives each owner an archive, the files of the archive account and
	 * those of the users of the owners arrive in the same run: each user hears about every
	 * new document that they can open, and Paperless gets the IDs of all of them, the
	 * highest first.
	 */
	public function testTheFilesOfTheArchiveAndOfTheOwnersArriveTogether(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->wants('jamie', true);
		$this->wants('sam', false, 'tag:taxes');
		$this->seen(410, [
			['id' => 413, 'title' => 'Of sam', 'since' => self::NOW - 300, 'owner' => 6],
			['id' => 412, 'title' => 'Of jamie', 'since' => self::NOW - 300, 'owner' => 5],
			['id' => 411, 'title' => 'Of nobody', 'since' => self::NOW - 300],
		]);
		$this->file('/archive/files/Paperless/Of nobody [P411].pdf');
		$this->file('/jamie/files/Paperless/Of nobody [P411].pdf');
		$this->file('/jamie/files/Paperless/Of jamie [P412].pdf', 'jamie');
		$this->file('/sam/files/Paperless/Of sam [P413].pdf', 'sam');
		$users = $this->answer(200, (string)json_encode(['count' => 2, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie'], ['id' => 6, 'username' => 'sam']]]));

		$this->runJob($this->paperless($this->documents([]), $users, $this->documents([['id' => 413]])));

		self::assertSame('413,412,411', $this->requests[2][1]['query']['id__in']);
		$titles = [];
		foreach ($this->sent as $notification) {
			$titles[$notification['user']] = array_column($notification['subject']['documents'], 'title');
			sort($titles[$notification['user']]);
		}
		ksort($titles);
		self::assertSame(['jamie' => ['Of jamie', 'Of nobody'], 'sam' => ['Of sam']], $titles);
		self::assertSame([], $this->stored()['waiting']);
	}

	/**
	 * The user of an owner whose account is gone since the owners were matched has no
	 * files, and the files of the archive still reach whoever can open them.
	 */
	public function testAnOwnerWhoseAccountIsGoneMeanwhileHasNoFiles(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->wants('sam', true);
		$this->seen(410, [
			['id' => 412, 'title' => 'Of jamie', 'since' => self::NOW - 300, 'owner' => 5],
			['id' => 411, 'title' => 'Of nobody', 'since' => self::NOW - 300],
		]);
		$this->file('/archive/files/Paperless/Of nobody [P411].pdf');
		$this->file('/sam/files/Paperless/Of nobody [P411].pdf');
		$this->file('/jamie/files/Paperless/Of jamie [P412].pdf', 'jamie');
		// The owners find jamie, the job no longer does.
		$enabled = $this->enabledAccounts('archive', 'jamie', 'sam');
		$lookups = 0;
		$accounts = $this->createStub(IUserManager::class);
		$accounts->method('get')->willReturnCallback(static function (string $id) use (&$lookups, $enabled): ?IUser {
			if ($id !== 'jamie') {
				return $enabled->get($id);
			}
			$lookups++;

			return $lookups === 1 ? $enabled->get($id) : null;
		});
		$users = $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie']]]));

		$this->runJob($this->paperless($this->documents([]), $users), accounts: $accounts);

		self::assertSame(['sam'], array_column($this->sent, 'user'));
		self::assertSame([['id' => 412, 'title' => 'Of jamie', 'since' => self::NOW - 300, 'owner' => 5]], $this->stored()['waiting']);
		self::assertSame(0, $this->stored()['failures']);
	}

	/**
	 * The run searches the files of the user of an owner once, and only for the documents
	 * that have no file of the archive account: not at all when they all have one.
	 *
	 * @return array<string, array{list<array{string, string}>, int}>
	 */
	public static function filesOfAnOwner(): array {
		return [
			// The archive account's files and jamie's for the notification.
			'every document with a file of the archive account' => [[['/archive/files/Paperless/A [P413].pdf', 'archive'], ['/archive/files/Paperless/B [P412].pdf', 'archive']], 2],
			// The archive account's files, jamie's own once, and jamie's for the notification.
			'one in the files of the owner, one without a file' => [[['/jamie/files/Paperless/B [P412].pdf', 'jamie']], 3],
		];
	}

	/**
	 * @param list<array{string, string}> $files the path and the owner of each file
	 */
	#[DataProvider('filesOfAnOwner')]
	public function testTheFilesOfAnOwnerAreSearchedOnceForWhatTheArchiveLacks(array $files, int $searches): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->wants('jamie', true);
		$this->seen(411, [
			['id' => 413, 'title' => 'A', 'since' => self::NOW - 300, 'owner' => 5],
			['id' => 412, 'title' => 'B', 'since' => self::NOW - 300, 'owner' => 5],
		]);
		foreach ($files as [$path, $owner]) {
			$this->file($path, $owner);
		}
		$users = $this->answer(200, (string)json_encode(['count' => 1, 'next' => null, 'results' => [['id' => 5, 'username' => 'jamie']]]));

		$this->runJob($this->paperless($this->documents([]), $users));

		self::assertCount($searches, $this->searches);
	}

	public function testAnArchiveAccountThatIsGoneMeanwhileHasNoFiles(): void {
		$this->wants('jamie', true);
		$this->seen(100);
		// The settings find the account, the job no longer does.
		$accounts = $this->createStub(IUserManager::class);
		$lookups = 0;
		$archive = $this->account('archive');
		$accounts->method('get')->willReturnCallback(static function () use (&$lookups, $archive): ?IUser {
			$lookups++;

			return $lookups === 1 ? $archive : null;
		});

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']])), accounts: $accounts);

		self::assertSame([], $this->sent);
		self::assertSame([['id' => 101, 'title' => 'A', 'since' => self::NOW]], $this->stored()['waiting']);
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame([], $this->logged);
	}

	/**
	 * A user who is new to the job waits no longer than the next documents, and a document of
	 * another run's waiting list reaches them too: they asked before it had a file.
	 */
	public function testADocumentThatWaitedReachesWhoeverAsksByThen(): void {
		$this->wants('jamie', true);
		$this->seen(100, waiting: [['id' => 100, 'title' => 'Waited', 'since' => self::NOW - 600]]);
		$this->file('/archive/files/W [P100].pdf');
		$this->file('/jamie/files/W [P100].pdf');

		$this->runJob($this->paperless($this->documents([])));

		self::assertSame('Waited', $this->sent[0]['subject']['documents'][0]['title']);
	}

	/**
	 * The saved searches of a user who wants them.
	 */
	private function wants(string $userId, bool $everyDocument, string ...$terms): void {
		$this->preferences[$userId . '.' . AppConstants::APP_ID . '.' . SavedSearches::PREFERENCE] = ['everyDocument' => $everyDocument, 'terms' => array_values($terms)];
	}

	/**
	 * The progress of an earlier run of this server.
	 *
	 * @param list<array{id: int, title: string, since: int}> $waiting
	 */
	private function seen(int $lastSeen, array $waiting = [], int $failures = 0, int $restingUntil = 0, int $checkedAt = self::NOW - 300): void {
		$this->settings[self::PROGRESS] = [
			'server' => hash('sha256', 'https://paperless.example.com'),
			'lastSeen' => $lastSeen,
			'checkedAt' => $checkedAt,
			'waiting' => $waiting,
			'failures' => $failures,
			'restingUntil' => $restingUntil,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = $this->settings[self::PROGRESS] ?? null;
		self::assertIsArray($stored, 'The job stored no progress.');

		return $stored;
	}

	private function runJob(IClient $client, ?IManager $notifications = null, ?IUserManager $accounts = null, ?IAppConfig $appConfig = null): void {
		$accounts ??= $this->enabledAccounts('archive', 'jamie', 'sam', 'dennis', 'alex');
		$configService = $this->configService($accounts);
		$logger = $this->createStub(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$clock->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime('@' . $this->now));

		$paperlessApi = $this->paperlessApi($configService, $client);
		(new NewDocumentAlerts(
			$configService,
			new SavedSearches($this->userConfig()),
			$paperlessApi,
			new NextcloudFileLocator($this->brokenAwareRoot(), $this->createStub(LoggerInterface::class)),
			$accounts,
			$notifications ?? $this->notifications(),
			$appConfig ?? $this->appConfig(),
			$clock,
			$logger,
			$this->documentOwners($paperlessApi, $configService, $accounts),
			$this->userTags($paperlessApi, $configService),
		))->run();
	}

	/**
	 * The root of the files, whose folder of a user in $this->brokenFiles fails to answer.
	 */
	private function brokenAwareRoot(): IRootFolder {
		$root = $this->rootFolder();
		$broken = $this->createStub(IRootFolder::class);
		$broken->method('getUserFolder')->willReturnCallback(function (string $uid) use ($root): Folder {
			if (in_array($uid, $this->brokenFiles, true)) {
				throw new RuntimeException('The storage of ' . $uid . ' fails.');
			}

			return $root->getUserFolder($uid);
		});

		return $broken;
	}

	private function enabledAccounts(string ...$ids): IUserManager {
		$accounts = [];
		foreach ($ids as $id) {
			$accounts[$id] = $this->account($id);
		}

		$manager = $this->createStub(IUserManager::class);
		$manager->method('get')->willReturnCallback(static fn (string $id): ?IUser => $accounts[$id] ?? null);

		return $manager;
	}

	private function account(string $id): IUser {
		$account = $this->createStub(IUser::class);
		$account->method('getUID')->willReturn($id);
		$account->method('isEnabled')->willReturnCallback(fn (): bool => !in_array($id, $this->disabled, true));

		return $account;
	}

	/**
	 * Nextcloud's manager of the notifications, which keeps those that go out in $this->sent.
	 *
	 * @param ?callable(): void $beforeNotify what happens as it sends a notification
	 */
	private function notifications(?callable $beforeNotify = null): IManager {
		$manager = $this->createStub(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$values = new ArrayObject();
			$notification = $this->createStub(INotification::class);
			$notification->method('setApp')->willReturnCallback(static function (string $app) use ($values, $notification): INotification {
				$values['app'] = $app;

				return $notification;
			});
			$notification->method('setUser')->willReturnCallback(static function (string $user) use ($values, $notification): INotification {
				$values['user'] = $user;

				return $notification;
			});
			$notification->method('setDateTime')->willReturnCallback(static function (DateTime $dateTime) use ($values, $notification): INotification {
				$values['dateTime'] = $dateTime;

				return $notification;
			});
			$notification->method('setObject')->willReturnCallback(static function (string $type, string $id) use ($values, $notification): INotification {
				$values['object'] = [$type, $id];

				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(static function (string $subject, array $parameters) use ($values, $notification): INotification {
				$values['subjectName'] = $subject;
				$values['subject'] = $parameters;

				return $notification;
			});
			$this->pendingNotifications[spl_object_id($notification)] = $values;

			return $notification;
		});
		$manager->method('notify')->willReturnCallback(function (INotification $notification) use ($beforeNotify): void {
			if ($beforeNotify !== null) {
				$beforeNotify();
			}
			$this->sent[] = $this->pendingNotifications[spl_object_id($notification)]->getArrayCopy();
		});

		return $manager;
	}

}
