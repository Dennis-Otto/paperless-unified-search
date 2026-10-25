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
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Shares;
use OCP\Constants;
use OCP\Http\Client\IResponse;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
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
	use Shares;

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
			. "✓ The account of the API token may change no document, and may view correspondents and document types.\n"
			. "✓ The archive account is archive.\n"
			. "✓ Coverage: 3 of 3 Paperless document(s) have a file of the archive account.\n"
			. "✓ Shared read-only: 1 user, 1 group.\n",
			$tester->getDisplay(),
		);
		self::assertSame('', $tester->getErrorOutput());
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
			. "✓ The account of the API token may change no document, and may view correspondents and document types.\n"
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
			. "✓ The account of the API token may change no document, and may view correspondents and document types.\n"
			. "✓ The archive account is archive, the account of Paperless Sync.\n"
			. "! Coverage: 1 document(s) have a file of the archive account; Paperless has 1,180 document(s), without naming them.\n"
			. self::NO_SHARES,
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
			. "✓ The account of the API token may change no document, and may view correspondents and document types.\n"
			. "✓ The archive account is archive.\n"
			. "! Coverage: 0 of 2 Paperless document(s) have a file of the archive account. Without one: 1, 2.\n"
			. "! 1 document(s) have a file of the archive account, but the account of the API token doesn't see them in Paperless, maybe because they were deleted there.\n"
			. self::NO_SHARES,
			$tester->getDisplay(),
		);
		self::assertSame(self::NONE_COVERED, $tester->getErrorOutput());
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
			. "✓ The archive account is archive.\n"
			. "✓ Coverage: 1 of 1 Paperless document(s) have a file of the archive account.\n"
			. "! The archive account shares none of its files, so only it finds its documents.\n",
			$tester->getDisplay(),
		);
		self::assertSame('https://paperless.example.com/api/ui_settings/', $this->requests[1][0]);
		// The overview of the administration settings warns until the next test.
		self::assertSame('staff,changes_documents,hides_correspondents,hides_document_types', $this->settings[AppConstants::APP_ID . '.token_warnings']);
	}

	/**
	 * Paperless tells what the account may do only when it may view its UI settings; the
	 * check says that it can't tell, and passes.
	 */
	public function testSaysWhenPaperlessDoesNotTellWhatTheAccountOfTheTokenMayDo(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Document [P1].pdf');
		$this->privileges = $this->answer(403, '{"detail":"You do not have permission to perform this action."}');
		$tester = $this->tester($this->documents([['id' => 1]], 1, [1]));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringContainsString(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "! Paperless doesn't say what the account of the API token may do, so the check can't tell whether it may change documents. Give the account the permission View for UI Settings in Paperless, so that the check can tell.\n"
			. "✓ The archive account is archive.\n",
			$tester->getDisplay(),
		);
	}

	public function testFailsWithoutAnArchiveAccount(): void {
		$this->connected('');
		$tester = $this->tester($this->documents([], 0, []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The account of the API token may change no document, and may view correspondents and document types.\n",
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
		$accounts = $this->accounts('archive', 'sam');
		$configService = $this->configService($accounts);

		return new Check(
			$configService,
			$this->paperlessApi($configService, $this->paperless(...$answers)),
			new NextcloudFileLocator($this->rootFolder(), $this->createStub(LoggerInterface::class)),
			$this->diagnostics(),
			$accounts,
			$this->messages(),
			$this->archiveShares(),
		);
	}
}
