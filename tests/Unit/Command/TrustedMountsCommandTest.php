<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Command;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Command\TrustedMountsCommand;
use OCA\PaperlessUnifiedSearch\Service\TrustedMounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\MemoryAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ paperless_unified_search:trusted-mounts, which names the Team folders and external
 * storages that hold the archive (decision 0019).
 */
final class TrustedMountsCommandTest extends TestCase {
	private const NONE = "No Team folder and no external storage is trusted: only the files of the archive account stand for documents.\n";

	private MemoryAppConfig $config;

	protected function setUp(): void {
		$this->config = new MemoryAppConfig();
	}

	public function testIsACommandOfTheApp(): void {
		self::assertSame('paperless_unified_search:trusted-mounts', $this->command()->getName());
		self::assertStringContainsString('occ paperless_unified_search:trusted-mounts team:3 external:7', $this->command()->getHelp());
	}

	public function testListsNoMountWhenNoneIsTrusted(): void {
		$tester = new CommandTester($this->command());

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(self::NONE, $tester->getDisplay());
	}

	/**
	 * The names replace those that the setting held, each once, in any case.
	 */
	public function testTrustsTheNamedMountsInsteadOfTheStoredOnes(): void {
		$this->config->seed(AppConstants::APP_ID, TrustedMounts::KEY, 'team:9');
		$tester = new CommandTester($this->command());

		self::assertSame(Command::SUCCESS, $tester->execute(['mounts' => ['Team:3', 'external:7,team:3']]));

		self::assertSame("Trusted mounts: team:3, external:7. Their files stand for documents for each user who may only read them there.\n", $tester->getDisplay());
		self::assertSame('team:3 external:7', $this->config->stored(AppConstants::APP_ID, TrustedMounts::KEY));
	}

	public function testTrustsNoMountAgainWithNone(): void {
		$this->config->seed(AppConstants::APP_ID, TrustedMounts::KEY, 'team:3');
		$tester = new CommandTester($this->command());

		self::assertSame(Command::SUCCESS, $tester->execute(['--none' => true]));

		self::assertSame(self::NONE, $tester->getDisplay());
		self::assertNull($this->config->stored(AppConstants::APP_ID, TrustedMounts::KEY));
	}

	/**
	 * A name that is neither team:<ID> nor external:<ID> changes nothing, and neither does a
	 * call that names mounts and --none.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalidCalls(): array {
		return [
			'an unknown name' => [['mounts' => ['team:3', 'share:4', 'groupfolder:5']], 'Name a Team folder as team:<ID> and an external storage as external:<mount ID>, not share:4, groupfolder:5.'],
			'names and --none' => [['mounts' => ['team:3'], '--none' => true], 'Name trusted mounts or pass --none, not both.'],
		];
	}

	/**
	 * @param array<string, mixed> $call
	 */
	#[DataProvider('invalidCalls')]
	public function testRefusesAnInvalidCall(array $call, string $message): void {
		$this->config->seed(AppConstants::APP_ID, TrustedMounts::KEY, 'team:9');
		$tester = new CommandTester($this->command());

		self::assertSame(Command::INVALID, $tester->execute($call, ['capture_stderr_separately' => true]));

		self::assertSame('', $tester->getDisplay());
		self::assertSame($message . "\n", $tester->getErrorOutput());
		self::assertSame('team:9', $this->config->stored(AppConstants::APP_ID, TrustedMounts::KEY));
	}

	/**
	 * occ config:app:set stores whatever it gets: the list names an entry that counts for
	 * nothing apart, on the error output.
	 */
	public function testNamesTheEntriesOfTheSettingThatCountForNothing(): void {
		$this->config->seed(AppConstants::APP_ID, TrustedMounts::KEY, 'team:3 share:4');
		$tester = new CommandTester($this->command());

		self::assertSame(Command::SUCCESS, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame("Trusted mounts: team:3. Their files stand for documents for each user who may only read them there.\n", $tester->getDisplay());
		self::assertSame("The setting trusted_mounts also holds share:4, which names no Team folder and no external storage and counts for nothing.\n", $tester->getErrorOutput());
	}

	private function command(): TrustedMountsCommand {
		return new TrustedMountsCommand(new TrustedMounts($this->config));
	}
}
