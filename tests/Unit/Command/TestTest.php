<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Command;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Command\Test;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * occ paperless_unified_search:test, whose exit code monitoring and scripts read.
 */
final class TestTest extends TestCase {
	use Accounts;
	use AppServices;

	public function testIsTheTestOfTheApp(): void {
		self::assertSame('paperless_unified_search:test', $this->command()->getName());
	}

	public function testPassesWhenPaperlessAnswers(): void {
		$this->connected();
		$tester = $this->tester($this->documents([['id' => 1]], 1234));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\nThe account of the API token may view 1,234 document(s).\n",
			$tester->getDisplay(),
		);
		self::assertSame(['page' => 1, 'page_size' => 1, 'fields' => 'id'], $this->requests[0][1]['query']);
		// The overview of the administration settings shows the version of the last answer.
		self::assertSame('3.3.0', $this->settings[AppConstants::APP_ID . '.paperless_version']);
	}

	/**
	 * A token of a superuser works, with a warning; monitoring still reads 0.
	 */
	public function testWarnsAboutATokenThatMayChangeDocuments(): void {
		$this->connected();
		$this->privileges = $this->uiSettings([], superuser: true);
		$tester = $this->tester($this->documents([['id' => 1]], 1));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertSame(
			"Paperless 3.3.0 (API 10) answers at https://paperless.example.com.\n"
			. "The account of the API token may view 1 document(s).\n"
			. "Warning: The API token belongs to a Superuser of Paperless: whoever gets hold of it can change and delete every document. Use the token of an account that may only view documents.\n",
			$tester->getDisplay(),
		);
		self::assertSame('superuser', $this->settings[AppConstants::APP_ID . '.token_warnings']);
	}

	public function testPassesWithoutAVersion(): void {
		$this->connected();
		$tester = $this->tester($this->documents([], headers: []));

		self::assertSame(Command::SUCCESS, $tester->execute([]));

		self::assertStringStartsWith("Paperless answers at https://paperless.example.com.\n", $tester->getDisplay());
	}

	public function testFailsWhenPaperlessRefusesTheToken(): void {
		$this->connected();
		$tester = $this->tester($this->answer(403, '{"detail":"Forbidden"}', []));

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame('', $tester->getDisplay());
		self::assertSame("Paperless rejected the API token (HTTP 403). Check the token, and that its account may view documents.\n", $tester->getErrorOutput());
	}

	public function testFailsWithoutAConnection(): void {
		$tester = $this->tester();

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame("Paperless Unified Search isn't connected to Paperless yet. Connect it with occ paperless_unified_search:setup.\n", $tester->getErrorOutput());
		self::assertSame([], $this->requests);
	}

	public function testFailsWhenTheTokenCannotBeDecrypted(): void {
		$this->connected();
		$this->storedToken = false;
		$tester = $this->tester();

		self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));

		self::assertSame("The stored API token can't be decrypted, as after a move or a restore of Nextcloud with another secret in config.php. Enter it again with occ paperless_unified_search:setup.\n", $tester->getErrorOutput());
	}

	private function tester(IResponse|Throwable ...$answers): CommandTester {
		return new CommandTester($this->command(...$answers));
	}

	private function command(IResponse|Throwable ...$answers): Test {
		$configService = $this->configService($this->accounts('archive'));

		return new Test($configService, $this->paperlessApi($configService, $this->paperless(...$answers)), $this->messages());
	}
}
