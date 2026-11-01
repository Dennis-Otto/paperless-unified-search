<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Settings;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Controller\PersonalSettingsController;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCA\PaperlessUnifiedSearch\Settings\PersonalSection;
use OCA\PaperlessUnifiedSearch\Settings\PersonalSettings;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppSettings;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The section Paperless of the personal settings, with the choice of a user to include
 * Paperless in every search of their own.
 */
final class PersonalSettingsTest extends TestCase {
	use Accounts;
	use AppSettings;
	use UserPreferences;

	protected function setUp(): void {
		$this->settings = [
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.archive_owner' => 'archive',
		];
		$this->storedToken = 'TEST_VALUE';
	}

	/**
	 * @return array<string, array{bool, bool}>
	 */
	public static function choices(): array {
		return [
			// chosen, trusted mode
			'not chosen' => [false, false],
			'chosen' => [true, false],
			'trusted mode' => [false, true],
		];
	}

	#[DataProvider('choices')]
	public function testTheFormShowsTheChoiceOfTheUserAndWhetherTheAdministratorsTrustPaperless(bool $chosen, bool $trusted): void {
		$this->preferences['dennis.' . AppConstants::APP_ID . '.always_search'] = $chosen;
		$this->settings[AppConstants::APP_ID . '.always_search'] = $trusted;
		$this->settings[AppConstants::APP_ID . '.personal_always_search'] = !$trusted;
		$this->settings[AppConstants::APP_ID . '.new_document_alerts'] = $trusted;
		$this->preferences['dennis.' . AppConstants::APP_ID . '.new_document_alerts'] = ['everyDocument' => $chosen, 'terms' => ['tag:unpaid', 'tax']];
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(static fn (string $route): string => match ($route) {
			AppConstants::APP_ID . '.personalSettings.save' => '/apps/paperless_unified_search/personal',
			AppConstants::APP_ID . '.personalSettings.saveAlerts' => '/apps/paperless_unified_search/personal/alerts',
		});

		$form = $this->settings('dennis', $urlGenerator)->getForm();

		self::assertSame(AppConstants::APP_ID, $form->getApp());
		self::assertSame('personal', $form->getTemplateName());
		self::assertSame([
			'alwaysSearchAllowed' => !$trusted,
			'alwaysSearch' => $chosen,
			'trusted' => $trusted,
			'saveUrl' => '/apps/paperless_unified_search/personal',
			'alertsAllowed' => $trusted,
			'everyDocument' => $chosen,
			'terms' => "tag:unpaid\ntax",
			'mostTerms' => 10,
			'alertsUrl' => '/apps/paperless_unified_search/personal/alerts',
		], $form->getParams());
	}

	/**
	 * Nextcloud names a route of an attribute after the controller without its suffix and
	 * the method, whatever their case, and links none for a name that it doesn't know:
	 * the checkbox would then post to the page itself.
	 */
	public function testTheFormLinksTheRouteOfTheController(): void {
		$linked = [];
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(static function (string $name) use (&$linked): string {
			$linked[] = $name;

			return '/apps/paperless_unified_search/personal';
		});

		$this->settings('dennis', $urlGenerator)->getForm();

		$controller = (new ReflectionClass(PersonalSettingsController::class))->getShortName();
		$prefix = AppConstants::APP_ID . '.' . substr($controller, 0, -strlen('Controller')) . '.';
		self::assertSame(
			[strtolower($prefix . 'save'), strtolower($prefix . 'saveAlerts')],
			array_map('strtolower', $linked),
		);
	}

	/**
	 * Nextcloud shows the personal settings only to a user who signed in; without one, the
	 * form shows nothing chosen.
	 */
	public function testWithoutAUserNothingIsChosen(): void {
		$params = $this->settings(null)->getForm()->getParams();

		self::assertFalse($params['alwaysSearch']);
		self::assertFalse($params['everyDocument']);
		self::assertSame('', $params['terms']);
	}

	/**
	 * @return array<string, array{array<string, mixed>, ?string, bool}>
	 */
	public static function sections(): array {
		return [
			// settings that differ from a connected app, the token, whether the section shows
			'connected, with an archive account' => [[], 'TEST_VALUE', true],
			'users may choose' => [['personal_always_search' => true], 'TEST_VALUE', true],
			'users may not choose' => [['personal_always_search' => false], 'TEST_VALUE', false],
			'users may only ask for notifications' => [['personal_always_search' => false, 'new_document_alerts' => true], 'TEST_VALUE', true],
			'users may only choose the search' => [['personal_always_search' => true, 'new_document_alerts' => false], 'TEST_VALUE', true],
			'users may choose neither' => [['personal_always_search' => false, 'new_document_alerts' => false], 'TEST_VALUE', false],
			'notifications without an archive account' => [['new_document_alerts' => true, 'archive_owner' => ''], 'TEST_VALUE', false],
			'without a URL' => [['paperless_url' => ''], 'TEST_VALUE', false],
			'without a token' => [[], null, false],
			'without an archive account' => [['archive_owner' => ''], 'TEST_VALUE', false],
			'with an archive account that does not exist' => [['archive_owner' => 'gone'], 'TEST_VALUE', false],
		];
	}

	/**
	 * The setting shows while the administrators let users choose and the search shows
	 * Paperless documents, as the dashboard widget does. Without it, Nextcloud hides the
	 * section, which holds nothing else.
	 *
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider('sections')]
	public function testTheSettingShowsOnlyWhileUsersMayChooseAndTheSearchShowsDocuments(array $settings, ?string $token, bool $shown): void {
		foreach ($settings as $key => $value) {
			$this->settings[AppConstants::APP_ID . '.' . $key] = $value;
		}
		$this->storedToken = $token;

		self::assertSame($shown ? AppConstants::APP_ID : null, $this->settings('dennis')->getSection());
	}

	public function testTheSettingComesFirstInTheSection(): void {
		self::assertSame(50, $this->settings('dennis')->getPriority());
	}

	public function testTheSectionIsNamedPaperlessWithTheIconOfTheApp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $text . ']');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::once())
			->method('imagePath')
			->with(AppConstants::APP_ID, 'app-dark.svg')
			->willReturn('/apps/paperless_unified_search/img/app-dark.svg');

		$section = new PersonalSection($l10n, $urlGenerator);

		self::assertSame(AppConstants::APP_ID, $section->getID());
		self::assertSame('[Paperless]', $section->getName());
		self::assertSame(55, $section->getPriority());
		self::assertSame('/apps/paperless_unified_search/img/app-dark.svg', $section->getIcon());
	}

	private function settings(?string $userId, ?IURLGenerator $urlGenerator = null): PersonalSettings {
		$configService = new ConfigService($this->appConfig(), $this->credentialsManager(), $this->accounts('archive'), $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));

		return new PersonalSettings(
			$configService,
			new AlwaysSearch($configService, $this->userConfig()),
			new SavedSearches($this->userConfig()),
			$this->session($userId),
			$urlGenerator ?? $this->createStub(IURLGenerator::class),
		);
	}
}
