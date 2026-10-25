<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCA\PaperlessUnifiedSearch\Settings\AdminSection;
use OCA\PaperlessUnifiedSearch\Settings\AdminSettings;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Shares;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Security\ICredentialsManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AdminSettingsTest extends TestCase {
	use Accounts;
	use Shares;

	public function testTheFormShowsThePublicConfigAndTheRoutesOfTheSettings(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => match ($app . '.' . $key) {
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			'paperless_sync.target_user' => 'sync',
			default => '',
		});
		$config->method('getValueBool')->willReturn(true);

		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::exactly(4))
			->method('linkToRoute')
			->willReturnCallback(static fn (string $routeName): string => match ($routeName) {
				AppConstants::APP_ID . '.settings.save' => '/apps/paperless_unified_search/settings#save',
				AppConstants::APP_ID . '.settings.reset' => '/apps/paperless_unified_search/settings#reset',
				AppConstants::APP_ID . '.settings.clearDiagnostics' => '/apps/paperless_unified_search/settings/diagnostics',
				AppConstants::APP_ID . '.settings.trySearch' => '/apps/paperless_unified_search/settings/try',
			});

		$form = $this->settings($config, $credentials, $urlGenerator)->getForm();

		self::assertSame(AppConstants::APP_ID, $form->getApp());
		self::assertSame('settings', $form->getTemplateName());
		self::assertSame(TemplateResponse::RENDER_AS_USER, $form->getRenderAs());

		$params = $form->getParams();
		self::assertSame('/apps/paperless_unified_search/settings#save', $params['saveUrl']);
		self::assertSame('/apps/paperless_unified_search/settings#reset', $params['resetUrl']);
		self::assertSame('/apps/paperless_unified_search/settings/diagnostics', $params['clearDiagnosticsUrl']);
		self::assertSame('/apps/paperless_unified_search/settings/try', $params['tryUrl']);
		self::assertInstanceOf(PublicConfig::class, $params['config']);
		self::assertSame([
			'url' => 'https://paperless.example.com',
			'tokenConfigured' => true,
			'alwaysSearch' => true,
			'archiveOwner' => '',
			'syncAccount' => 'sync',
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
			'allowLocal' => true,
			'paperlessVersion' => '',
			'paperlessApiVersion' => '',
			'personalAlwaysSearch' => true,
			'tokenWarnings' => [],
		], $params['config']->jsonSerialize());
		self::assertStringNotContainsString('TEST_VALUE', json_encode($params, JSON_THROW_ON_ERROR));
		self::assertNull($params['history']);
		self::assertSame([], $params['tokenWarnings']);
		// The shares of the account of Paperless Sync, the archive account in use.
		self::assertSame(['summary' => 'The archive account shares none of its files, so only it finds its documents.', 'warnings' => []], $params['shares']);
		self::assertSame(['sync'], $this->askedForSharesOf);
	}

	/**
	 * Below the archive account, the page says with whom it shares its files, and what is
	 * wrong with each share that allows changes.
	 */
	public function testTheFormSaysHowTheArchiveIsShared(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => $app . '.' . $key === AppConstants::APP_ID . '.archive_owner' ? 'sync' : '');
		$this->share(IShare::TYPE_GROUP, 'staff', '/Paperless', owner: 'sync');
		$this->share(IShare::TYPE_USER, 'sam', '/Paperless', Constants::PERMISSION_ALL, owner: 'sync');

		$params = $this->settings($config, $this->createStub(ICredentialsManager::class), $this->createStub(IURLGenerator::class))->getForm()->getParams();

		self::assertSame([
			'summary' => 'Shared: 1 user, 1 group.',
			'warnings' => ['/Paperless is shared with the user sam with write permission, so the search shows sam none of the documents in it. Share it read-only.'],
		], $params['shares']);
	}

	/**
	 * The page shows the warnings about the account of the token that the last test of the
	 * connection found, in the language of the administrator.
	 */
	public function testTheFormNamesTheWarningsAboutTheToken(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = '', bool $lazy = false): string => match ($app . '.' . $key) {
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.token_warnings' => $lazy ? 'superuser,hides_correspondents' : '',
			default => '',
		});
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$params = $this->settings($config, $credentials, $this->createStub(IURLGenerator::class))->getForm()->getParams();

		self::assertSame([
			'The API token belongs to a Superuser of Paperless: whoever gets hold of it can change and delete every document. Use the token of an account that may only view documents.',
			'The account of the API token may not view correspondents in Paperless, so results show without them. Give the account the permission View for Correspondents.',
		], $params['tokenWarnings']);
		self::assertInstanceOf(PublicConfig::class, $params['config']);
		self::assertSame(['superuser', 'hides_correspondents'], $params['config']->jsonSerialize()['tokenWarnings']);
	}

	/**
	 * After a move or a restore of Nextcloud with another secret in config.php, the stored
	 * token can't be decrypted. The page failed with HTTP 500 then, so that the token
	 * couldn't even be entered again (#99).
	 */
	public function testTheFormAsksForTheTokenAgainWhenTheStoredOneCannotBeDecrypted(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => match ($app . '.' . $key) {
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.archive_owner' => 'deleted',
			default => '',
		});
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('retrieve')->willThrowException(new RuntimeException('HMAC does not match.'));

		$params = $this->settings($config, $credentials, $this->createStub(IURLGenerator::class))->getForm()->getParams();

		self::assertInstanceOf(PublicConfig::class, $params['config']);
		self::assertSame('https://paperless.example.com', $params['config']->url);
		self::assertFalse($params['config']->tokenConfigured);
		self::assertTrue($params['config']->tokenUnreadable);
		self::assertTrue($params['config']->archiveOwnerMissing);
		// Without an archive account, there are no shares to show.
		self::assertNull($params['shares']);
		self::assertSame([], $this->askedForSharesOf);
	}

	public function testTheFormShowsTheHistoryOfTheProblemsWithFormattedTimes(): void {
		$history = DiagnosticsHistory::start(1791400000)
			->with(new SearchEvent(1791500000, SearchEvent::KIND_FAILED, SearchEvent::STEP_FILES, 'RuntimeException', 'Storage unavailable', 12))
			->recovered(1791500060)
			->with(new SearchEvent(1791500100, SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS, 'ConnectException', '', 3001));
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturn($history->jsonSerialize());

		$params = $this->settings($config, $this->createStub(ICredentialsManager::class), $this->createStub(IURLGenerator::class))
			->getForm()
			->getParams();

		self::assertSame([
			'since' => 'at 1791400000',
			'failed' => 1,
			'retried' => 1,
			'lastFailure' => 'at 1791500000',
			'recovery' => 'at 1791500060',
			'events' => [
				['time' => 'at 1791500100', 'kind' => SearchEvent::KIND_RETRIED, 'step' => SearchEvent::STEP_PAPERLESS, 'error' => 'ConnectException', 'durationMs' => 3001],
				['time' => 'at 1791500000', 'kind' => SearchEvent::KIND_FAILED, 'step' => SearchEvent::STEP_FILES, 'error' => 'RuntimeException: Storage unavailable', 'durationMs' => 12],
			],
		], $params['history']);
	}

	public function testAHistoryOfRetriesOnlyHasNoLastFailureAndNoRecovery(): void {
		$history = DiagnosticsHistory::start(1791400000)
			->with(new SearchEvent(1791500100, SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS, 'ConnectException', 'cURL error 7', 3001));
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturn($history->jsonSerialize());

		$params = $this->settings($config, $this->createStub(ICredentialsManager::class), $this->createStub(IURLGenerator::class))
			->getForm()
			->getParams();

		self::assertIsArray($params['history']);
		self::assertSame('', $params['history']['lastFailure']);
		self::assertSame('', $params['history']['recovery']);
	}

	public function testAHistoryWithoutEventsShowsNoProblem(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueArray')->willReturn(DiagnosticsHistory::start(1791400000)->jsonSerialize());

		$params = $this->settings($config, $this->createStub(ICredentialsManager::class), $this->createStub(IURLGenerator::class))
			->getForm()
			->getParams();

		self::assertNull($params['history']);
	}

	public function testTheFormHasASectionOfItsOwn(): void {
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->willReturnCallback(static fn (string $app, string $image): string => "/apps/{$app}/img/{$image}");
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')
			->willReturnCallback(static fn (string $text): string => $text === 'Paperless Unified Search' ? 'Paperless-Suche' : $text);
		$settings = $this->settings($this->createStub(IAppConfig::class), $this->createStub(ICredentialsManager::class), $urlGenerator);
		$section = new AdminSection($l10n, $urlGenerator);

		self::assertSame(AppConstants::APP_ID, $section->getID());
		self::assertSame($section->getID(), $settings->getSection());
		self::assertSame('Paperless-Suche', $section->getName());
		// The monochrome icon, which Nextcloud turns white on the active section and in
		// its dark theme; the coloured one turned pink there.
		self::assertSame('/apps/paperless_unified_search/img/app-dark.svg', $section->getIcon());
		self::assertSame(55, $section->getPriority());
		self::assertSame(50, $settings->getPriority());
	}

	private function settings(IAppConfig $config, ICredentialsManager $credentials, IURLGenerator $urlGenerator): AdminSettings {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$formatter = $this->createStub(IDateTimeFormatter::class);
		$formatter->method('formatDateTime')->willReturnCallback(static function (mixed $timestamp, string $date, string $time): string {
			self::assertIsInt($timestamp);
			self::assertSame(['short', 'medium'], [$date, $time]);

			return 'at ' . $timestamp;
		});

		return new AdminSettings(
			new ConfigService($config, $credentials, $this->accounts('sync'), $this->createStub(LoggerInterface::class)),
			$urlGenerator,
			new SearchDiagnostics($config, $this->createStub(ITimeFactory::class), $this->createStub(ICacheFactory::class)),
			$formatter,
			$this->archiveShares(),
			new SettingsMessages($l10n),
		);
	}
}
