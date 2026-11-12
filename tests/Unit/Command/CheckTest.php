<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Command;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Command\Check;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\TrustedMounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Owners;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Shares;
use OCP\Constants;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * occ paperless_unified_search:check, with the archive account archive, which owns the
 * synchronized files.
 */
final class CheckTest extends TestCase {
	use Accounts;
	use AppServices;
	use Files;
	use Owners;
	use Shares;

	/** The users of Paperless of the tests of the archives of each owner: jamie and Sam. */
	private const USERS = '{"count":2,"next":null,"results":[{"id":5,"username":"jamie"},{"id":6,"username":"Sam"}]}';

	/** The cache of the users of Paperless, when a test fills it before the check. */
	private ?MemoryCache $cache = null;

	private const NO_SHARES = "! The archive account shares none of its files, so only it finds its documents.\n";

	private const NONE_COVERED = "No document of Paperless has a file of the archive account archive, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account, and check that their names carry the markers of the documents, such as [P412].\n";

	public function testPassesWhenEveryDocumentHasAFile(): void {
		$this->connected();
		foreach ([1, 2, 3] as $documentId) {
			$this->file('/archive/files/Paperless/Document [P' . $documentId . '].pdf');
		}
		$this->share(IShare::TYPE_GROUP, 'staff', '/Paperless');
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless', Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
		$tester = $this->tester($this->documents([['id' => 3]], 3, [3, 2, 1]));

		self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents, document types and tags.\n"
			. "✓ The archive account is archive.\n"
			. "✓ Coverage: 3 of 3 Paperless document(s) have a file of the archive account.\n"
			. "✓ Shared read-only: 1 user, 1 group.\n",
			$tester->getDisplay(),
		);
		self::assertSame('', $tester->getErrorOutput());
		// Without an archive of each owner, the check asks Paperless for no owners.
		self::assertSame([], array_filter($this->requests, static fn (array $request): bool => ($request[1]['query']['fields'] ?? null) === 'id,owner,tags'));
		self::assertSame('3.3.0', $this->settings[AppConstants::APP_ID . '.paperless_version']);
	}

	/**
	 * Documents that have no file yet, such as those in the inbox of Paperless, and files
	 * of documents that Paperless no longer lists: neither keeps the search from working.
	 */
	public function testNamesTheDocumentsWithoutAFile(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$this->file('/archive/files/Paperless/Document [P2] and [P3].pdf');
		$this->file('/archive/files/Paperless/Deleted [P9999].pdf');
		// Only the archive account's own files count, not those that others share with it.
		$this->file('/archive/files/Shared/Copy [P4].pdf', 'sam');
		$this->folder('/archive/files/Scans [P5]');
		$all = range(1, 1234);
		$tester = $this->tester($this->documents([['id' => 1]], 1234, $all));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents, document types and tags.\n"
			. "✓ The archive account is archive.\n"
			. "! Coverage: 3 of 1,234 Paperless document(s) have a file of the archive account. Without one, among others: 4, 5, 6, 7, 8, 9, 10, 11, 12, 13.\n"
			. "! 1 document(s) have a file of the archive account, but the account of the API token doesn't see them in Paperless, maybe because they were deleted there.\n"
			. self::NO_SHARES,
			$tester->getDisplay(),
		);
		// One search of the file cache for all files with a marker.
		self::assertCount(1, $this->searches);
	}

	public function testNamesEveryDocumentWithoutAFileWhenTheyAreFew(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$tester = $this->tester($this->documents([['id' => 1]], 3, [1, 2, 3]));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString("! Coverage: 1 of 3 Paperless document(s) have a file of the archive account. Without one: 2, 3.\n", $tester->getDisplay());
	}

	/**
	 * Ten documents without a file are all that the check names, without others among them.
	 */
	public function testNamesTenDocumentsWithoutAFileAsAll(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$tester = $this->tester($this->documents([['id' => 1]], 11, range(1, 11)));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString("! Coverage: 1 of 11 Paperless document(s) have a file of the archive account. Without one: 2, 3, 4, 5, 6, 7, 8, 9, 10, 11.\n", $tester->getDisplay());
	}

	/**
	 * A Paperless that doesn't list the IDs of all documents can only be compared by the
	 * number of documents.
	 */
	public function testComparesOnlyTheNumberWithoutTheIdsOfPaperless(): void {
		$this->connected();
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings[AppConstants::APP_ID . '.archive_owner'] = '';
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$tester = $this->tester($this->documents([['id' => 1]], 1180, headers: []));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"✓ Paperless answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents, document types and tags.\n"
			. "✓ The archive account is archive, the account of Paperless Sync.\n"
			. "! Coverage: 1 document(s) have a file of the archive account; Paperless has 1,180 document(s), without naming them.\n"
			. self::NO_SHARES,
			$tester->getDisplay(),
		);
	}

	/**
	 * Paperless Sync can give each user the documents that their user of Paperless owns or
	 * may view as files of their own. The copies of the owners count, as with the documents
	 * that each user owns, but not those of the users who may only view a document, and
	 * nothing said so (#338). The check says what the search still shows and links what to
	 * do; the search works with the files of the archive account and of the owners, so the
	 * check passes.
	 */
	public function testWarnsWhenPaperlessSyncGivesEachUserTheDocumentsThatTheyMayView(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user-shared';
		$this->file('/archive/files/Paperless/Without an owner [P1].pdf');
		$this->file('/jamie/files/Paperless/Own [P2].pdf', 'jamie');
		// The copy of a document that sam may only view.
		$this->file('/sam/files/Paperless/Shared with me [P2].pdf', 'sam');
		$tester = $this->tester(
			$this->documents([['id' => 1]], 2, [1, 2]),
			$this->documents([]),
			$this->documents([['id' => 1, 'owner' => null], ['id' => 2, 'owner' => 5]]),
			$this->answer(200, self::USERS),
		);

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents, document types and tags.\n"
			. "✓ The archive account is archive, the account of Paperless Sync.\n"
			. "! Paperless Sync also puts copies of the documents that each user may view in Paperless into their own files, which the search doesn't show: it shows the files of the archive account and of the owner of each document. See https://dennis-otto.github.io/paperless-unified-search/troubleshooting.html#paperless-sync-gives-each-user-their-own-archive\n"
			. "✓ Coverage: 2 of 2 Paperless document(s) have a file of the archive account or of the user of their owner.\n"
			. self::NO_SHARES,
			$tester->getDisplay(),
		);
	}

	/**
	 * With the documents of each owner in the files of their user (decision 0013), the
	 * check counts them as the search finds them: a file of the user whom Paperless Sync
	 * gives the owner of the document, in that user's folders, besides the files of the
	 * archive account. A file that another user renamed after a document of someone else
	 * counts for nothing, and neither does one that the user only received through a share.
	 * The owners come page by page, with only their IDs.
	 */
	public function testCountsTheFilesOfTheUserOfEachOwner(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->file('/archive/files/Paperless/Without an owner [P3].pdf');
		$this->file('/jamie/files/Paperless/Own [P1].pdf', 'jamie');
		$this->file('/jamie/files/Paperless/Also own [P5].pdf', 'jamie');
		$this->file('/sam/files/Paperless/Renamed [P4].pdf', 'sam');
		$this->file('/sam/files/Shared/Of jamie [P2].pdf', 'jamie');
		$tester = $this->tester(
			$this->documents([['id' => 1]], 5, [1, 2, 3, 4, 5]),
			$this->documents([]),
			$this->documents([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 6], ['id' => 3, 'owner' => null], ['id' => 4, 'owner' => 5], ['id' => 5, 'owner' => 5]]),
			$this->answer(200, self::USERS),
		);

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents, document types and tags.\n"
			. "✓ The archive account is archive, the account of Paperless Sync.\n"
			. "! Coverage: 3 of 5 Paperless document(s) have a file of the archive account or of the user of their owner. Without one: 2, 4.\n"
			. self::NO_SHARES,
			$tester->getDisplay(),
		);
		self::assertSame(['ordering' => 'id', 'page' => 1, 'page_size' => 1000, 'fields' => 'id,owner,tags'], $this->requests[3][1]['query']);
		self::assertSame('https://paperless.example.com/api/users/', $this->requests[4][0]);
	}

	/**
	 * A user of Nextcloud whose ID is a number, as some directories of users give them,
	 * counts as any other.
	 */
	public function testCountsTheFilesOfAUserWhoseIdIsANumber(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->file('/1234/files/Paperless/Own [P1].pdf', '1234');
		$tester = $this->tester(
			$this->documents([['id' => 1]], 1, [1]),
			$this->documents([]),
			$this->documents([['id' => 1, 'owner' => 7]]),
			$this->answer(200, '{"count":1,"next":null,"results":[{"id":7,"username":"1234"}]}'),
		);

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString("✓ Coverage: 1 of 1 Paperless document(s) have a file of the archive account or of the user of their owner.\n", $tester->getDisplay());
	}

	/**
	 * The users of Paperless that Paperless refused a moment ago, before the account got
	 * View for Users, would rest for ten minutes: the check asks for them anew, so that it
	 * tells what the search finds now, and the search does too from then on.
	 */
	public function testAsksAgainForTheUsersThatPaperlessRefusedBefore(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->file('/jamie/files/Paperless/Own [P1].pdf', 'jamie');
		$this->cache = new MemoryCache(static fn (): int => 0);
		$resting = 'users-resting-' . hash('sha256', 'https://paperless.example.com TEST_VALUE');
		$this->cache->set($resting, true, 600);
		$tester = $this->tester(
			$this->documents([['id' => 1]], 1, [1]),
			$this->documents([]),
			$this->documents([['id' => 1, 'owner' => 5]]),
			$this->answer(200, self::USERS),
		);

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString("✓ Coverage: 1 of 1 Paperless document(s) have a file of the archive account or of the user of their owner.\n", $tester->getDisplay());
		self::assertNull($this->cache->get($resting));
	}

	/**
	 * When the owners of the documents don't come, as from a Paperless that doesn't answer
	 * in time, the check can't look through the files of the users: it counts the files of
	 * the archive account alone, says so, and passes.
	 */
	public function testCountsOnlyTheArchiveAccountWithoutTheOwnersOfTheDocuments(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->file('/jamie/files/Paperless/Own [P1].pdf', 'jamie');
		$tester = $this->tester(
			$this->documents([['id' => 1]], 1, [1]),
			$this->documents([]),
			new RuntimeException('cURL error 28: Operation timed out after 10000 milliseconds'),
		);

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString("! Coverage: 0 of 1 Paperless document(s) have a file of the archive account. Without one: 1. Paperless Sync puts the documents of each user into their own files, which this count leaves out.\n", $tester->getDisplay());
	}

	/**
	 * Once the check could look through the files of the owners, not one document with a
	 * file in either place is the mistake that it is there to find: the search shows no
	 * document, and the check fails.
	 */
	public function testFailsWhenNoDocumentHasAFileOfTheArchiveAccountOrOfItsOwner(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->file('/sam/files/Paperless/Renamed [P1].pdf', 'sam');
		$tester = $this->tester(
			$this->documents([['id' => 1]], 2, [1, 2]),
			$this->documents([]),
			$this->documents([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 6]]),
			$this->answer(200, self::USERS),
		);

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertStringContainsString("! Coverage: 0 of 2 Paperless document(s) have a file of the archive account or of the user of their owner. Without one: 1, 2.\n", $tester->getDisplay());
		self::assertStringContainsString('No document of Paperless has a file of the archive account archive or of the user of its owner, so the search shows no Paperless documents.', $tester->getErrorOutput());
	}

	/**
	 * With the documents of each owner in the files of their user (decision 0013), the
	 * archive account holds only those that Paperless Sync gives no user, and maybe none:
	 * the check counts its files alone, says so, warns about nothing else and passes. Users
	 * that the account of the token can't view would leave the search with the archive
	 * account alone, which the check names.
	 */
	public function testCountsOnlyTheArchiveAccountWhenPaperlessSyncGivesEachOwnerAnArchive(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$this->privileges = $this->uiSettings(['view_document', 'view_correspondent', 'view_documenttype', 'view_tag']);
		$tester = $this->tester(
			$this->documents([['id' => 1]], 2, [1, 2]),
			$this->documents([]),
			$this->documents([['id' => 1, 'owner' => 5], ['id' => 2, 'owner' => 6]]),
			$this->answer(403, '{"detail":"You do not have permission to perform this action."}'),
		);

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "! The account of the API token may not view users in Paperless, so the search shows only the files of the archive account, not the documents that Paperless Sync puts into the files of each user. Give the account the permission View for Users.\n"
			. "✓ The archive account is archive, the account of Paperless Sync.\n"
			. "! Coverage: 0 of 2 Paperless document(s) have a file of the archive account. Without one: 1, 2. Paperless Sync puts the documents of each user into their own files, which this count leaves out.\n"
			. self::NO_SHARES,
			$tester->getDisplay(),
		);
	}

	/**
	 * Without the archives of each owner, the check doesn't name users that the account of
	 * the token can't view: the search reads none. Only Paperless names them.
	 */
	public function testCountsDocumentsWithoutNamingThemAlsoForTheArchivesOfEachOwner(): void {
		$this->connected('');
		$this->settings['paperless_sync.target_user'] = 'archive';
		$this->settings['paperless_sync.ownership'] = 'per-user';
		$tester = $this->tester($this->documents([['id' => 1]], 4));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString(
			"! Coverage: 0 document(s) have a file of the archive account; Paperless has 4 document(s), without naming them. Paperless Sync puts the documents of each user into their own files, which this count leaves out.\n",
			$tester->getDisplay(),
		);
	}

	/**
	 * An archive account that exists but owns none of the synchronized files, or only
	 * files without markers, leaves the search without a single Paperless document: the
	 * mistake that a script that reads the exit code must notice.
	 */
	public function testFailsWhenNoDocumentHasAFile(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Deleted [P9999].pdf');
		$this->file('/archive/files/Paperless/Invoice without a marker.pdf');
		$tester = $this->tester($this->documents([['id' => 1]], 2, [1, 2]));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents, document types and tags.\n"
			. "✓ The archive account is archive.\n"
			. "! Coverage: 0 of 2 Paperless document(s) have a file of the archive account. Without one: 1, 2.\n"
			. "! 1 document(s) have a file of the archive account, but the account of the API token doesn't see them in Paperless, maybe because they were deleted there.\n"
			. self::NO_SHARES,
			$tester->getDisplay(),
		);
		self::assertSame(self::NONE_COVERED, $tester->getErrorOutput());
	}

	/**
	 * The files in a Team folder or an external storage that the administrator trusts
	 * (decision 0019) belong to no account whose folders the check could look through: it
	 * says that it leaves them out, and passes without a file of the archive account, as
	 * the search may show documents through them.
	 */
	public function testLeavesOutTheFilesInTrustedMountsAndPassesWithoutTheArchive(): void {
		$this->connected();
		$this->settings[AppConstants::APP_ID . '.' . TrustedMounts::KEY] = 'team:3 external:7';
		$tester = $this->tester($this->documents([['id' => 1]], 2, [1, 2]));

		self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertStringContainsString("! Coverage: 0 of 2 Paperless document(s) have a file of the archive account. Without one: 1, 2. The files in the trusted mounts team:3, external:7 stand for documents too, and this count leaves them out.\n", $tester->getDisplay());
		self::assertSame('', $tester->getErrorOutput());
	}

	public function testLeavesOutTheFilesInTrustedMountsWithoutTheIdsOfPaperless(): void {
		$this->connected();
		$this->settings[AppConstants::APP_ID . '.' . TrustedMounts::KEY] = 'team:3';
		$tester = $this->tester($this->documents([['id' => 1]], 1180, headers: []));

		self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertStringEndsWith("! Coverage: 0 document(s) have a file of the archive account; Paperless has 1,180 document(s), without naming them. The files in the trusted mounts team:3 stand for documents too, and this count leaves them out.\n" . self::NO_SHARES, $tester->getDisplay());
		self::assertSame('', $tester->getErrorOutput());
	}

	public function testFailsWhenNoFileHasAMarkerWithoutTheIdsOfPaperless(): void {
		$this->connected();
		$tester = $this->tester($this->documents([['id' => 1]], 1180, headers: []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertStringEndsWith("! Coverage: 0 document(s) have a file of the archive account; Paperless has 1,180 document(s), without naming them.\n" . self::NO_SHARES, $tester->getDisplay());
		self::assertSame(self::NONE_COVERED, $tester->getErrorOutput());
	}

	/**
	 * A Paperless without documents leaves nothing to show, which is no mistake of the
	 * set-up, also when it doesn't list their IDs.
	 */
	public function testPassesWhenPaperlessHasNoDocuments(): void {
		$this->connected();
		$tester = $this->tester($this->documents([], 0, headers: []));

		self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertStringEndsWith("! Coverage: 0 document(s) have a file of the archive account; Paperless has 0 document(s), without naming them.\n" . self::NO_SHARES, $tester->getDisplay());
		self::assertSame('', $tester->getErrorOutput());
	}

	public function testFailsWithoutAConnection(): void {
		$this->settings[AppConstants::APP_ID . '.archive_owner'] = 'archive';
		$tester = $this->tester();

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		// The shares still say who would find the documents.
		self::assertSame("✓ The archive account is archive.\n" . self::NO_SHARES, $tester->getDisplay());
		self::assertSame("Paperless Unified Search isn't connected to Paperless yet. Connect it with occ paperless_unified_search:setup.\n", $tester->getErrorOutput());
		self::assertSame([], $this->searches);
	}

	public function testFailsWhenPaperlessFails(): void {
		$this->connected();
		$tester = $this->tester($this->answer(502, 'Bad Gateway', []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		// The shares still say who would find the documents.
		self::assertSame("✓ The archive account is archive.\n" . self::NO_SHARES, $tester->getDisplay());
		self::assertSame("Paperless answered with an error (HTTP 502). Check its log, or try again later.\n", $tester->getErrorOutput());
	}

	/**
	 * An error may repeat what the request sent, as a proxy may with its headers: -v shows
	 * it without the token.
	 */
	public function testNamesARequestWithoutAnswerWithoutTheToken(): void {
		$this->connected();
		$error = new RuntimeException('cURL error 56: Recv failure: the proxy refused "Authorization: Token TEST_VALUE"');
		$tester = $this->tester($error, $error);

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true, 'verbosity' => OutputInterface::VERBOSITY_VERBOSE]));

		self::assertStringEndsWith("\nunreachable, RuntimeException: cURL error 56: Recv failure: the proxy refused \"Authorization: Token …\"\n", $tester->getErrorOutput());
		self::assertStringNotContainsString('TEST_VALUE', $tester->getErrorOutput());
	}

	/**
	 * An account of the token that may do more than the search needs, or less than the
	 * results show, gets a warning with what to change, and the search still works.
	 */
	public function testWarnsAboutWhatTheAccountOfTheTokenMayDo(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$this->privileges = $this->uiSettings(['view_document', 'change_document'], staff: true);
		$tester = $this->tester($this->documents([['id' => 1]], 1, [1]));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "! The API token belongs to an Admin of Paperless, who may also read its logs: whoever gets hold of the token can too. Use the token of an account that is neither Admin nor Superuser.\n"
			. "! The account of the API token may add, change or delete documents in Paperless: whoever gets hold of the token can too. Use the token of an account that may only view documents.\n"
			. "! The account of the API token may not view correspondents in Paperless, so results show without them. Give the account the permission View for Correspondents.\n"
			. "! The account of the API token may not view document types in Paperless, so results show without them. Give the account the permission View for Document Types.\n"
			. "! The account of the API token may not view tags in Paperless, so results show without them. Give the account the permission View for Tags.\n"
			. "✓ The archive account is archive.\n"
			. "✓ Coverage: 1 of 1 Paperless document(s) have a file of the archive account.\n"
			. "! The archive account shares none of its files, so only it finds its documents.\n",
			$tester->getDisplay(),
		);
		self::assertSame('https://paperless.example.com/api/ui_settings/', $this->requests[1][0]);
		// The overview of the administration settings warns until the next test.
		self::assertSame('staff,changes_documents,hides_correspondents,hides_document_types,hides_tags,hides_users', $this->settings[AppConstants::APP_ID . '.token_warnings']);
	}

	/**
	 * A question for what the account may do that gets no answer in time, or no answer of
	 * Paperless, says nothing about the account: the check says that it can't tell this
	 * time, without the permission that it doesn't lack, keeps the warnings of the last
	 * test for the overview, and passes.
	 */
	public function testSaysWhenPaperlessDoesNotAnswerWhatTheAccountOfTheTokenMayDo(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$this->settings[AppConstants::APP_ID . '.token_warnings'] = 'changes_documents';
		$this->privileges = new RuntimeException('cURL error 28: Operation timed out after 3000 milliseconds');
		$tester = $this->tester($this->documents([['id' => 1]], 1, [1]));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "! Paperless didn't answer in time, or not as Paperless does, what the account of the API token may do, so the check can't tell this time whether it may change documents, and the overview keeps the warnings of the last test. Run the check again.\n"
			. "✓ The archive account is archive.\n",
			$tester->getDisplay(),
		);
		self::assertSame('changes_documents', $this->settings[AppConstants::APP_ID . '.token_warnings']);
	}

	/**
	 * Paperless tells what the account may do only when it may view its UI settings; the
	 * check says that it can't tell, and passes. The warnings of an earlier test belong to
	 * an account that has changed since, so the overview forgets them.
	 */
	public function testSaysWhenPaperlessDoesNotTellWhatTheAccountOfTheTokenMayDo(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$this->settings[AppConstants::APP_ID . '.token_warnings'] = 'superuser';
		$this->privileges = $this->answer(403, '{"detail":"You do not have permission to perform this action."}');
		$tester = $this->tester($this->documents([['id' => 1]], 1, [1]));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "! Paperless doesn't say what the account of the API token may do, so the check can't tell whether it may change documents. Give the account the permission View for UI Settings in Paperless, so that the check can tell.\n"
			. "✓ The archive account is archive.\n",
			$tester->getDisplay(),
		);
		self::assertArrayNotHasKey(AppConstants::APP_ID . '.token_warnings', $this->settings);
	}

	public function testFailsWithoutAnArchiveAccount(): void {
		$this->connected('');
		$tester = $this->tester($this->documents([], 0, []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents, document types and tags.\n",
			$tester->getDisplay(),
		);
		self::assertSame("No archive account is set, and Paperless Sync names none, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account.\n", $tester->getErrorOutput());
	}

	public function testFailsWhenTheArchiveAccountDoesNotExist(): void {
		$this->connected('gone');
		$tester = $this->tester($this->documents([], 0, []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame("The archive account \"gone\" does not exist, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account.\n", $tester->getErrorOutput());
	}

	/**
	 * The history of the problems of the search says whether searches failed since the
	 * last one that worked, which the test of the connection alone can't tell.
	 */
	public function testNotesAFailureThatHasNotRecovered(): void {
		$this->connected();
		$failure = new SearchEvent(1791400000, SearchEvent::KIND_FAILED, SearchEvent::STEP_PAPERLESS, 'PaperlessRequestException', 'Paperless returned HTTP 500.', 120);
		$this->settings[AppConstants::APP_ID . '.diagnostics'] = DiagnosticsHistory::start(1791300000)->with($failure)->jsonSerialize();
		$tester = $this->tester($this->documents([], 0, []));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringEndsWith("! The last search failed at 2026-10-07 19:06 UTC, and none has worked since. The settings list the problems of the search.\n", $tester->getDisplay());

		$this->settings[AppConstants::APP_ID . '.diagnostics'] = DiagnosticsHistory::start(1791300000)->with($failure)->recovered(1791450000)->jsonSerialize();
		$tester = $this->tester($this->documents([], 0, []));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringNotContainsString('The last search failed', $tester->getDisplay());
	}

	/**
	 * A share that allows changes lets its recipients give a file the marker of any
	 * document: the check names each, and what corrects it, without failing, since the
	 * search still shows documents to the others.
	 */
	public function testWarnsOfEachShareThatAllowsChanges(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless');
		$this->share(IShare::TYPE_USER, 'sam', '/Paperless', Constants::PERMISSION_ALL);
		$this->share(IShare::TYPE_LINK, 'token', '/Inbox', Constants::PERMISSION_CREATE);
		$tester = $this->tester($this->documents([['id' => 1]], 1, [1]));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringEndsWith(
			"✓ Coverage: 1 of 1 Paperless document(s) have a file of the archive account.\n"
			. "! Shared: 2 users, 1 public link.\n"
			. "! /Inbox has a public link that allows uploads or changes, so whoever has the link can add a file with the marker of any document. Make the link read-only.\n"
			. "! /Paperless is shared with the user sam with write permission, so the search shows sam none of the documents in it. Share it read-only.\n",
			$tester->getDisplay(),
		);
	}

	/**
	 * A list of the shares that may lack some is no reason for comfort.
	 */
	public function testNotesWhenTheListOfTheSharesMayLackSome(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless');
		$broken = $this->createStub(IShare::class);
		$broken->method('getShareOwner')->willReturn('archive');
		$broken->method('getNode')->willThrowException(new RuntimeException('Storage unavailable'));
		$this->shares[IShare::TYPE_GROUP][] = $broken;
		$tester = $this->tester($this->documents([['id' => 1]], 1, [1]));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringEndsWith("! Shared read-only: 1 user. Not every share could be read, so the list may lack some.\n", $tester->getDisplay());
	}

	/**
	 * Without an archive account, there are no shares to list.
	 */
	public function testListsNoSharesWithoutAnArchiveAccount(): void {
		$this->connected('');
		$tester = $this->tester($this->documents([], 0, []));

		self::assertSame(Command::FAILURE, $tester->execute([]));

		self::assertStringNotContainsString('Shared', $tester->getDisplay());
		self::assertSame([], $this->askedForShares);
	}

	public function testIsTheCheckOfTheApp(): void {
		self::assertSame('paperless_unified_search:check', $this->command()->getName());
	}

	private function tester(IResponse|Throwable ...$answers): CommandTester {
		return new CommandTester($this->command(...$answers));
	}

	private function command(IResponse|Throwable ...$answers): Check {
		$accounts = $this->accounts('archive', 'sam', 'jamie', '1234');
		$configService = $this->configService($accounts);
		$paperlessApi = $this->paperlessApi($configService, $this->paperless(...$answers));
		$cacheFactory = null;
		if ($this->cache !== null) {
			$cacheFactory = $this->createStub(ICacheFactory::class);
			$cacheFactory->method('createDistributed')->willReturn($this->cache);
		}

		return new Check(
			$configService,
			$paperlessApi,
			new NextcloudFileLocator($this->rootFolder(), $this->createStub(LoggerInterface::class)),
			$this->diagnostics(),
			$accounts,
			$this->messages(),
			$this->archiveShares(),
			$this->documentOwners($paperlessApi, $configService, $accounts, $cacheFactory),
			new TrustedMounts($this->appConfig()),
		);
	}
}
