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
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

	public function testPassesWhenEveryDocumentHasAFile(): void {
		$this->connected();
		foreach ([1, 2, 3] as $documentId) {
			$this->file('/archive/files/Paperless/Document [P' . $documentId . '].pdf');
		}
		$tester = $this->tester($this->documents([['id' => 3]], 3, [3, 2, 1]));

		self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame(
			"✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "✓ The archive account is archive.\n"
			. "✓ Coverage: 3 of 3 Paperless document(s) have a file of the archive account.\n",
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
			. "✓ The archive account is archive.\n"
			. "! Coverage: 3 of 1,234 Paperless document(s) have a file of the archive account. Without one, among others: 4, 5, 6, 7, 8, 9, 10, 11, 12, 13.\n"
			. "! 1 file(s) of the archive account carry the marker of a document that the account of the API token doesn't see in Paperless, maybe one deleted there.\n",
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
			. "✓ The archive account is archive, the account of Paperless Sync.\n"
			. "! Coverage: 1 file(s) of the archive account carry the marker of a document; Paperless has 1,180 document(s), without naming them.\n",
			$tester->getDisplay(),
		);
	}

	public function testFailsWithoutAConnection(): void {
		$this->settings[AppConstants::APP_ID . '.archive_owner'] = 'archive';
		$tester = $this->tester();

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame("✓ The archive account is archive.\n", $tester->getDisplay());
		self::assertSame("Paperless Unified Search isn't connected to Paperless yet. Connect it with occ paperless_unified_search:setup.\n", $tester->getErrorOutput());
		self::assertSame([], $this->searches);
	}

	public function testFailsWhenPaperlessFails(): void {
		$this->connected();
		$tester = $this->tester($this->answer(502, 'Bad Gateway', []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame("✓ The archive account is archive.\n", $tester->getDisplay());
		self::assertSame("Paperless answered with an error (HTTP 502). Check its log, or try again later.\n", $tester->getErrorOutput());
	}

	public function testFailsWithoutAnArchiveAccount(): void {
		$this->connected('');
		$tester = $this->tester($this->documents([], 0, []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame("✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n", $tester->getDisplay());
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
		);
	}
}
