<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppSettings;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Whether a search includes Paperless without the switch Search connected services: the
 * trusted-service mode of the administrators for everybody, and the choice of a user for
 * their own searches while the administrators let users choose it.
 */
final class AlwaysSearchTest extends TestCase {
	use Accounts;
	use AppSettings;
	use UserPreferences;

	private const CHOSEN = 'dennis.' . AppConstants::APP_ID . '.always_search';

	/**
	 * @return array<string, array{bool, ?bool, bool, ?string, bool}>
	 */
	public static function cases(): array {
		return [
			// trusted mode, users may choose (null: never stored), dennis chose it, the user, included
			'nobody chose it' => [false, null, false, 'dennis', false],
			'the user chose it' => [false, null, true, 'dennis', true],
			'the user chose it, and users may choose' => [false, true, true, 'dennis', true],
			'the user chose it, but users may not choose' => [false, false, true, 'dennis', false],
			'another user chose it' => [false, null, true, 'alice', false],
			'trusted mode' => [true, null, false, 'dennis', true],
			'trusted mode, although users may not choose' => [true, false, false, 'dennis', true],
			'trusted mode without a user' => [true, null, false, null, true],
			'without a user, such as in occ' => [false, null, true, null, false],
		];
	}

	#[DataProvider('cases')]
	public function testTheSearchIncludesPaperlessForTheTrustedModeOrTheChoiceOfTheUser(bool $trusted, ?bool $usersMayChoose, bool $chosen, ?string $userId, bool $included): void {
		$this->settings[AppConstants::APP_ID . '.always_search'] = $trusted;
		if ($usersMayChoose !== null) {
			$this->settings[AppConstants::APP_ID . '.personal_always_search'] = $usersMayChoose;
		}
		if ($chosen) {
			$this->preferences[self::CHOSEN] = true;
		}

		self::assertSame($included, $this->alwaysSearch()->isOnFor($this->user($userId)));
	}

	/**
	 * What a user chose stays stored while the administrators don't let users choose it,
	 * and counts again once they do.
	 */
	public function testTheChoiceStaysWhileUsersMayNotChoose(): void {
		$this->preferences[self::CHOSEN] = true;
		$this->settings[AppConstants::APP_ID . '.personal_always_search'] = false;
		$alwaysSearch = $this->alwaysSearch();

		self::assertTrue($alwaysSearch->isChosenBy('dennis'));
		self::assertFalse($alwaysSearch->isOnFor($this->user('dennis')));

		$this->settings[AppConstants::APP_ID . '.personal_always_search'] = true;
		self::assertTrue($alwaysSearch->isOnFor($this->user('dennis')));
	}

	/**
	 * The preference is stored while it is chosen and deleted when it isn't, so that a
	 * user who never chose it and one who took it back look the same.
	 */
	public function testChoosingStoresThePreferenceAndTakingItBackDeletesIt(): void {
		$alwaysSearch = $this->alwaysSearch();
		self::assertFalse($alwaysSearch->isChosenBy('dennis'));

		$alwaysSearch->choose('dennis', true);
		self::assertSame([self::CHOSEN => true], $this->preferences);
		self::assertTrue($alwaysSearch->isChosenBy('dennis'));
		self::assertFalse($alwaysSearch->isChosenBy('alice'));

		$alwaysSearch->choose('dennis', false);
		self::assertSame([], $this->preferences);
		self::assertFalse($alwaysSearch->isChosenBy('dennis'));
	}

	public function testThePreferenceIsAValueOfTheAppThatIsNotLazy(): void {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->expects(self::once())
			->method('getValueBool')
			->with('dennis', AppConstants::APP_ID, 'always_search', false, false)
			->willReturn(true);
		$userConfig->expects(self::once())
			->method('setValueBool')
			->with('dennis', AppConstants::APP_ID, 'always_search', true, false)
			->willReturn(true);
		$userConfig->expects(self::once())
			->method('deleteUserConfig')
			->with('dennis', AppConstants::APP_ID, 'always_search');
		$alwaysSearch = new AlwaysSearch($this->configService(), $userConfig);

		self::assertTrue($alwaysSearch->isChosenBy('dennis'));
		$alwaysSearch->choose('dennis', true);
		$alwaysSearch->choose('dennis', false);
	}

	private function alwaysSearch(): AlwaysSearch {
		return new AlwaysSearch($this->configService(), $this->userConfig());
	}

	private function configService(): ConfigService {
		return new ConfigService($this->appConfig(), $this->credentialsManager(), $this->accounts(), $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));
	}

	private function user(?string $userId): ?IUser {
		if ($userId === null) {
			return null;
		}
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($userId);

		return $user;
	}
}
