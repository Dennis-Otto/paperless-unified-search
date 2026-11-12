<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\DocumentOwners;
use OCA\PaperlessUnifiedSearch\Service\UserTags;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryCache;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The settings of Paperless Sync from which the search decides who sees which document, as
 * a release of Paperless Sync stores them (tests/Unit/fixtures/paperless-sync-settings.json):
 * the same key, type and lazy flag, and each value as its settings page saves what the
 * administrator typed. The demo saves the same forms with that release and compares what
 * it stored with the fixture (bash demo/seed.sh --check-access), so a change of a key, a
 * format or a flag in Paperless Sync fails one of the two, never neither (#405).
 */
final class PaperlessSyncSettingsTest extends TestCase {
	use Accounts;
	use AppServices;

	private const FIXTURE = __DIR__ . '/../fixtures/paperless-sync-settings.json';

	/** The users of Paperless of the demo, by their IDs, who own the documents 101 and 102. */
	private const PAPERLESS_USERS = [5 => 'robin', 6 => 'sam.paperless'];

	/** The users of Nextcloud of the demo; Paperless Sync refuses a line of a user that doesn't exist. */
	private const ACCOUNTS = ['paperless', 'jamie', 'robin', 'sam'];

	/**
	 * Each case of the fixture: the tags that a user may see, the archives of each owner,
	 * and the single archive again.
	 *
	 * @return array<string, array{string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::fixture()['cases']) as $case) {
			$cases[$case] = [$case];
		}

		return $cases;
	}

	/**
	 * Every setting that the search reads of Paperless Sync is in the fixture, under the key
	 * that the search reads, and the search finds each with the type and the lazy flag that
	 * Paperless Sync stores it with: a read of another type fails, and a read that isn't lazy
	 * finds no lazy value, as in Nextcloud.
	 */
	#[DataProvider('cases')]
	public function testTheSearchReadsEverySettingAsPaperlessSyncStoresIt(string $case): void {
		$stored = self::fixture()['cases'][$case]['stored'];
		$settings = $this->configService($case);

		self::assertSame(
			['target_user', 'paperless_url', 'paperless_local_network', 'ownership', 'user_map', 'user_group', 'user_tags'],
			array_keys($stored),
		);
		self::assertSame($stored['target_user']['value'], $settings->getSyncAccount());
		self::assertSame($stored['paperless_url']['value'], $settings->getSyncUrl());
		self::assertSame($stored['paperless_local_network']['value'], $settings->isSyncLocalNetworkAllowed());
		self::assertSame($stored['user_map']['value'], $settings->getSyncUserMap());
		self::assertSame($stored['user_group']['value'], $settings->getSyncUserGroup());
		self::assertSame($stored['user_tags']['value'], $settings->getSyncUserTags());
		self::assertTrue($settings->isSyncArchive());
		self::assertSame(self::fixture()['cases'][$case]['search']['archives_of_each_owner'], $settings->hasArchivesOfEachOwner());
	}

	/**
	 * The search limits a user to the tags of their line, as Paperless Sync saved it from
	 * what the administrator typed, with the user ID as Nextcloud spells it and each tag
	 * once, and every other user to none.
	 */
	#[DataProvider('cases')]
	public function testTheSearchLimitsEachUserToTheTagsThatPaperlessSyncSaved(string $case): void {
		$expected = self::fixture()['cases'][$case]['search']['user_tags'];
		$settings = $this->configService($case);
		$tags = new UserTags($settings, $this->paperlessApi($settings, $this->paperless()), $this->cacheFactory(), $this->createStub(LoggerInterface::class));

		self::assertSame($expected, $tags->lines());
		foreach (self::ACCOUNTS as $user) {
			self::assertSame(isset($expected[$user]), $tags->restricts($user), $user);
			self::assertSame($expected[$user] ?? [], $tags->namesOf($user), $user);
		}
		self::assertSame([], $this->requests);
	}

	/**
	 * The files of the user of Nextcloud whom Paperless Sync gives the documents of an owner
	 * stand for them in the search: by the table that it saved, whatever the case of the
	 * name of the user of Paperless, or else by the same ID. With a single archive, no
	 * owner's files stand for a document.
	 */
	#[DataProvider('cases')]
	public function testTheFilesOfTheUserOfEachOwnerStandForTheirDocuments(string $case): void {
		$owners = self::fixture()['cases'][$case]['search']['owners'];
		$users = [];
		$documents = [];
		$expected = [];
		foreach (self::PAPERLESS_USERS as $id => $name) {
			$users[] = ['id' => $id, 'username' => $name];
			$documents[] = ['id' => 96 + $id, 'owner' => $id];
			if (isset($owners[$name])) {
				$expected[96 + $id] = $owners[$name];
			}
		}
		$settings = $this->configService($case);
		$paperlessApi = $this->paperlessApi($settings, $this->paperless($this->users($users)));
		$logger = $this->createStub(LoggerInterface::class);
		$documentOwners = new DocumentOwners(
			$paperlessApi,
			$settings,
			$this->accounts(...self::ACCOUNTS),
			$this->createStub(IGroupManager::class),
			$this->cacheFactory(),
			$logger,
			new UserTags($settings, $paperlessApi, $this->cacheFactory(), $logger),
		);

		self::assertSame($expected, $documentOwners->of($documents));
	}

	/**
	 * The settings of a case of the fixture as Paperless Sync stored them, beside those of
	 * the search, whose archive account is the target user of Paperless Sync, as in the demo.
	 */
	private function configService(string $case): ConfigService {
		$config = new MemoryAppConfig([
			AppConstants::APP_ID . '.paperless_url' => 'https://paperless.example.com',
			AppConstants::APP_ID . '.archive_owner' => 'paperless',
		]);
		foreach (self::fixture()['cases'][$case]['stored'] as $key => $setting) {
			$config->seed('paperless_sync', $key, $setting['value'], $setting['lazy']);
		}
		$this->storedToken = 'TEST_VALUE';

		return new ConfigService($config, $this->credentialsManager(), $this->accounts(...self::ACCOUNTS), $this->createStub(LoggerInterface::class), $this->createStub(IConfig::class));
	}

	private function cacheFactory(): ICacheFactory {
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new MemoryCache(static fn (): int => 0));

		return $cacheFactory;
	}

	/**
	 * The answer of Paperless with these users, as /api/users/ lists them.
	 *
	 * @param list<array{id: int, username: string}> $users
	 */
	private function users(array $users): IResponse {
		return $this->answer(200, (string)json_encode(['count' => count($users), 'next' => null, 'previous' => null, 'results' => $users]));
	}

	/**
	 * @return array{paperless_sync: string, cases: array<string, array{form: array<string, string>, stored: array<string, array{type: string, lazy: bool, value: string|bool}>, search: array{archives_of_each_owner: bool, user_tags: array<string, list<string>>, owners: array<string, string>}}>}
	 */
	private static function fixture(): array {
		return json_decode((string)file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);
	}
}
