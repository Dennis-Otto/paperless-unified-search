<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\TrustedMounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use OCA\PaperlessUnifiedSearch\Tests\Unit\TeamFolderMount;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Mount\ISystemMountPoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Team folders and external storages that the administrator trusts with the archive
 * (decision 0019), and how a mount tells which one it is.
 */
final class TrustedMountsTest extends TestCase {
	private const SETTING = AppConstants::APP_ID . '.' . TrustedMounts::KEY;

	/**
	 * @return array<string, array{string, list<string>, list<string>}>
	 */
	public static function settings(): array {
		return [
			'none' => ['', [], []],
			'blank' => [" \t ", [], []],
			'one Team folder' => ['team:3', ['team:3'], []],
			'separated by spaces, commas or semicolons' => ['team:3, external:7;team:12  external:1', ['team:3', 'external:7', 'team:12', 'external:1'], []],
			'in any case, each once' => ['Team:3 TEAM:3 External:7', ['team:3', 'external:7'], []],
			'with entries that name no mount' => ['team:3 share:4 team:0 team:03 team: external:-1 groupfolder:5 team:3x', ['team:3'], ['share:4', 'team:0', 'team:03', 'team:', 'external:-1', 'groupfolder:5', 'team:3x']],
			'an unknown entry once' => ['share:4 share:4', [], ['share:4']],
			'an ID of ten digits' => ['team:1234567890 external:1', ['team:1234567890', 'external:1'], []],
			'an ID of more than ten digits' => ['team:12345678901', [], ['team:12345678901']],
			'a name within another word' => ['myteam:3 external:7x', [], ['myteam:3', 'external:7x']],
		];
	}

	/**
	 * @param list<string> $names
	 * @param list<string> $unknown
	 */
	#[DataProvider('settings')]
	public function testReadsTheNamesOfTheSetting(string $setting, array $names, array $unknown): void {
		self::assertSame([$names, $unknown], TrustedMounts::parse($setting));

		$trustedMounts = new TrustedMounts(new MemoryAppConfig([self::SETTING => $setting]));

		self::assertSame($names, $trustedMounts->names());
		self::assertSame($unknown, $trustedMounts->unknown());
	}

	public function testTrustsNoMountWithoutTheSetting(): void {
		$trustedMounts = new TrustedMounts(new MemoryAppConfig());

		self::assertSame([], $trustedMounts->names());
		self::assertSame([], $trustedMounts->unknown());
	}

	public function testStoresTheNamesAndRemovesTheSettingForNone(): void {
		$config = new MemoryAppConfig([self::SETTING => 'share:4']);
		$trustedMounts = new TrustedMounts($config);

		$trustedMounts->save(['team:3', 'external:7']);

		self::assertSame('team:3 external:7', $config->stored(AppConstants::APP_ID, TrustedMounts::KEY));
		self::assertSame(['team:3', 'external:7'], $trustedMounts->names());

		$trustedMounts->save([]);

		self::assertNull($config->stored(AppConstants::APP_ID, TrustedMounts::KEY));
		self::assertSame([], $trustedMounts->names());
	}

	/**
	 * The mount of a Team folder names it by the ID of its folder.
	 *
	 * @return array<string, array{int, ?string}>
	 */
	public static function teamFolders(): array {
		return [
			'a Team folder' => [3, 'team:3'],
			'the first Team folder' => [1, 'team:1'],
			'a folder without a valid ID' => [0, null],
		];
	}

	#[DataProvider('teamFolders')]
	public function testNamesATeamFolderByItsId(int $folderId, ?string $name): void {
		$mount = $this->createStub(TeamFolderMount::class);
		$mount->method('getMountType')->willReturn('group');
		$mount->method('getFolderId')->willReturn($folderId);

		self::assertSame($name, TrustedMounts::nameOf($mount));
	}

	/**
	 * A mount of the type group that doesn't tell the ID of its folder, as of another app
	 * or another version of groupfolders, names no Team folder.
	 */
	public function testAGroupMountWithoutTheIdOfItsFolderIsNone(): void {
		$mount = $this->createStub(ISystemMountPoint::class);
		$mount->method('getMountType')->willReturn('group');
		$mount->method('getMountId')->willReturn(3);

		self::assertNull(TrustedMounts::nameOf($mount));
	}

	/**
	 * An external storage of the administrator is named by the ID of its mount, also one
	 * that logs in with the credentials of each user.
	 *
	 * @return array<string, array{string, ?int, ?string}>
	 */
	public static function systemMounts(): array {
		return [
			'an external storage' => ['external', 7, 'external:7'],
			'the first external storage' => ['external', 1, 'external:1'],
			'an external storage with the login of each user' => ['external-session', 8, 'external:8'],
			'an external storage without an ID' => ['external', null, null],
			'an external storage with an ID of 0' => ['external', 0, null],
			'another system mount' => ['local', 7, null],
		];
	}

	#[DataProvider('systemMounts')]
	public function testNamesAnExternalStorageOfTheAdministratorByTheIdOfItsMount(string $type, ?int $mountId, ?string $name): void {
		$mount = $this->createStub(ISystemMountPoint::class);
		$mount->method('getMountType')->willReturn($type);
		$mount->method('getMountId')->willReturn($mountId);

		self::assertSame($name, TrustedMounts::nameOf($mount));
	}

	/**
	 * An external storage that a user adds in their personal settings is no system mount:
	 * its user could write it outside Nextcloud. Nor is a share, or a missing mount.
	 */
	public function testAMountOfAUserIsNone(): void {
		$mount = $this->createStub(IMountPoint::class);
		$mount->method('getMountType')->willReturn('external');
		$mount->method('getMountId')->willReturn(7);

		self::assertNull(TrustedMounts::nameOf($mount));
		self::assertNull(TrustedMounts::nameOf(null));
	}
}
