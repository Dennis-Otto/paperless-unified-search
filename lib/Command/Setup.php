<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Command;

use OCA\PaperlessUnifiedSearch\Exception\InvalidSettingException;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\SettingProblem;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

/**
 * occ paperless_unified_search:setup: the settings page without a browser, for Ansible,
 * Docker and other set-ups by script. It saves nothing until Paperless answers at the URL
 * with the token, as the settings page does, and takes the token from the standard input
 * or from an environment variable, never from an argument, which the history of the shell
 * and the list of processes would show to others.
 */
final class Setup extends Command {
	use ReportsProblems;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private SettingsMessages $messages,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_unified_search:setup')
			->setDescription('Connect Paperless-ngx: test the connection, then save the settings')
			->setHelp(<<<'HELP'
				Tests the connection to Paperless-ngx at the URL with the API token, and saves
				the settings once Paperless answers, as Test connection and save does in the
				administration settings. An option that you leave out keeps its stored value.

				The token never goes on the command line, where the history of the shell and
				the list of processes would show it:

				  printf '%s' "$TOKEN" | occ paperless_unified_search:setup --url=https://paperless.example.com --token-stdin
				  occ paperless_unified_search:setup --url=https://paperless.example.com --token-env=PAPERLESS_TOKEN

				Without either, the stored token stays, for the server that it was saved for.

				Exits with 0 once the settings are saved, with 1 when Paperless doesn't answer
				or a setting is wrong, and with 2 for a wrong call, such as a missing --url or
				an empty token source.
				HELP)
			->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address of Paperless-ngx, such as https://paperless.example.com')
			->addOption('token-stdin', null, InputOption::VALUE_NONE, 'Read the API token from the first line of the standard input')
			->addOption('token-env', null, InputOption::VALUE_REQUIRED, 'Read the API token from this environment variable')
			->addOption('archive-account', null, InputOption::VALUE_REQUIRED, 'The Nextcloud account that owns the synchronized files; empty for the account of Paperless Sync')
			->addOption('always-search', null, InputOption::VALUE_NEGATABLE, 'Include Paperless in every global search as a trusted service, or not')
			->addOption('personal-always-search', null, InputOption::VALUE_NEGATABLE, 'Let users include Paperless in all their searches in their personal settings, or not')
			->addOption('allow-local', null, InputOption::VALUE_NEGATABLE, 'Allow this Paperless, and only this one, at an address of the local network, or not');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$url = $input->getOption('url');
		if (!is_string($url) || trim($url) === '') {
			self::error($output, 'Name the address of Paperless with --url, such as --url=https://paperless.example.com.');

			return self::INVALID;
		}

		try {
			$token = $this->readToken($input);
		} catch (UnexpectedValueException $problem) {
			self::error($output, $problem->getMessage());

			return self::INVALID;
		}

		$alwaysSearch = self::switchOf($input, 'always-search', $this->configService->isAlwaysSearchEnabled());
		$personalAlwaysSearch = self::switchOf($input, 'personal-always-search', $this->configService->isPersonalAlwaysSearchAllowed());
		$allowLocal = self::switchOf($input, 'allow-local', $this->configService->isLocalNetworkAllowed());
		/** @psalm-suppress MixedAssignment An option of the console, checked before use. */
		$archiveAccount = $input->getOption('archive-account');
		$archiveOwner = is_string($archiveAccount) ? $archiveAccount : $this->configService->getArchiveOwnerSetting();

		$effectiveToken = '';
		try {
			$normalizedUrl = $this->configService->normalizeUrl($url);
			$effectiveToken = $this->configService->resolveToken($token, $normalizedUrl);
			$owner = $this->configService->resolveArchiveOwner($archiveOwner);
			$version = $this->paperlessApi->testConnection($normalizedUrl, $effectiveToken, $allowLocal);
			$config = $this->configService->save($normalizedUrl, $effectiveToken, $alwaysSearch, $owner, $allowLocal, $version, $personalAlwaysSearch);
		} catch (InvalidSettingException $exception) {
			self::error($output, $this->messages->forSetting($exception->problem, trim($archiveOwner)));
			if (in_array($exception->problem, [SettingProblem::TokenRequired, SettingProblem::TokenForOtherServer], true)) {
				self::errors($output)->writeln('Pass the token with --token-stdin or --token-env=NAME.');
			}

			return self::FAILURE;
		} catch (PaperlessRequestException $exception) {
			self::requestFailed($output, $this->messages, $exception, [$effectiveToken]);

			return self::FAILURE;
		}

		$output->writeln(OutputFormatter::escape($version === null
			? sprintf('Paperless answers at %s. The settings are saved.', $config->url)
			: sprintf('Paperless %s (API %s) answers at %s. The settings are saved.', $version->version, $version->apiVersion, $config->url)));
		$this->describe($output, $config);

		return self::SUCCESS;
	}

	/**
	 * The token of --token-env or --token-stdin, or '' to keep the stored one.
	 *
	 * @throws UnexpectedValueException when both name the token, or the one named is empty
	 */
	private function readToken(InputInterface $input): string {
		/** @psalm-suppress MixedAssignment An option of the console, checked before use. */
		$variable = $input->getOption('token-env');
		$fromStandardInput = $input->getOption('token-stdin') === true;
		if (is_string($variable) && $fromStandardInput) {
			throw new UnexpectedValueException('Take the token either from the standard input or from an environment variable, not from both.');
		}

		if (is_string($variable)) {
			$token = $variable !== '' ? getenv($variable) : false;
			if (!is_string($token) || trim($token) === '') {
				throw new UnexpectedValueException(sprintf('The environment variable "%s" holds no token.', $variable));
			}

			return $token;
		}

		if (!$fromStandardInput) {
			return '';
		}

		$stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
		$line = fgets($stream ?? STDIN);
		if ($line === false || trim($line) === '') {
			throw new UnexpectedValueException('The standard input holds no token.');
		}

		return $line;
	}

	/**
	 * The value of a switch such as --allow-local and --no-allow-local, or the stored one
	 * when neither is given.
	 */
	private static function switchOf(InputInterface $input, string $name, bool $stored): bool {
		/** @psalm-suppress MixedAssignment An option of the console, checked before use. */
		$value = $input->getOption($name);

		return is_bool($value) ? $value : $stored;
	}

	/**
	 * The settings as saved, with the archive account that the search uses.
	 */
	private function describe(OutputInterface $output, PublicConfig $config): void {
		$account = $config->archiveOwner !== '' ? $config->archiveOwner : $config->syncAccount;
		$archive = match (true) {
			$account === '' => '<comment>none, so the search shows no Paperless documents; name it with --archive-account</comment>',
			$config->archiveOwnerMissing => '<comment>' . OutputFormatter::escape($account) . ', which does not exist, so the search shows no Paperless documents</comment>',
			$config->archiveOwner === '' => OutputFormatter::escape($account) . ', the account of Paperless Sync',
			default => OutputFormatter::escape($account),
		};

		$output->writeln('Archive account: ' . $archive);
		$output->writeln('Always include Paperless in global search: ' . ($config->alwaysSearch ? 'yes' : 'no'));
		$output->writeln('Users may include Paperless in all their searches: ' . ($config->personalAlwaysSearch ? 'yes' : 'no'));
		$output->writeln('Paperless runs in the local network: ' . ($config->allowLocal ? 'yes' : 'no'));
	}
}
