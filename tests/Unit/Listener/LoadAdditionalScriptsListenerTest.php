<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Listener;

use OC;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Listener\LoadAdditionalScriptsListener;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppSettings;
use OCP\EventDispatcher\Event;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Util;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

final class LoadAdditionalScriptsListenerTest extends TestCase {
	use Accounts;
	use AppSettings;

	private bool $signedIn = true;

	protected function setUp(): void {
		$this->settings[AppConstants::APP_ID . '.paperless_url'] = 'https://paperless.example.test';
		$this->settings[AppConstants::APP_ID . '.archive_owner'] = 'archive';
		$this->storedToken = 'TEST_VALUE';
		// The language of the translations that come before the script.
		$factory = $this->createStub(IFactory::class);
		$factory->method('findLanguage')->willReturn('de');
		OC::$server = new class($factory) {
			public function __construct(
				private IFactory $factory,
			) {
			}

			public function get(string $serviceName): IFactory {
				TestCase::assertSame(IFactory::class, $serviceName);

				return $this->factory;
			}
		};
		self::initScripts([]);
		\OC_Util::$styles = [];
	}

	protected function tearDown(): void {
		self::initScripts([]);
		\OC_Util::$styles = [];
		OC::$server = null;
	}

	/** The event of Files, by its name, which Application registers the listener for. */
	public function testTheListenerIsForTheEventOfFilesThatLoadsTheScriptsOfApps(): void {
		self::assertSame('OCA\Files\Event\LoadAdditionalScriptsEvent', LoadAdditionalScriptsListener::EVENT);
	}

	/** The translations come first, as the script translates its action when Files shows it. */
	public function testAUserOfASetUpSearchGetsTheScriptAndItsStyles(): void {
		$this->listener()->handle(new Event());

		self::assertSame(['paperless_unified_search/l10n/de', 'paperless_unified_search/js/files'], self::initScripts());
		self::assertSame(['paperless_unified_search/files'], \OC_Util::$styles);
	}

	/** A page without a user, such as a public share, gets nothing. */
	public function testAPageWithoutAUserGetsNothing(): void {
		$this->signedIn = false;

		$this->listener()->handle(new Event());

		self::assertSame([], self::initScripts());
		self::assertSame([], \OC_Util::$styles);
	}

	/**
	 * Without Paperless, its token or an archive account the search finds nothing, so Files
	 * offers nothing either.
	 */
	public function testASearchThatCanFindNothingAddsNothing(): void {
		foreach (['paperless_url', 'archive_owner'] as $key) {
			$saved = $this->settings[AppConstants::APP_ID . '.' . $key];
			$this->settings[AppConstants::APP_ID . '.' . $key] = '';

			$this->listener()->handle(new Event());

			$this->settings[AppConstants::APP_ID . '.' . $key] = $saved;
		}
		$this->storedToken = null;
		$this->listener()->handle(new Event());

		self::assertSame([], self::initScripts());
		self::assertSame([], \OC_Util::$styles);
	}

	/** The listener as Nextcloud builds it for a page of Files, with the settings as they are now. */
	private function listener(): LoadAdditionalScriptsListener {
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn ? $this->createStub(IUser::class) : null);

		return new LoadAdditionalScriptsListener(
			new ConfigService($this->appConfig(), $this->credentialsManager(), $this->accounts('archive'), $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class)),
			$session,
		);
	}

	/**
	 * The init scripts of the page, which OCP\Util keeps in a private property; with $scripts,
	 * set to them first.
	 *
	 * @param list<string>|null $scripts
	 * @return list<string>
	 */
	private static function initScripts(?array $scripts = null): array {
		$property = new ReflectionProperty(Util::class, 'scriptsInit');
		if ($scripts !== null) {
			$property->setValue(null, $scripts);
		}
		/** @var list<string> */
		return $property->getValue();
	}
}
