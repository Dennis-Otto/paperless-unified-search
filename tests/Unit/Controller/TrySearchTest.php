<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Controller\SettingsController;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\SearchExplainer;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Files;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Http\Client\IResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Try a search of the settings page, as the administrator alex tries the search of jamie
 * for "invoice": Paperless ranks four documents first, of twelve. The archive account
 * archive has shared the file of 412 with jamie, keeps the one of 389 to itself, and has
 * none of 371 and 360; jamie has a copy of 371 from sam. The same documents as those of
 * occ paperless_unified_search:search --explain, which SearchTest goes through.
 */
final class TrySearchTest extends TestCase {
	use Accounts;
	use AppServices;
	use Files;

	/** What Paperless sends of each document besides its ID and title, which stays in the app. */
	private const CONTENT = ['content' => 'Account number DE00 0000 secret', '__search_hit__' => ['highlights' => 'Account <b>number</b>']];

	private const HITS = [
		['id' => 412, 'title' => 'Electricity invoice'] + self::CONTENT,
		['id' => 389, 'title' => 'Home insurance'] + self::CONTENT,
		['id' => 371, 'title' => 'Internet invoice'] + self::CONTENT,
		['id' => 360, 'title' => ' Letter '] + self::CONTENT,
	];

	protected function setUp(): void {
		$this->connected();
		$this->file('/archive/files/Paperless/Electricity [P412].pdf');
		$this->file('/jamie/files/Electricity [P412].pdf');
		$this->file('/archive/files/Paperless/Insurance [P389].pdf');
		$this->file('/jamie/files/Copy [P371].pdf', 'sam');
	}

	public function testNamesForEachDocumentWhetherItShowsToTheUserAndWhyNot(): void {
		$response = $this->controller($this->documents(self::HITS, 12))->trySearch(' invoice ', ' Jamie ');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'user' => 'jamie',
			'archiveAccount' => 'archive',
			'count' => 12,
			'documents' => [
				['id' => 412, 'title' => 'Electricity invoice', 'verdict' => 'shown'],
				['id' => 389, 'title' => 'Home insurance', 'verdict' => 'not_shared'],
				['id' => 371, 'title' => 'Internet invoice', 'verdict' => 'not_owned'],
				['id' => 360, 'title' => 'Letter', 'verdict' => 'no_file'],
			],
			'fileError' => false,
		], $this->payload($response));
		[$url, $options] = $this->requests[0];
		self::assertSame('https://paperless.example.com/api/documents/', $url);
		self::assertSame(['query' => 'invoice', 'page' => 1, 'page_size' => 20], array_intersect_key($options['query'], ['query' => 0, 'page' => 0, 'page_size' => 0]));
	}

	/**
	 * Administrators learn which titles a user finds, and nothing more of the documents:
	 * no content, no excerpt, no file and no account that owns one.
	 */
	public function testTheAnswerHoldsTheIdsAndTitlesOnly(): void {
		$answer = $this->controller($this->documents(self::HITS, 12))->trySearch('invoice', 'jamie')->render();

		foreach (['DE00', 'secret', 'number', '<b>', '[P', 'Paperless/', 'sam', '.pdf'] as $hidden) {
			self::assertStringNotContainsString($hidden, $answer);
		}
	}

	public function testGoesThroughTheFirstDocumentsOfTheRankingEachOnce(): void {
		$hits = [['id' => 412, 'title' => 'First'], ['id' => 412, 'title' => 'Again'], ['id' => 'none'], ['title' => 'No ID'], ['id' => 0]];
		for ($id = 1; $id <= 25; $id++) {
			$hits[] = ['id' => 1000 + $id];
		}

		$payload = $this->payload($this->controller($this->documents($hits, 99))->trySearch('invoice', 'jamie'));

		self::assertSame(
			[412, ...range(1001, 1015)],
			array_column($payload['documents'], 'id'),
		);
		self::assertSame(['First', ...array_fill(0, 15, '')], array_column($payload['documents'], 'title'));
	}

	public function testSaysWhenPaperlessFindsNothing(): void {
		self::assertSame(
			['user' => 'jamie', 'archiveAccount' => 'archive', 'count' => 0, 'documents' => [], 'fileError' => false],
			$this->payload($this->controller($this->documents([], 0))->trySearch('nothing', 'jamie')),
		);
	}

	public function testSaysThatAFileFailedToAnswer(): void {
		$broken = $this->createStub(File::class);
		$broken->method('getName')->willReturn('Letter [P360].pdf');
		$broken->method('getOwner')->willThrowException(new RuntimeException('Storage unavailable'));
		$this->visible['jamie'][] = $broken;

		$payload = $this->payload($this->controller($this->documents(self::HITS, 12))->trySearch('invoice', 'jamie'));

		self::assertTrue($payload['fileError']);
	}

	/**
	 * The log says which administrator saw the titles of which user, without the term,
	 * which the history of the problems keeps out as well.
	 */
	public function testLogsWhoTriedASearchAsWhomWithoutTheTerm(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with('{admin} tried a search of Paperless as {user} in the settings', ['app' => AppConstants::APP_ID, 'admin' => 'alex', 'user' => 'jamie']);

		$this->controller($this->documents(self::HITS, 12), $logger)->trySearch('invoice', 'JAMIE');
	}

	public function testLogsAnAdministratorWithoutASession(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with(self::anything(), self::callback(static fn (array $context): bool => $context['admin'] === ''));

		$this->controller($this->documents(self::HITS, 12), $logger, null)->trySearch('invoice', 'jamie');
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function invalidTries(): array {
		return [
			'a blank term' => [' ', 'jamie', 'no_term', 'Enter a term to search for.'],
			'no user' => ['invoice', ' ', 'unknown_user', 'Enter the user ID of the account to search as.'],
			'an account that does not exist' => ['invoice', 'nobody', 'unknown_user', 'There is no account nobody.'],
		];
	}

	#[DataProvider('invalidTries')]
	public function testRefusesATryWithoutATermOrAnAccount(string $term, string $user, string $code, string $message): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('info');

		$response = $this->controller(null, $logger)->trySearch($term, $user);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => $message, 'code' => $code], $this->payload($response));
		self::assertSame([], $this->requests);
	}

	public function testNeedsAConnection(): void {
		$this->settings = [];

		$response = $this->controller()->trySearch('invoice', 'jamie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => 'Paperless is not connected yet. Save its URL and API token above first.', 'code' => 'not_configured'], $this->payload($response));
	}

	public function testNeedsAnArchiveAccount(): void {
		$this->connected('');

		$response = $this->controller()->trySearch('invoice', 'jamie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => 'No archive account exists, so the search shows no Paperless documents. Name the account that owns the synchronized files above.',
			'code' => 'no_archive_account',
		], $this->payload($response));
		self::assertSame([], $this->requests);
	}

	/**
	 * @return array<string, array{IResponse|Throwable, string, string}>
	 */
	public static function failures(): array {
		return [
			'an error of Paperless' => [
				self::reply(500, 'Server Error'),
				'http',
				'Paperless answered with an error (HTTP 500). Check its log, or try again later.',
			],
			'a query that Paperless 2 cannot read' => [
				self::reply(400, '{"query":["Invalid date value"]}', ['X-Version' => '2.20.13', 'X-Api-Version' => '9']),
				'query_rejected',
				'Paperless can\'t read this term in its advanced syntax, such as a range of dates that isn\'t complete like created:[2026 to. Correct the term and try again.',
			],
		];
	}

	#[DataProvider('failures')]
	public function testNamesWhatFailedInPaperless(IResponse $answer, string $code, string $message): void {
		$response = $this->controller($answer)->trySearch('created:[2026 to', 'jamie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => $message, 'code' => $code], $this->payload($response));
	}

	public function testNamesARequestWithoutAnswer(): void {
		$error = new RuntimeException('cURL error 28: Operation timed out after 3001 ms for https://paperless.example.com/api/documents/?query=invoice');

		$response = $this->controller($error, null, 'alex', $error)->trySearch('invoice', 'jamie');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => 'Paperless did not answer in time. Check that it runs and is not overloaded, then try again.',
			'code' => 'timeout',
		], $this->payload($response));
	}

	/**
	 * The route as Nextcloud builds it, with the services of the app and a Paperless that
	 * gives these answers; $admin is the account of the session, null without one.
	 */
	private function controller(IResponse|Throwable|null $answer = null, ?LoggerInterface $logger = null, ?string $admin = 'alex', IResponse|Throwable ...$more): SettingsController {
		$accounts = $this->accounts('archive', 'jamie', 'sam', 'alex');
		$configService = $this->configService($accounts);
		$locator = new NextcloudFileLocator($this->rootFolder(), $this->createStub(LoggerInterface::class));
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($admin === null ? null : $accounts->get($admin));
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new SettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			$this->paperlessApi($configService, $this->paperless(...($answer === null ? [] : [$answer, ...$more]))),
			$this->diagnostics(),
			$this->messages(),
			$l10n,
			$logger ?? $this->createStub(LoggerInterface::class),
			new SearchExplainer($locator, $accounts),
			$accounts,
			$session,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(JSONResponse $response): array {
		$payload = json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($payload);

		return $payload;
	}

	/**
	 * An answer of Paperless for the data providers, which run before any test.
	 *
	 * @param array<string, string> $headers
	 */
	private static function reply(int $status, string $body, array $headers = self::VERSION_HEADERS): IResponse {
		return new class($status, $body, $headers) implements IResponse {
			/**
			 * @param array<string, string> $headers
			 */
			public function __construct(
				private int $status,
				private string $body,
				private array $headers,
			) {
			}

			public function getBody() {
				return $this->body;
			}

			public function getStatusCode(): int {
				return $this->status;
			}

			public function getHeader(string $key): string {
				return $this->headers[$key] ?? '';
			}

			public function getHeaders(): array {
				return [];
			}
		};
	}
}
