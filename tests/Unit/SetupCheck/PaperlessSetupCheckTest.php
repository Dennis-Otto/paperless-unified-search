<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\SetupCheck;

use OC;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\SetupCheck\PaperlessSetupCheck;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Shares;
use OCP\Constants;
use OCP\IDateTimeFormatter;
use OCP\IURLGenerator;
use OCP\RichObjectStrings\IValidator;
use OCP\SetupCheck\SetupResult;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The state of the search in the overview of the administration settings, which an
 * administrator sees without opening the settings of the app.
 */
final class PaperlessSetupCheckTest extends TestCase {
	use Accounts;
	use AppServices;
	use Shares;

	private const SECTION = 'https://cloud.example.com/index.php/settings/admin/paperless_unified_search';

	/** @var list<array{string, array<string, array<string, string>>}> what Nextcloud's validator of rich texts checked */
	private array $validated = [];

	protected function setUp(): void {
		$validator = $this->createStub(IValidator::class);
		$validator->method('validate')->willReturnCallback(function (string $subject, array $parameters): void {
			$this->validated[] = [$subject, $parameters];
		});
		OC::$server = new class($validator) {
			public function __construct(
				private IValidator $validator,
			) {
			}

			public function get(string $serviceName): IValidator {
				TestCase::assertSame(IValidator::class, $serviceName);

				return $this->validator;
			}
		};
	}

	protected function tearDown(): void {
		OC::$server = null;
	}

	public function testIsACheckOfTheSystem(): void {
		$check = $this->check();

		self::assertSame('system', $check->getCategory());
		self::assertSame('T:Paperless Unified Search', $check->getName());
	}

	/**
	 * Without a connection, the app isn't in use, which is no problem: Nextcloud shows a
	 * check that passes only in occ setupchecks.
	 */
	public function testPassesWithoutAConnection(): void {
		$result = $this->check()->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
		self::assertSame('T:Paperless Unified Search is not connected to Paperless.', $result->getDescription());
	}

	public function testNamesThePaperlessThatItIsConnectedTo(): void {
		$this->connected();
		$this->settings[AppConstants::APP_ID . '.paperless_version'] = '3.3.0';
		$this->settings[AppConstants::APP_ID . '.paperless_api_version'] = '10';

		$result = $this->check()->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
		self::assertSame('T:Connected to Paperless 3.3.0 (API 10) at https://paperless.example.com, with the archive account archive. T:The archive account shares none of its files, so only it finds its documents.', $result->getDescription());
		self::assertNull($result->getDescriptionParameters());
	}

	public function testNamesThePaperlessWithoutAVersion(): void {
		$this->connected('Archive');

		$result = $this->check()->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
		self::assertSame('T:Connected to Paperless at https://paperless.example.com, with the archive account archive. T:The archive account shares none of its files, so only it finds its documents.', $result->getDescription());
	}

	/**
	 * Shares that allow no changes are what the archive needs: the check says how many.
	 */
	public function testSaysHowTheArchiveIsSharedReadOnly(): void {
		$this->connected();
		$this->share(IShare::TYPE_GROUP, 'staff', '/Paperless');
		$this->share(IShare::TYPE_USER, 'jamie', '/Paperless/2026', Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);

		$result = $this->check()->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
		self::assertStringEndsWith('archive. T:Shared read-only: T:1 user, T:1 group.', $result->getDescription());
	}

	/**
	 * A search that worked again after a failure ends it; retries alone are no failure.
	 */
	public function testPassesOnceSearchesWorkAgain(): void {
		$this->connected();
		$this->history(DiagnosticsHistory::start(1791300000)->with($this->event(SearchEvent::KIND_FAILED, SearchEvent::STEP_PAPERLESS))->recovered(1791450000));

		self::assertSame(SetupResult::SUCCESS, $this->check()->run()->getSeverity());

		$this->history(DiagnosticsHistory::start(1791300000)->with($this->event(SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS)));

		self::assertSame(SetupResult::SUCCESS, $this->check()->run()->getSeverity());
	}

	/**
	 * @return array<string, array{callable(self): void, string}>
	 */
	public static function problems(): array {
		return [
			'a token that cannot be decrypted' => [
				static function (self $test): void {
					$test->connected();
					$test->storedToken = false;
				},
				'T:The stored Paperless API token can\'t be decrypted, as after a move or a restore of Nextcloud with another secret in config.php, so the search shows no Paperless documents. Enter the token again in {section}.',
			],
			'no archive account' => [
				static function (self $test): void {
					$test->connected('');
				},
				'T:No archive account is set, so the search shows no Paperless documents. Enter the account that owns the synchronized files in {section}.',
			],
			'an archive account that does not exist' => [
				static function (self $test): void {
					$test->connected('gone');
				},
				'T:The archive account gone does not exist, so the search shows no Paperless documents. Enter the account that owns the synchronized files in {section}.',
			],
			'an account of Paperless Sync that does not exist' => [
				static function (self $test): void {
					$test->connected('');
					$test->settings['paperless_sync.target_user'] = 'removed';
				},
				'T:The archive account removed does not exist, so the search shows no Paperless documents. Enter the account that owns the synchronized files in {section}.',
			],
			'a failure in Paperless' => [
				static function (self $test): void {
					$test->connected();
					$test->history(DiagnosticsHistory::start(1791300000)->with($test->event(SearchEvent::KIND_FAILED, SearchEvent::STEP_PAPERLESS)));
				},
				'T:A search failed on formatted 1791400000 while asking Paperless, and none has worked since. Search problems in {section} shows what failed.',
			],
			'shares that allow changes' => [
				static function (self $test): void {
					$test->connected();
					$test->share(IShare::TYPE_USER, 'jamie', '/Paperless');
					$test->share(IShare::TYPE_USER, 'sam', '/Paperless', Constants::PERMISSION_ALL);
					$test->share(IShare::TYPE_GROUP, 'staff', '/Paperless', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
					$test->share(IShare::TYPE_LINK, 'token', '/Inbox', Constants::PERMISSION_CREATE);
				},
				'T:The archive account archive shares /Inbox, /Paperless with write permission, so whoever may write there can give a file the marker of any document. Share the archive read-only: {section} lists each share.',
			],
			'a failure of the files, with retries since' => [
				static function (self $test): void {
					$test->connected();
					$test->history(DiagnosticsHistory::start(1791300000)
						->with($test->event(SearchEvent::KIND_FAILED, SearchEvent::STEP_FILES))
						->with($test->event(SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS)));
				},
				'T:A search failed on formatted 1791400000 while looking for the files of the documents in Nextcloud, and none has worked since. Search problems in {section} shows what failed.',
			],
		];
	}

	/**
	 * A problem that keeps the search from showing Paperless documents is a warning, with
	 * a link to the settings of the app where it is corrected.
	 *
	 * @param callable(self): void $arrange
	 */
	#[DataProvider('problems')]
	public function testWarnsOfAProblemWithALinkToTheSettings(callable $arrange, string $description): void {
		$arrange($this);

		$result = $this->check()->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertSame($description, $result->getDescription());
		$parameters = [
			'section' => [
				'type' => 'highlight',
				'id' => AppConstants::APP_ID,
				'name' => 'T:the settings of Paperless Unified Search',
				'link' => self::SECTION,
			],
		];
		self::assertSame($parameters, $result->getDescriptionParameters());
		// Nextcloud refuses a rich text whose placeholders lack their objects.
		self::assertSame([[$description, $parameters]], $this->validated);
	}

	/**
	 * The check, which reads what the app stored and sends no request to Paperless, so that
	 * the overview opens as fast as without the app: it has no service that would.
	 */
	private function check(): PaperlessSetupCheck {
		$l10n = $this->writtenTexts('T:');
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route, array $arguments): string {
			TestCase::assertSame(['settings.AdminSettings.index', ['section' => AppConstants::APP_ID]], [$route, $arguments]);

			return self::SECTION;
		});
		$dateTimeFormatter = $this->createStub(IDateTimeFormatter::class);
		$dateTimeFormatter->method('formatDateTime')->willReturnCallback(static fn (int $time): string => 'formatted ' . $time);

		return new PaperlessSetupCheck(
			$this->configService($this->accounts('archive')),
			$this->diagnostics(),
			$l10n,
			$urlGenerator,
			$dateTimeFormatter,
			$this->archiveShares($l10n),
		);
	}

	private function history(DiagnosticsHistory $history): void {
		$this->settings[AppConstants::APP_ID . '.diagnostics'] = $history->jsonSerialize();
	}

	private function event(string $kind, string $step): SearchEvent {
		return new SearchEvent(1791400000, $kind, $step, 'PaperlessRequestException', 'Paperless returned HTTP 500.', 120);
	}
}
