<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Command;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Command\Search;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\SearchExplainer;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCP\Files\File;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * occ paperless_unified_search:search, as jamie searches for "invoice": Paperless ranks
 * four documents first, of twelve. The archive account archive has shared the file of 412
 * with jamie, keeps the one of 389 to itself, and has none of 371 and 360; jamie has a
 * copy of 371 from sam.
 */
final class SearchTest extends TestCase {
	use Accounts;
	use AppServices;
	use Files;

	private const HITS = [
		['id' => 412, 'title' => 'Electricity invoice'],
		['id' => 389, 'title' => 'Home insurance'],
		['id' => 371, 'title' => 'Internet invoice'],
		['id' => 360, 'title' => ' Letter '],
	];

	protected function setUp(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Electricity [P412].pdf');
		$this->file('/jamie/files/Electricity [P412].pdf');
		$this->file('/archive/files/Paperless/Insurance [P389].pdf');
		$this->file('/jamie/files/Copy [P371].pdf', 'sam');
	}

	public function testListsWhetherEachDocumentShows(): void {
		$tester = $this->tester($this->documents(self::HITS, 12));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => ' invoice ']));

		self::assertSame([
			'| ID  | Title               | Verdict | File                    |',
			'| 412 | Electricity invoice | shown   | /Electricity [P412].pdf |',
			'| 389 | Home insurance      | hidden  |                         |',
			'| 371 | Internet invoice    | hidden  |                         |',
			'| 360 | Letter              | hidden  |                         |',
			'Paperless found 12 document(s) for "invoice"; 1 of the 4 above show to jamie.',
			'Add --explain to see why the others don\'t.',
		], self::rows($tester->getDisplay()));
		// As the search does it: one search of the file cache for the files of the page.
		self::assertCount(1, $this->searches);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://paperless.example.com/api/documents/', $url);
		self::assertSame(['query' => 'invoice', 'page' => 1, 'page_size' => 10], array_intersect_key($options['query'], ['query' => 0, 'page' => 0, 'page_size' => 0]));
	}

	public function testExplainsWhyTheOthersDoNotShow(): void {
		$tester = $this->tester($this->documents(self::HITS, 12));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => 'invoice', '--explain' => true, '--limit' => '4']));

		self::assertSame([
			'| ID  | Title               | Verdict    | File                                                              |',
			'| 412 | Electricity invoice | shown      | /Electricity [P412].pdf                                           |',
			'| 389 | Home insurance      | not shared | /Paperless/Insurance [P389].pdf of archive, not shared with jamie |',
			'| 371 | Internet invoice    | not owned  | /Copy [P371].pdf of sam                                           |',
			'| 360 | Letter              | no file    |                                                                   |',
			'Paperless found 12 document(s) for "invoice"; 1 of the 4 above show to jamie.',
			'Not shared (389): the archive account archive has the file, but hasn\'t shared it with jamie. Share the file, or a folder that holds it, read-only with jamie.',
			'Not owned (371): jamie sees a file with the marker of the document, but only the files of the archive account archive stand for documents.',
			'No file (360): no file of the archive account archive has the marker of the document, such as [P360], in its name. The synchronization hasn\'t written it yet, or names files without it.',
		], self::rows($tester->getDisplay()));
		self::assertSame(4, $this->requests[0][1]['query']['page_size']);
	}

	/**
	 * A file that jamie may write proves nothing, since jamie could have named it: its
	 * share is what to correct.
	 */
	public function testExplainsThatJamieMayWriteTheFileOfADocument(): void {
		$this->file('/jamie/files/Upload/Receipt [P377].pdf', writable: true);
		$tester = $this->tester($this->documents([['id' => 377, 'title' => 'Receipt'], ...self::HITS], 12));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => 'invoice', '--explain' => true, '--limit' => '2']));

		self::assertSame([
			'| ID  | Title               | Verdict  | File                                                      |',
			'| 377 | Receipt             | writable | /Upload/Receipt [P377].pdf of archive, jamie may write it |',
			'| 412 | Electricity invoice | shown    | /Electricity [P412].pdf                                   |',
		], array_slice(self::rows($tester->getDisplay()), 0, 3));
		self::assertContains(
			'Writable (377): jamie may change the file of the archive account archive, or add files to its folder, so anyone with that share could have given it the marker of the document. Share it read-only with jamie.',
			self::rows($tester->getDisplay()),
		);
	}

	public function testWritesTheDocumentsAsJson(): void {
		$tester = $this->tester($this->documents([...self::HITS, ['id' => 'none'], ['title' => 'No ID']], 12));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => 'invoice', '--explain' => true, '--json' => true]));

		self::assertSame([
			'user' => 'jamie',
			'term' => 'invoice',
			'count' => 12,
			'documents' => [
				['title' => 'Electricity invoice', 'id' => 412, 'verdict' => 'shown', 'path' => '/Electricity [P412].pdf', 'owner' => 'archive'],
				['title' => 'Home insurance', 'id' => 389, 'verdict' => 'not_shared', 'path' => '/Paperless/Insurance [P389].pdf', 'owner' => 'archive'],
				['title' => 'Internet invoice', 'id' => 371, 'verdict' => 'not_owned', 'path' => '/Copy [P371].pdf', 'owner' => 'sam'],
				['title' => 'Letter', 'id' => 360, 'verdict' => 'no_file', 'path' => '', 'owner' => ''],
			],
		], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
	}

	/**
	 * The JSON is for people as well as for scripts: indented, with the slashes of a path
	 * and the letters of a title as they are. A term that a shell sent in another encoding
	 * than UTF-8 gets the replacement character, instead of leaving no JSON at all.
	 */
	public function testWritesJsonThatAPersonCanRead(): void {
		$tester = $this->tester($this->documents([['id' => 412, 'title' => 'Stromrechnung März']], 1));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => "M\xE4rz", '--json' => true]));

		self::assertSame(<<<JSON
			{
			    "user": "jamie",
			    "term": "M\u{FFFD}rz",
			    "count": 1,
			    "documents": [
			        {
			            "title": "Stromrechnung März",
			            "id": 412,
			            "verdict": "shown",
			            "path": "/Electricity [P412].pdf",
			            "owner": "archive"
			        }
			    ]
			}
			JSON . "\n", $tester->getDisplay());
	}

	/**
	 * A hit without the ID of a document, a whole number above 0, stands for none, as in
	 * the search; a title that isn't text shows as none.
	 */
	public function testReadsTheHitsAsTheSearchDoes(): void {
		$tester = $this->tester($this->documents([
			['id' => 0, 'title' => 'Zero'],
			['id' => -3, 'title' => 'Negative'],
			['id' => 0.5, 'title' => 'A fraction'],
			['id' => 'none', 'title' => 'Text'],
			['title' => 'No ID'],
			['id' => '412', 'title' => 'Electricity invoice'],
			['id' => '389.0', 'title' => 7],
		], 7));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => 'invoice']));

		self::assertSame([
			'| ID  | Title               | Verdict | File                    |',
			'| 412 | Electricity invoice | shown   | /Electricity [P412].pdf |',
			'| 389 |                     | hidden  |                         |',
			'Paperless found 7 document(s) for "invoice"; 1 of the 2 above show to jamie.',
			'Add --explain to see why the others don\'t.',
		], self::rows($tester->getDisplay()));
	}

	/**
	 * The limit counts from 1 to the 50 hits of a page of Paperless, both included.
	 *
	 * @return array<string, array{string, list<string>}>
	 */
	public static function limits(): array {
		return [
			'the first document' => ['1', [
				'| 412 | Electricity invoice | shown   | /Electricity [P412].pdf |',
				'Paperless found 12 document(s) for "invoice"; 1 of the 1 above show to jamie.',
			]],
			'a page of Paperless' => ['50', [
				'| 412 | Electricity invoice | shown   | /Electricity [P412].pdf |',
				'| 389 | Home insurance      | hidden  |                         |',
				'| 371 | Internet invoice    | hidden  |                         |',
				'| 360 | Letter              | hidden  |                         |',
				'Paperless found 12 document(s) for "invoice"; 1 of the 4 above show to jamie.',
			]],
		];
	}

	/**
	 * @param list<string> $rows
	 */
	#[DataProvider('limits')]
	public function testGoesThroughAsManyDocumentsAsTheLimitSays(string $limit, array $rows): void {
		$tester = $this->tester($this->documents(self::HITS, 12));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => 'invoice', '--limit' => $limit]));

		self::assertSame((int)$limit, $this->requests[0][1]['query']['page_size']);
		self::assertSame($rows, array_slice(self::rows($tester->getDisplay()), 1, count($rows)));
	}

	/**
	 * Titles from Paperless and names of files are text, not markup of the console.
	 */
	public function testShowsTitlesAsText(): void {
		$tester = $this->tester($this->documents([['id' => 412, 'title' => '<error>Invoice</error>']], 1));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => '<info>']));

		self::assertSame([
			'| ID  | Title                  | Verdict | File                    |',
			'| 412 | <error>Invoice</error> | shown   | /Electricity [P412].pdf |',
			'Paperless found 1 document(s) for "<info>"; 1 of the 1 above show to jamie.',
		], self::rows($tester->getDisplay()));
	}

	public function testSaysWhenPaperlessFindsNothing(): void {
		$tester = $this->tester($this->documents([], 0));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => 'nothing']));

		self::assertSame(['Paperless found 0 document(s) for "nothing"; 0 of the 0 above show to jamie.'], self::rows($tester->getDisplay()));
	}

	public function testSaysThatAFileFailedToAnswer(): void {
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Letter [P360].pdf');
		$broken->method('getPath')->willReturn('/jamie/files/Letter [P360].pdf');
		$broken->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));
		$this->visible['jamie'][] = $broken;
		$tester = $this->tester($this->documents(self::HITS, 12));

		self::assertSame(Command::SUCCESS, $tester->execute(['user' => 'jamie', 'term' => 'invoice'], ['capture_stderr_separately' => true]));

		self::assertSame("Nextcloud failed to read a file (RuntimeException), so a document without a file above may have one.\n", $tester->getErrorOutput());
	}

	/**
	 * @return array<string, array{array<string, string>, int, string}>
	 */
	public static function invalidCalls(): array {
		return [
			'a limit of 0' => [['--limit' => '0'], Command::INVALID, 'The limit is a number from 1 to 50.'],
			'a limit above a page' => [['--limit' => '51'], Command::INVALID, 'The limit is a number from 1 to 50.'],
			'a limit that is no number' => [['--limit' => 'ten'], Command::INVALID, 'The limit is a number from 1 to 50.'],
			'a limit that is no whole number' => [['--limit' => '2.5'], Command::INVALID, 'The limit is a number from 1 to 50.'],
			'a blank term' => [['term' => ' '], Command::INVALID, 'Name a term to search for.'],
			'an account that does not exist' => [['user' => 'nobody'], Command::FAILURE, 'There is no account "nobody".'],
		];
	}

	/**
	 * @param array<string, string> $call
	 */
	#[DataProvider('invalidCalls')]
	public function testRefusesAnInvalidCall(array $call, int $status, string $message): void {
		$tester = $this->tester();

		self::assertSame($status, $tester->execute([...['user' => 'jamie', 'term' => 'invoice'], ...$call], ['capture_stderr_separately' => true]));

		self::assertSame($message . "\n", $tester->getErrorOutput());
		self::assertSame([], $this->requests);
	}

	public function testNeedsAConnection(): void {
		unset($this->settings[AppConstants::APP_ID . '.paperless_url']);
		$tester = $this->tester();

		self::assertSame(Command::FAILURE, $tester->execute(['user' => 'jamie', 'term' => 'invoice'], ['capture_stderr_separately' => true]));

		self::assertSame("Paperless Unified Search isn't connected to Paperless yet. Connect it with occ paperless_unified_search:setup.\n", $tester->getErrorOutput());
		self::assertSame([], $this->requests);
	}

	/**
	 * occ shows an error in its colours, white on red, where the terminal shows colours.
	 */
	public function testShowsAnErrorInTheColoursOfAnError(): void {
		$tester = $this->tester();

		self::assertSame(Command::INVALID, $tester->execute(['user' => 'jamie', 'term' => 'invoice', '--limit' => '0'], ['capture_stderr_separately' => true, 'decorated' => true]));

		self::assertSame("\e[37;41mThe limit is a number from 1 to 50.\e[39;49m\n", $tester->getErrorOutput());
	}

	public function testNeedsAnArchiveAccount(): void {
		$this->settings[AppConstants::APP_ID . '.archive_owner'] = 'gone';
		$tester = $this->tester();

		self::assertSame(Command::FAILURE, $tester->execute(['user' => 'jamie', 'term' => 'invoice'], ['capture_stderr_separately' => true]));

		self::assertSame("No archive account exists, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account.\n", $tester->getErrorOutput());
	}

	public function testNamesAFailureOfPaperlessWithoutTheTermAndTheToken(): void {
		$tester = $this->tester($this->answer(500, 'Server Error'));

		self::assertSame(Command::FAILURE, $tester->execute(['user' => 'jamie', 'term' => 'invoice'], [
			'capture_stderr_separately' => true,
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]));

		self::assertSame(
			"Paperless answered with an error (HTTP 500). Check its log, or try again later.\nhttp, OCA\\PaperlessUnifiedSearch\\Exception\\PaperlessRequestException: Paperless returned HTTP 500.\n",
			$tester->getErrorOutput(),
		);
	}

	public function testNamesARequestWithoutAnswer(): void {
		$error = new RuntimeException('cURL error 28: Operation timed out after 3001 ms for https://paperless.example.com/api/documents/?query=invoice');
		$tester = $this->tester($error, $error);

		self::assertSame(Command::FAILURE, $tester->execute(['user' => 'jamie', 'term' => 'invoice'], [
			'capture_stderr_separately' => true,
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]));

		self::assertSame(
			"Paperless did not answer in time. Check that it runs and is not overloaded, then try again.\ntimeout, RuntimeException: cURL error 28: Operation timed out after 3001 ms for https://paperless.example.com/api/documents/\n",
			$tester->getErrorOutput(),
		);
	}

	/**
	 * An error may repeat what the request sent, as a proxy may with its headers: the
	 * error shows without the token and the term.
	 */
	public function testNamesARequestWithoutAnswerWithoutTheTokenAndTheTerm(): void {
		$error = new RuntimeException('cURL error 56: Recv failure: the proxy refused "Authorization: Token TEST_VALUE" for invoice');
		$tester = $this->tester($error, $error);

		self::assertSame(Command::FAILURE, $tester->execute(['user' => 'jamie', 'term' => 'invoice'], [
			'capture_stderr_separately' => true,
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]));

		self::assertSame('unreachable, RuntimeException: cURL error 56: Recv failure: the proxy refused "Authorization: Token …" for …', self::rows($tester->getErrorOutput())[1]);
		self::assertStringNotContainsString('TEST_VALUE', $tester->getErrorOutput());
	}

	/**
	 * Paperless 2 refuses a query in its advanced syntax that it can't read, and has no
	 * simple mode of its search that would take it instead.
	 */
	public function testSaysWhenPaperlessRefusesTheQuery(): void {
		$tester = $this->tester($this->answer(400, '{"query":["Invalid date value"]}', ['X-Version' => '2.20.13', 'X-Api-Version' => '9']));

		self::assertSame(Command::FAILURE, $tester->execute(['user' => 'jamie', 'term' => 'created:[2026 to'], ['capture_stderr_separately' => true]));

		self::assertSame("Paperless refused the query (HTTP 400): it can't read it in its advanced syntax, as a range of dates that isn't complete, such as created:[2026 to.\n", $tester->getErrorOutput());
	}

	public function testIsTheSearchOfTheApp(): void {
		$command = $this->command();

		self::assertSame('paperless_unified_search:search', $command->getName());
		self::assertSame(['user', 'term'], array_keys($command->getDefinition()->getArguments()));
		self::assertSame('10', $command->getDefinition()->getOption('limit')->getDefault());
		self::assertSame('How many documents of the ranking of Paperless to go through, up to 50', $command->getDefinition()->getOption('limit')->getDescription());
	}

	private function tester(IResponse|Throwable ...$answers): CommandTester {
		return new CommandTester($this->command(...$answers));
	}

	private function command(IResponse|Throwable ...$answers): Search {
		$accounts = $this->accounts('archive', 'jamie', 'sam');
		$configService = $this->configService($accounts);
		$locator = new NextcloudFileLocator($this->rootFolder(), $this->createStub(LoggerInterface::class));

		return new Search(
			$configService,
			$this->paperlessApi($configService, $this->paperless(...$answers)),
			new SearchExplainer($locator, $accounts),
			$accounts,
			$this->messages(),
		);
	}

	/**
	 * The rows of the table and the lines below it, without its borders.
	 *
	 * @return list<string>
	 */
	private static function rows(string $output): array {
		return array_values(array_filter(
			array_map('rtrim', explode("\n", $output)),
			static fn (string $line): bool => $line !== '' && !str_starts_with($line, '+'),
		));
	}
}
