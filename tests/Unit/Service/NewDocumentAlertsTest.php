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
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
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
			'fields' => 'id,title',
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
			'fields' => 'id,title',
			'added__date__gte' => gmdate('Y-m-d', self::NOW - 300 - 86400),
		], $this->requests[0][1]['query']);
		self::assertSame([], $this->sent);
		self::assertSame(412, $this->stored()['lastSeen']);
		self::assertSame(self::NOW, $this->stored()['checkedAt']);
		self::assertSame([], $this->stored()['waiting']);
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
	 * Paperless. The log says so, without the words of the user.
	 */
	public function testARefusedSearchFindsNothingAndTheOthersGoOn(): void {
		$this->wants('jamie', false, 'created:[2026 to', 'tag:unpaid');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			$this->answer(400, '{"query": ["Invalid"]}', []),
			$this->documents([['id' => 101]]),
		));

		self::assertCount(1, $this->sent);
		self::assertSame(0, $this->stored()['failures']);
		self::assertSame(0, $this->stored()['restingUntil']);
		self::assertSame([['info', 'Paperless refused {count} saved search(es) of the notifications about new documents', ['app' => AppConstants::APP_ID, 'count' => 1]]], $this->logged);
	}

	/**
	 * One user's search that Paperless fails to answer, such as a wildcard that takes too
	 * long, stops nobody else: it finds nothing, the log counts it without its words, and
	 * the job doesn't rest, since the other search shows that Paperless answers.
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
		self::assertSame([['warning', '{count} saved search(es) of the notifications about new Paperless documents failed ({errorType})', ['app' => AppConstants::APP_ID, 'count' => 1, 'errorType' => RuntimeException::class]]], $this->logged);
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
	 * When every search fails, it is Paperless that is down: the documents keep waiting
	 * and the job rests, also for whoever asked for every document.
	 */
	public function testEverySearchFailingRestsTheJob(): void {
		$this->wants('jamie', true);
		$this->wants('sam', false, 'tag:unpaid', 'tag:bills');
		$this->seen(100);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless(
			$this->documents([['id' => 101, 'title' => 'A']]),
			new RuntimeException('Connection refused'),
			new RuntimeException('Connection refused'),
		));

		self::assertSame([], $this->sent);
		self::assertSame([['id' => 101, 'title' => 'A', 'since' => self::NOW]], $this->stored()['waiting']);
		self::assertSame(1, $this->stored()['failures']);
		self::assertSame(self::NOW + 600, $this->stored()['restingUntil']);
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
	 * A search that fails stops the run before any notification goes out, with the documents
	 * still waiting and the count of the failures going on, so that the next run asks again.
	 */
	public function testAFailedSearchKeepsTheDocumentsWaiting(): void {
		$this->wants('jamie', false, 'tag:unpaid');
		$this->seen(100, failures: 1);
		$this->file('/archive/files/A [P101].pdf');
		$this->file('/jamie/files/A [P101].pdf');

		$this->runJob($this->paperless($this->documents([['id' => 101, 'title' => 'A']]), $this->answer(500, 'Server Error', [])));

		self::assertSame([], $this->sent);
		self::assertSame(101, $this->stored()['lastSeen']);
		self::assertSame(self::NOW, $this->stored()['checkedAt']);
		self::assertSame([['id' => 101, 'title' => 'A', 'since' => self::NOW]], $this->stored()['waiting']);
		self::assertSame(2, $this->stored()['failures']);
		self::assertSame(self::NOW + 1200, $this->stored()['restingUntil']);
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

		(new NewDocumentAlerts(
			$configService,
			new SavedSearches($this->userConfig()),
			$this->paperlessApi($configService, $client),
			new NextcloudFileLocator($this->brokenAwareRoot(), $this->createStub(LoggerInterface::class)),
			$accounts,
			$notifications ?? $this->notifications(),
			$appConfig ?? $this->appConfig(),
			$clock,
			$logger,
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
