<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Command;

use Exception;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Command\Setup;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Owners;
use OCA\PaperlessUnifiedSearch\Tests\Unit\SearchAccountPaperless;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ paperless_unified_search:setup --create-account: the search account in Paperless,
 * set up with the token of a superuser from the standard input or the environment, as
 * the settings page sets it up (decision 0012).
 */
final class SetupCreateAccountTest extends TestCase {
	use Accounts;
	use AppServices;
	use Owners;
	use SearchAccountPaperless;

	public function testCreatesTheAccountAndSavesTheSettingsWithItsToken(): void {
		$tester = $this->tester();
		$tester->setInputs([self::SUPERUSER_TOKEN]);

		$status = $tester->execute([
			'--url' => self::SETUP_URL,
			'--archive-account' => 'archive',
			'--allow-local' => true,
			'--create-account' => true,
			'--token-stdin' => true,
		], ['capture_stderr_separately' => true]);

		self::assertSame(Command::SUCCESS, $status, $tester->getErrorOutput());
		self::assertSame([
			'Created the user nextcloud-search in Paperless, which may only view documents, correspondents, document types, tags and its UI settings.',
			'The workflow "Nextcloud search: view new documents" lets it view each new document.',
			'It may view the 2 documents and the 1 correspondents, document types and tags that Paperless has.',
			'Paperless 3.3.0 (API 10) answers at http://paperless:8000. The settings are saved.',
			'Archive account: archive',
		], array_slice(self::lines($tester->getDisplay()), 0, 5));
		self::assertSame('', $tester->getErrorOutput());
		self::assertSame(self::ACCOUNT_TOKEN, $this->storedToken);
		self::assertSame(self::SETUP_URL, $this->settings[AppConstants::APP_ID . '.paperless_url']);
		self::assertStringNotContainsString(self::SUPERUSER_TOKEN, $tester->getDisplay());
	}

	/**
	 * Without the workflow and the permissions on what Paperless has, the user may only
	 * view what Paperless lets every user view.
	 */
	public function testCreatesOnlyTheUserWhenAskedTo(): void {
		putenv('PAPERLESS_UNIFIED_SEARCH_SUPERUSER=' . self::SUPERUSER_TOKEN);
		try {
			$tester = $this->tester();
			$status = $tester->execute([
				'--url' => self::SETUP_URL,
				'--allow-local' => true,
				'--create-account' => true,
				'--token-env' => 'PAPERLESS_UNIFIED_SEARCH_SUPERUSER',
				'--no-view-new-documents' => true,
				'--no-view-existing' => true,
			], ['capture_stderr_separately' => true]);
		} finally {
			putenv('PAPERLESS_UNIFIED_SEARCH_SUPERUSER');
		}

		self::assertSame(Command::SUCCESS, $status, $tester->getErrorOutput());
		self::assertSame('Created the user nextcloud-search in Paperless, which may only view documents, correspondents, document types, tags and its UI settings.', self::lines($tester->getDisplay())[0]);
		self::assertStringStartsWith('Paperless 3.3.0', self::lines($tester->getDisplay())[1]);
		self::assertSame(['GET /api/ui_settings/', 'GET /api/users/', 'POST /api/users/', 'POST /api/token/'], array_slice(array_column($this->requests, 0), 0, 4));
		self::assertNotContains('POST /api/workflows/', array_column($this->requests, 0));
	}

	/**
	 * The token of the superuser has to come from the standard input or the environment.
	 */
	public function testRefusesToCreateTheAccountWithoutAToken(): void {
		$tester = $this->tester();

		$status = $tester->execute(['--url' => self::SETUP_URL, '--create-account' => true], ['capture_stderr_separately' => true]);

		self::assertSame(Command::INVALID, $status);
		self::assertSame(['Pass the API token of a superuser of Paperless with --token-stdin or --token-env=NAME: it creates the search account and is not stored.'], self::lines($tester->getErrorOutput()));
		self::assertSame([], $this->requests);
	}

	/**
	 * @return array<string, array{array<string, mixed>, list<string>}>
	 */
	public static function failures(): array {
		$repeated = new Exception('cURL error 7: Failed to connect with Authorization: Token ' . self::SUPERUSER_TOKEN);

		return [
			'the token of another account' => [
				['GET /api/ui_settings/' => self::reply(200, '{"user":{"id":3,"is_superuser":false},"permissions":[]}')],
				['This API token belongs to no superuser of Paperless, so it can\'t set up the search account. Enter the token of a superuser; it is not stored.'],
			],
			'a user of the name' => [
				['GET /api/users/' => self::reply(200, '{"count":1,"results":[{"id":9,"username":"nextcloud-search"}]}')],
				['The user nextcloud-search exists in Paperless already, and stays as it is. Save the settings with its API token, or delete the user in Paperless and try again.'],
			],
			'a user that stays' => [
				['POST /api/token/' => self::reply(500, ''), 'DELETE /api/users/7/' => self::reply(500, '')],
				['Paperless did not finish setting up the search account, and the user nextcloud-search remains in it. Delete the user in Paperless, then try again.'],
			],
			'a workflow of the name' => [
				['GET /api/workflows/' => self::reply(200, '{"count":1,"results":[{"id":4,"name":"Nextcloud search: view new documents"}]}')],
				['The workflow “Nextcloud search: view new documents” exists in Paperless already, and stays as it is. Delete it in Paperless and try again, or turn off “Let it view each new document”.'],
			],
			'no answer that repeats the token' => [
				['GET /api/ui_settings/' => $repeated],
				[
					'Nextcloud could not reach Paperless at this URL. Check the address and the port, and that Paperless runs.',
					'unreachable, Exception: cURL error 7: Failed to connect with Authorization: Token …',
				],
			],
		];
	}

	/**
	 * Each failure names what to do, without the token of the superuser, and saves nothing.
	 *
	 * @param array<string, mixed> $answers
	 * @param list<string> $errors
	 */
	#[DataProvider('failures')]
	public function testNamesWhatFailedAndSavesNothing(array $answers, array $errors): void {
		$this->instead = $answers;
		$tester = $this->tester();
		$tester->setInputs([self::SUPERUSER_TOKEN]);

		$status = $tester->execute(['--url' => self::SETUP_URL, '--allow-local' => true, '--create-account' => true, '--token-stdin' => true], [
			'capture_stderr_separately' => true,
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(Command::FAILURE, $status);
		self::assertSame($errors, self::lines($tester->getErrorOutput()));
		self::assertNull($this->storedToken);
		self::assertSame([], $this->settings);
	}

	private function tester(): CommandTester {
		$accounts = $this->accounts('archive');
		$configService = $this->configService($accounts);
		$client = $this->paperlessForTheSetUp();
		$api = $this->paperlessApi($configService, $client);

		return new CommandTester(new Setup($configService, $api, $this->messages(), $this->searchAccountSetup($client), $this->documentOwners($api, $configService, $accounts)));
	}

	/**
	 * @return list<string>
	 */
	private static function lines(string $output): array {
		return array_values(array_filter(array_map('rtrim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));
	}
}
