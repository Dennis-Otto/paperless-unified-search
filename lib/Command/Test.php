<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Command;

use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_unified_search:test: whether Paperless answers with the stored settings,
 * for monitoring and for scripts, which read the exit code: 0 when it does, 1 otherwise.
 */
final class Test extends Command {
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
		$this->setName('paperless_unified_search:test')
			->setDescription('Test the connection to Paperless-ngx with the stored settings')
			->setHelp('Asks Paperless for one document with the stored URL and API token, and exits with 0 when it answers, or names what failed and exits with 1. It also warns, without failing, when the account of the token may change documents, or may not view the correspondents and the document types that results show.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->configService->isConfigured()) {
			self::notConfigured($output, $this->configService);

			return self::FAILURE;
		}

		try {
			$status = $this->paperlessApi->probeTheSettings();
		} catch (PaperlessRequestException $exception) {
			self::requestFailed($output, $this->messages, $exception, [$this->configService->getToken()]);

			return self::FAILURE;
		}

		$url = $this->configService->getUrl();
		$output->writeln(OutputFormatter::escape($status->version === null
			? sprintf('Paperless answers at %s.', $url)
			: sprintf('Paperless %s (API %s) answers at %s.', $status->version->version, $status->version->apiVersion, $url)));
		$output->writeln(sprintf('The account of the API token may view %s document(s).', number_format($status->documents)));
		self::warnAboutTheToken($output, $this->messages, $status->tokenWarnings ?? []);

		return self::SUCCESS;
	}
}
