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
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppSettings;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The route through which a user chooses to include Paperless in every search of their
 * own, in the personal settings.
 */
final class PersonalSettingsControllerTest extends TestCase {
	use Accounts;
	use AppSettings;
	use UserPreferences;

	private const CHOSEN = 'dennis.' . AppConstants::APP_ID . '.always_search';
	private const ALERTS = 'dennis.' . AppConstants::APP_ID . '.new_document_alerts';

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
		self::assertSame(['everyDocument' => false, 'terms' => ['tag:unpaid', 'correspondent:"tax office"']], $this->answer($response));
		self::assertSame(['everyDocument' => false, 'terms' => ['tag:unpaid', 'correspondent:"tax office"']], $this->preferences[self::ALERTS]);
	}

	public function testEveryDocumentKeepsTheSearches(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;

		$response = $this->controller('dennis')->saveAlerts(true, ['tag:unpaid']);

		self::assertSame(['everyDocument' => true, 'terms' => ['tag:unpaid']], $this->answer($response));
		self::assertSame(['everyDocument' => true, 'terms' => ['tag:unpaid']], $this->preferences[self::ALERTS]);
	}

	public function testNothingAskedForRemovesThePreference(): void {
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = true;
		$this->preferences[self::ALERTS] = ['everyDocument' => true, 'terms' => []];

		$response = $this->controller('dennis')->saveAlerts();

		self::assertSame(['everyDocument' => false, 'terms' => []], $this->answer($response));
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

	private function controller(?string $userId): PersonalSettingsController {
		$configService = new ConfigService($this->appConfig(), $this->credentialsManager(), $this->accounts(), $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => '[' . vsprintf($text, $parameters) . ']');

		return new PersonalSettingsController(
			AppConstants::APP_ID,
			$this->createStub(IRequest::class),
			$configService,
			new AlwaysSearch($configService, $this->userConfig()),
			new SavedSearches($this->userConfig()),
			$this->session($userId),
			$l10n,
		);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function answer(JSONResponse $response): array {
		return (array)json_decode($response->render(), true, 512, JSON_THROW_ON_ERROR);
	}
}
