<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Command;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Command\Setup;
use OCA\PaperlessUnifiedSearch\Tests\Unit\Accounts;
use OCA\PaperlessUnifiedSearch\Tests\Unit\AppServices;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

final class SetupTest extends TestCase {
	use Accounts;
	use AppServices;

	private const ENVIRONMENT_VARIABLE = 'PAPERLESS_UNIFIED_SEARCH_TEST_TOKEN';

	protected function tearDown(): void {
		putenv(self::ENVIRONMENT_VARIABLE);
	}

	/**
	 * The token never goes on the command line, where the history of the shell and the
	 * list of processes would show it to others.
	 */
	public function testTakesTheTokenFromNoArgument(): void {
		$definition = $this->command()->getDefinition();

		self::assertSame('paperless_unified_search:setup', $this->command()->getName());
		self::assertSame([], $definition->getArguments());
		self::assertFalse($definition->hasOption('token'));
		self::assertFalse($definition->getOption('token-stdin')->acceptValue());
	}

	public function testSavesTheSettingsOnceTheConnectionWorks(): void {
		$tester = $this->tester($this->documents([['id' => 7]]));
		$tester->setInputs(['TEST_VALUE']);

		$status = $tester->execute([
			'--url' => ' http://paperless:8000/ ',
			'--archive-account' => 'Archive',
			'--allow-local' => true,
			'--token-stdin' => true,
		], ['capture_stderr_separately' => true]);

		self::assertSame(Command::SUCCESS, $status, $tester->getErrorOutput());
		self::assertSame([
			'Paperless 3.3.0 (API 10) answers at http://paperless:8000. The settings are saved.',
			'Archive account: archive',
			'Always include Paperless in global search: no',
			'Paperless runs in the local network: yes',
		], self::lines($tester->getDisplay()));
		self::assertSame('', $tester->getErrorOutput());
		self::assertSame('TEST_VALUE', $this->storedToken);
		self::assertSame([
			AppConstants::APP_ID . '.paperless_url' => 'http://paperless:8000',
			AppConstants::APP_ID . '.always_search' => false,
			AppConstants::APP_ID . '.allow_local' => true,
			// As Nextcloud spells the account.
			AppConstants::APP_ID . '.archive_owner' => 'archive',
			AppConstants::APP_ID . '.paperless_version' => '3.3.0',
			AppConstants::APP_ID . '.paperless_api_version' => '10',
		], $this->settings);
		self::assertCount(1, $this->requests);
		[$url, $options] = $this->requests[0];
		self::assertSame('http://paperless:8000/api/documents/', $url);
		self::assertSame('Token TEST_VALUE', $options['headers']['Authorization']);
		self::assertSame(['allow_local_address' => true], $options['nextcloud']);
	}

	public function testTakesTheTokenFromAnEnvironmentVariable(): void {
		putenv(self::ENVIRONMENT_VARIABLE . '= TEST_VALUE ');
		$tester = $this->tester($this->documents([]));

		$status = $tester->execute(['--url' => 'https://paperless.example.com', '--token-env' => self::ENVIRONMENT_VARIABLE]);

		self::assertSame(Command::SUCCESS, $status);
		self::assertSame('TEST_VALUE', $this->storedToken);
		self::assertSame('Token TEST_VALUE', $this->requests[0][1]['headers']['Authorization']);
	}

	/**
	 * @return array<string, array{array<string, mixed>, list<string>, string}>
	 */
	public static function sourcesWithoutAToken(): array {
		return [
			'an empty standard input' => [['--token-stdin' => true], [], 'The standard input holds no token.'],
			'a blank line' => [['--token-stdin' => true], ['  '], 'The standard input holds no token.'],
			'a variable that is not set' => [['--token-env' => 'PAPERLESS_UNIFIED_SEARCH_NO_TOKEN'], [], 'The environment variable "PAPERLESS_UNIFIED_SEARCH_NO_TOKEN" holds no token.'],
			'a variable without a name' => [['--token-env' => ''], [], 'The environment variable "" holds no token.'],
			'both sources' => [['--token-stdin' => true, '--token-env' => self::ENVIRONMENT_VARIABLE], ['TEST_VALUE'], 'Take the token either from the standard input or from an environment variable, not from both.'],
			'no URL' => [['--token-stdin' => true, '--url' => null], ['TEST_VALUE'], 'Name the address of Paperless with --url, such as --url=https://paperless.example.com.'],
			'a blank URL' => [['--token-stdin' => true, '--url' => ' '], ['TEST_VALUE'], 'Name the address of Paperless with --url, such as --url=https://paperless.example.com.'],
		];
	}

	/**
	 * @param array<string, mixed> $options
	 * @param list<string> $inputs
	 */
	#[DataProvider('sourcesWithoutAToken')]
	public function testRefusesASourceWithoutAToken(array $options, array $inputs, string $message): void {
		putenv(self::ENVIRONMENT_VARIABLE . '=TEST_VALUE');
		$tester = $this->tester();
		$tester->setInputs($inputs);

		$status = $tester->execute(array_filter(['--url' => 'https://paperless.example.com', ...$options], static fn (mixed $value): bool => $value !== null), ['capture_stderr_separately' => true]);

		self::assertSame(Command::INVALID, $status);
		self::assertSame([$message], self::lines($tester->getErrorOutput()));
		self::assertSame([], $this->requests);
		self::assertSame([], $this->settings);
	}

	public function testRefusesToSaveWithoutAToken(): void {
		$tester = $this->tester();

		$status = $tester->execute(['--url' => 'https://paperless.example.com'], ['capture_stderr_separately' => true]);

		self::assertSame(Command::FAILURE, $status);
		self::assertSame([
			'A Paperless API token is required.',
			'Pass the token with --token-stdin or --token-env=NAME.',
		], self::lines($tester->getErrorOutput()));
		self::assertSame([], $this->requests);
		self::assertSame([], $this->settings);
	}

	/**
	 * As on the settings page, the stored token stays for the server that it was saved
	 * for, and only for that one.
	 */
	public function testKeepsTheStoredTokenOnlyForItsServer(): void {
		$this->connected();
		$tester = $this->tester($this->documents([]));

		self::assertSame(Command::SUCCESS, $tester->execute(['--url' => 'https://paperless.example.com/']));
		self::assertSame('Token TEST_VALUE', $this->requests[0][1]['headers']['Authorization']);

		$status = $tester->execute(['--url' => 'https://evil.example.com'], ['capture_stderr_separately' => true]);

		self::assertSame(Command::FAILURE, $status);
		self::assertSame([
			'Enter the API token again: a stored token is only sent to the Paperless server it was saved for.',
			'Pass the token with --token-stdin or --token-env=NAME.',
		], self::lines($tester->getErrorOutput()));
		self::assertCount(1, $this->requests);
		self::assertSame('https://paperless.example.com', $this->settings[AppConstants::APP_ID . '.paperless_url']);
	}

	/**
	 * An option that is left out keeps its stored value, so that a script can change one
	 * setting, such as the token, without repeating all others.
	 */
	public function testKeepsWhatItIsNotGiven(): void {
		$this->connected();
		$this->settings[AppConstants::APP_ID . '.always_search'] = true;
		$this->settings[AppConstants::APP_ID . '.allow_local'] = true;
		$tester = $this->tester($this->documents([]));
		$tester->setInputs(['NEW_VALUE']);

		self::assertSame(Command::SUCCESS, $tester->execute(['--url' => 'https://paperless.example.com', '--token-stdin' => true]));

		self::assertSame('NEW_VALUE', $this->storedToken);
		self::assertTrue($this->settings[AppConstants::APP_ID . '.always_search']);
		self::assertTrue($this->settings[AppConstants::APP_ID . '.allow_local']);
		self::assertSame('archive', $this->settings[AppConstants::APP_ID . '.archive_owner']);
		self::assertSame(['allow_local_address' => true], $this->requests[0][1]['nextcloud']);
	}

	public function testChangesWhatItIsGiven(): void {
		$this->connected();
		$this->settings[AppConstants::APP_ID . '.allow_local'] = true;
		$this->settings['paperless_sync.target_user'] = 'alex';
		$tester = $this->tester($this->documents([]));

		$status = $tester->execute([
			'--url' => 'https://paperless.example.com',
			'--always-search' => true,
			'--allow-local' => false,
			'--archive-account' => '',
		]);

		self::assertSame(Command::SUCCESS, $status);
		self::assertTrue($this->settings[AppConstants::APP_ID . '.always_search']);
		self::assertFalse($this->settings[AppConstants::APP_ID . '.allow_local']);
		self::assertArrayNotHasKey(AppConstants::APP_ID . '.archive_owner', $this->settings);
		self::assertSame([
			'Paperless 3.3.0 (API 10) answers at https://paperless.example.com. The settings are saved.',
			'Archive account: alex, the account of Paperless Sync',
			'Always include Paperless in global search: yes',
			'Paperless runs in the local network: no',
		], self::lines($tester->getDisplay()));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function archiveAccounts(): array {
		return [
			'none' => ['', 'Archive account: none, so the search shows no Paperless documents; name it with --archive-account'],
			'the account of Paperless Sync, which is gone' => ['gone', 'Archive account: gone, which does not exist, so the search shows no Paperless documents'],
		];
	}

	#[DataProvider('archiveAccounts')]
	public function testWarnsWhenTheSearchHasNoArchiveAccount(string $syncAccount, string $line): void {
		$this->settings['paperless_sync.target_user'] = $syncAccount;
		$tester = $this->tester($this->documents([], headers: []));
		$tester->setInputs(['TEST_VALUE']);

		self::assertSame(Command::SUCCESS, $tester->execute(['--url' => 'https://paperless.example.com', '--token-stdin' => true]));

		self::assertSame([
			'Paperless answers at https://paperless.example.com. The settings are saved.',
			$line,
			'Always include Paperless in global search: no',
			'Paperless runs in the local network: no',
		], self::lines($tester->getDisplay()));
	}

	/**
	 * @return array<string, array{array<string, string>, string}>
	 */
	public static function invalidSettings(): array {
		return [
			'another scheme' => [['--url' => 'ftp://paperless.example.com'], 'The Paperless URL must use HTTP or HTTPS.'],
			'an archive account that does not exist' => [['--archive-account' => ' nobody '], 'The archive account nobody does not exist.'],
		];
	}

	/**
	 * @param array<string, string> $options
	 */
	#[DataProvider('invalidSettings')]
	public function testRefusesInvalidSettings(array $options, string $message): void {
		$tester = $this->tester();
		$tester->setInputs(['TEST_VALUE']);

		$status = $tester->execute(['--url' => 'https://paperless.example.com', '--token-stdin' => true, ...$options], ['capture_stderr_separately' => true]);

		self::assertSame(Command::FAILURE, $status);
		self::assertSame([$message], self::lines($tester->getErrorOutput()));
		self::assertSame([], $this->settings);
	}

	public function testNamesWhatFailedAndSavesNothing(): void {
		$tester = $this->tester($this->answer(401, '{"detail":"Invalid token"}', []));
		$tester->setInputs(['TEST_VALUE']);

		$status = $tester->execute(['--url' => 'https://paperless.example.com', '--token-stdin' => true], ['capture_stderr_separately' => true]);

		self::assertSame(Command::FAILURE, $status);
		self::assertSame(['Paperless rejected the API token (HTTP 401). Check the token, and that its account may view documents.'], self::lines($tester->getErrorOutput()));
		self::assertSame([], $this->settings);
		self::assertNull($this->storedToken);
	}

	/**
	 * With -v, occ shows the error itself, without the token, which an error of cURL may
	 * repeat with the headers of the request.
	 */
	public function testShowsTheErrorWithoutTheTokenWhenAskedTo(): void {
		$error = new RuntimeException('cURL error 7: Failed to connect with Authorization: Token TEST_VALUE');
		$tester = $this->tester($error, $error);
		$tester->setInputs(['TEST_VALUE']);

		$status = $tester->execute(['--url' => 'https://paperless.example.com', '--token-stdin' => true], [
			'capture_stderr_separately' => true,
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(Command::FAILURE, $status);
		self::assertSame([
			'Nextcloud could not reach Paperless at this URL. Check the address and the port, and that Paperless runs.',
			'unreachable, RuntimeException: cURL error 7: Failed to connect with Authorization: Token …',
		], self::lines($tester->getErrorOutput()));
	}

	public function testNamesTheOptionForTheLocalNetwork(): void {
		$tester = $this->tester(new LocalServerException('Host "paperless" violates local access rules'));
		$tester->setInputs(['TEST_VALUE']);

		$status = $tester->execute(['--url' => 'http://paperless:8000', '--token-stdin' => true], ['capture_stderr_separately' => true]);

		self::assertSame(Command::FAILURE, $status);
		self::assertSame([
			'Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.',
			'The option --allow-local of occ paperless_unified_search:setup allows this Paperless, and only this one, at an address of the local network.',
		], self::lines($tester->getErrorOutput()));
	}

	private function tester(IResponse|Throwable ...$answers): CommandTester {
		return new CommandTester($this->command(...$answers));
	}

	private function command(IResponse|Throwable ...$answers): Setup {
		$configService = $this->configService($this->accounts('archive', 'alex'));

		return new Setup($configService, $this->paperlessApi($configService, $this->paperless(...$answers)), $this->messages());
	}

	/**
	 * @return list<string>
	 */
	private static function lines(string $output): array {
		return array_values(array_filter(array_map('rtrim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));
	}
}
