<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Controller\PersonalSettingsController;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppSettings;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The routes through which a user chooses to include Paperless in every search of their
 * own, and what to be notified about, in the personal settings.
 */
final class PersonalSettingsControllerTest extends TestCase {
	use Accounts;
	use AppSettings;
	use UserPreferences;

	private const CHOSEN = 'dennis.' . AppConstants::APP_ID . '.always_search';
	private const ALERTS = 'dennis.' . AppConstants::APP_ID . '.new_document_alerts';
	private const INVALID_DATE = '{"query":["Invalid date value \'[2026\' for field \'created\'."]}';
	/** The headers of an answer of Paperless 3.3.0, as it names its version. */
	private const PAPERLESS_3 = ['X-Version' => '3.3.0', 'X-Api-Version' => '10'];

	/** @var list<array<string, int|string>> the queries that reached Paperless */
	private array $queries = [];

	/** @var array<string, IResponse> what Paperless answers to a term, other than no documents */
	private array $answers = [];

	/** An error instead of every answer of Paperless. */
	private ?Throwable $unanswered = null;

	/** Whether the settings name a Paperless and its token. */
	private bool $connected = true;

	public function testAUserChoosesAndTakesBackTheSearchOfPaperlessInEverySearch(): void {
		$controller = $this->controller('dennis');

		$response = $controller->save(true);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['alwaysSearch' => true], $this->answer($response));
		self::assertSame([self::CHOSEN => true], $this->preferences);

		$response = $controller->save(false);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['alwaysSearch' => false], $this->answer($response));
		self::assertSame([], $this->preferences);
	}

	public function testASaveWithoutTheChoiceTakesItBack(): void {
		$this->preferences[self::CHOSEN] = true;

		self::assertSame(['alwaysSearch' => false], $this->answer($this->controller('dennis')->save()));
		self::assertSame([], $this->preferences);
	}

	/**
	 * While the administrators don't let users choose it, a choice is refused, in the
	 * language of the user, and the stored one stays as it is.
	 */
	public function testTheChoiceIsRefusedWhileUsersMayNotChoose(): void {
		$this->settings[AppConstants::APP_ID . '.personal_always_search'] = false;
		$this->preferences[self::CHOSEN] = true;

		$response = $this->controller('dennis')->save(false);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([
			'message' => '[Your administrator does not let users include Paperless in all their searches.]',
			'code' => 'not_allowed',
		], $this->answer($response));
		self::assertSame([self::CHOSEN => true], $this->preferences);
	}

	/**
	 * Nextcloud lets no request without a login reach the route; should one come, it
	 * stores nothing.
	 */
	public function testWithoutAUserNothingIsStored(): void {
		$response = $this->controller(null)->save(true);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame([
			'message' => '[Your session has expired. Reload the page and try again.]',
			'code' => 'not_signed_in',
		], $this->answer($response));
		self::assertSame([], $this->preferences);
	}

	public function testTheSearchesAreStoredAndAnsweredAsTheServerKeepsThem(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;

		$response = $this->controller('dennis')->saveAlerts(false, [' tag:unpaid ', '', 'tag:unpaid', 'correspondent:"tax office"']);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['everyDocument' => false, 'terms' => ['tag:unpaid', 'correspondent:"tax office"'], 'checked' => true], $this->answer($response));
		self::assertSame(['everyDocument' => false, 'terms' => ['tag:unpaid', 'correspondent:"tax office"']], $this->preferences[self::ALERTS]);
	}

	public function testEveryDocumentKeepsTheSearches(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;

		$response = $this->controller('dennis')->saveAlerts(true, ['tag:unpaid']);

		self::assertSame(['everyDocument' => true, 'terms' => ['tag:unpaid'], 'checked' => true], $this->answer($response));
		self::assertSame(['everyDocument' => true, 'terms' => ['tag:unpaid']], $this->preferences[self::ALERTS]);
	}

	public function testNothingAskedForRemovesThePreference(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->preferences[self::ALERTS] = ['everyDocument' => true, 'terms' => []];

		$response = $this->controller('dennis')->saveAlerts();

		self::assertSame(['everyDocument' => false, 'terms' => [], 'checked' => true], $this->answer($response));
		self::assertSame([], $this->preferences);
	}

	public function testExactlyTheMostSearchesOfTheirLongestLengthAreStored(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$terms = array_map(static fn (int $number): string => $number . str_repeat('ä', SavedSearches::LONGEST_TERM - strlen((string)$number)), range(1, SavedSearches::MOST_TERMS));

		$response = $this->controller('dennis')->saveAlerts(false, $terms);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($terms, $this->preferences[self::ALERTS]['terms']);
	}

	/**
	 * More searches than a user may save are refused, in the language of the user, and what
	 * is stored stays.
	 */
	public function testMoreSearchesThanAllowedAreRefused(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->preferences[self::ALERTS] = ['everyDocument' => true, 'terms' => []];

		$response = $this->controller('dennis')->saveAlerts(false, array_map('strval', range(1, SavedSearches::MOST_TERMS + 1)));

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => '[Save at most 10 searches.]', 'code' => 'too_many_searches'], $this->answer($response));
		self::assertSame(['everyDocument' => true, 'terms' => []], $this->preferences[self::ALERTS]);
	}

	public function testALongerSearchIsRefused(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;

		$response = $this->controller('dennis')->saveAlerts(false, ['tax', str_repeat('ä', SavedSearches::LONGEST_TERM + 1)]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['message' => '[A search can be at most 500 characters long.]', 'code' => 'search_too_long'], $this->answer($response));
		self::assertSame([], $this->preferences);
	}

	/**
	 * While the administrators don't let users ask for it, a choice is refused, and the
	 * stored one stays as it is.
	 */
	public function testTheSearchesAreRefusedWhileUsersMayNotAsk(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = false;
		$this->preferences[self::ALERTS] = ['everyDocument' => true, 'terms' => []];

		$response = $this->controller('dennis')->saveAlerts(false, ['tax']);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => '[Your administrator does not let users ask for notifications about new documents.]', 'code' => 'not_allowed'], $this->answer($response));
		self::assertSame(['everyDocument' => true, 'terms' => []], $this->preferences[self::ALERTS]);
	}

	/**
	 * A new search that Paperless can't read is refused with the number of its line, as the
	 * field sent it, and what is stored stays: it would wait for documents that it never
	 * finds. The searches before it went to Paperless, those after it didn't.
	 */
	public function testANewSearchThatPaperlessCantReadIsRefusedWithItsLine(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->preferences[self::ALERTS] = ['everyDocument' => false, 'terms' => ['tax']];
		$this->answers['created:[2026 to'] = $this->paperlessAnswer(400, self::INVALID_DATE);

		$response = $this->controller('dennis')->saveAlerts(false, ['tag:unpaid', '', ' created:[2026   to ', 'tax', 'invoice']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'message' => "[Paperless can't read this term in its advanced syntax, such as a range of dates that isn't complete like created:[2026 to. Correct the term and try again.]",
			'code' => 'query_rejected',
			'term' => 'created:[2026 to',
			'line' => 3,
		], $this->answer($response));
		self::assertSame(['everyDocument' => false, 'terms' => ['tax']], $this->preferences[self::ALERTS]);
		self::assertSame(['tag:unpaid', 'created:[2026 to'], $this->checkedTerms());
	}

	/**
	 * Paperless 3 would read the search in its simple mode, as only its words, which finds
	 * other documents than the user meant: the save is refused all the same, and the
	 * simple mode gets no request.
	 */
	public function testASearchThatPaperless3ReadsOnlyInItsSimpleModeIsRefused(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->answers['tax created:[2026 to'] = $this->paperlessAnswer(400, self::INVALID_DATE, self::PAPERLESS_3);

		$response = $this->controller('dennis')->saveAlerts(false, ['tax created:[2026 to']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['query_rejected', 1], [$this->answer($response)['code'], $this->answer($response)['line']]);
		self::assertSame([], $this->preferences);
		self::assertSame([['query' => 'tax created:[2026 to', 'page' => 1, 'page_size' => 1, 'fields' => 'id']], $this->queries);
	}

	/**
	 * Only a search that is new among those in use goes to Paperless: those saved before
	 * stay as they are, so that a save doesn't ask Paperless again for each one.
	 */
	public function testOnlyTheNewSearchesGoToPaperless(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->preferences[self::ALERTS] = ['everyDocument' => false, 'terms' => ['tag:unpaid']];

		$response = $this->controller('dennis')->saveAlerts(false, ['tag:unpaid', 'tax']);

		self::assertSame(['everyDocument' => false, 'terms' => ['tag:unpaid', 'tax'], 'checked' => true], $this->answer($response));
		self::assertSame(['tax'], $this->checkedTerms());
	}

	/**
	 * Every document leaves the searches unused and the field disabled, so that the user
	 * couldn't correct one: they are kept unchecked, and checked once they count.
	 */
	public function testTheSearchesThatEveryDocumentKeepsAreCheckedOnceTheyCount(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->answers['tag:unpaid'] = $this->paperlessAnswer(400, self::INVALID_DATE);

		$response = $this->controller('dennis')->saveAlerts(true, ['tag:unpaid']);

		self::assertSame(['everyDocument' => true, 'terms' => ['tag:unpaid'], 'checked' => true], $this->answer($response));
		self::assertSame([], $this->queries);

		$response = $this->controller('dennis')->saveAlerts(false, ['tag:unpaid']);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['tag:unpaid'], $this->checkedTerms());
		self::assertSame(['everyDocument' => true, 'terms' => ['tag:unpaid']], $this->preferences[self::ALERTS]);
	}

	/**
	 * Whether Paperless has the document of like:412 that its account may view is no matter
	 * of the syntax, and an answer would tell a user about a document that they may not be
	 * allowed to open: such a search is saved as it is.
	 */
	public function testASearchForDocumentsSimilarToOneThatPaperlessRefusesIsSaved(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->answers['like:412'] = $this->paperlessAnswer(403, 'Invalid more_like_id');

		$response = $this->controller('dennis')->saveAlerts(false, ['like:412', 'tax']);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['everyDocument' => false, 'terms' => ['like:412', 'tax'], 'checked' => true], $this->answer($response));
		self::assertSame(['like:412', 'tax'], $this->checkedTerms());
	}

	/**
	 * When Paperless doesn't answer, the searches are saved, the answer says that they
	 * could not be checked, and the further ones don't wait for it again.
	 */
	public function testWithoutAnAnswerOfPaperlessTheSearchesAreSavedUnchecked(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->unanswered = new RuntimeException('Connection timed out');

		$response = $this->controller('dennis')->saveAlerts(false, ['tax', 'tag:unpaid']);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['everyDocument' => false, 'terms' => ['tax', 'tag:unpaid'], 'checked' => false], $this->answer($response));
		self::assertSame(['everyDocument' => false, 'terms' => ['tax', 'tag:unpaid']], $this->preferences[self::ALERTS]);
		self::assertSame(['tax'], $this->checkedTerms());
	}

	public function testWithoutPaperlessTheSearchesAreSavedUnchecked(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->connected = false;

		$response = $this->controller('dennis')->saveAlerts(false, ['tax']);

		self::assertSame(['everyDocument' => false, 'terms' => ['tax'], 'checked' => false], $this->answer($response));
		self::assertSame([], $this->queries);
	}

	public function testWithoutAUserNoSearchIsStored(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;

		$response = $this->controller(null)->saveAlerts(true);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame([
			'message' => '[Your session has expired. Reload the page and try again.]',
			'code' => 'not_signed_in',
		], $this->answer($response));
		self::assertSame([], $this->preferences);
	}

	/**
	 * The controller, in a Nextcloud connected to a Paperless that answers each search as
	 * $this->answers says, with no documents by default, or none at all with
	 * $this->unanswered, and that records the queries that reach it.
	 */
	private function controller(?string $userId): PersonalSettingsController {
		if ($this->connected) {
			$this->settings[AppConstants::APP_ID . '.paperless_url'] = 'https://paperless.example.com';
			$this->storedToken = 'TEST_VALUE';
		}
		$configService = new ConfigService($this->appConfig(), $this->credentialsManager(), $this->accounts(), $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => '[' . vsprintf($text, $parameters) . ']');

		$client = $this->createStub(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
			/** @var array<string, int|string> $query */
			$query = $options['query'];
			$this->queries[] = $query;
			if ($this->unanswered !== null) {
				throw $this->unanswered;
			}

			return $this->answers[(string)($query['query'] ?? 'like:' . ($query['more_like_id'] ?? ''))] ?? $this->paperlessAnswer(200, '{"count":0,"next":null,"results":[]}');
		});
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$clock = $this->createStub(ITimeFactory::class);
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		return new PersonalSettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			new AlwaysSearch($configService, $this->userConfig()),
			new SavedSearches($this->userConfig()),
			new PaperlessApiService($configService, $clientService, new SearchDiagnostics($this->appConfig(), $clock, $this->createStub(ICacheFactory::class)), $clock, $appManager),
			$this->session($userId),
			$l10n,
		);
	}

	/**
	 * @param array<string, string> $headers by name
	 */
	private function paperlessAnswer(int $status, string $body, array $headers = []): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');

		return $response;
	}

	/**
	 * The terms that reached Paperless, in their order.
	 *
	 * @return list<string>
	 */
	private function checkedTerms(): array {
		return array_map(
			static fn (array $query): string => (string)($query['query'] ?? 'like:' . ($query['more_like_id'] ?? '')),
			$this->queries,
		);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function answer(JSONResponse $response): array {
		return (array)json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
	}
}
