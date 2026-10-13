<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Command;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\PaperlessStatus;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_unified_search:check: everything that the search needs, in one go. The
 * connection to Paperless, the archive account, and how many documents of Paperless have
 * a file of the archive account, which the search can show. It exits with 1 when the
 * search can show no document at all, and with 0 otherwise.
 */
final class Check extends Command {
	use ReportsProblems;

	/** How many documents without a file the check names at most. */
	private const EXAMPLES = 10;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private NextcloudFileLocator $fileLocator,
		private SearchDiagnostics $diagnostics,
		private IUserManager $userManager,
		private SettingsMessages $messages,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_unified_search:check')
			->setDescription('Check the connection to Paperless-ngx, the archive account and how many documents have a file')
			->setHelp('Tests the connection to Paperless, checks that the archive account exists, and compares the documents of Paperless with the files of the archive account whose names carry their markers, such as [P412]. Exits with 1 when the search can show no Paperless document, and with 0 otherwise.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$status = $this->checkTheConnection($output);
		$archive = $this->checkTheArchiveAccount($output);
		if ($status !== null && $archive !== null) {
			$this->checkTheCoverage($output, $status, $archive);
		}
		$this->checkTheHistory($output);

		return $status !== null && $archive !== null ? self::SUCCESS : self::FAILURE;
	}

	private function checkTheConnection(OutputInterface $output): ?PaperlessStatus {
		if (!$this->configService->isConfigured()) {
			self::notConfigured($output, $this->configService);

			return null;
		}

		try {
			$status = $this->paperlessApi->probeTheSettings();
		} catch (PaperlessRequestException $exception) {
			self::requestFailed($output, $this->messages, $exception, [$this->configService->getToken()]);

			return null;
		}

		$url = $this->configService->getUrl();
		self::passed($output, $status->version === null
			? sprintf('Paperless answers at %s.', $url)
			: sprintf('Paperless %s (API %s) answers at %s.', $status->version->version, $status->version->apiVersion, $url));

		return $status;
	}

	private function checkTheArchiveAccount(OutputInterface $output): ?IUser {
		$setting = $this->configService->getArchiveOwnerSetting();
		$named = $setting !== '' ? $setting : $this->configService->getSyncAccount();
		$archive = $named !== '' ? $this->userManager->get($named) : null;
		if ($archive === null) {
			self::error($output, $named === ''
				? 'No archive account is set, and Paperless Sync names none, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account.'
				: sprintf('The archive account "%s" does not exist, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account.', $named));

			return null;
		}

		self::passed($output, $setting !== ''
			? sprintf('The archive account is %s.', $archive->getUID())
			: sprintf('The archive account is %s, the account of Paperless Sync.', $archive->getUID()));

		return $archive;
	}

	/**
	 * Compares the documents of Paperless with the files of the archive account: by their
	 * IDs where Paperless lists them, and else only by their number.
	 */
	private function checkTheCoverage(OutputInterface $output, PaperlessStatus $status, IUser $archive): void {
		$archived = $this->fileLocator->findArchivedDocumentIds($archive);
		if ($status->documentIds === null) {
			self::noted($output, sprintf(
				'Coverage: %s file(s) of the archive account carry the marker of a document; Paperless has %s document(s), without naming them.',
				number_format(count($archived)),
				number_format($status->documents),
			));

			return;
		}

		$missing = array_values(array_diff($status->documentIds, $archived));
		$covered = count($status->documentIds) - count($missing);
		$coverage = sprintf('Coverage: %s of %s Paperless document(s) have a file of the archive account.', number_format($covered), number_format(count($status->documentIds)));
		if ($missing === []) {
			self::passed($output, $coverage);
		} else {
			self::noted($output, $coverage . sprintf(
				' Without one%s: %s.',
				count($missing) > self::EXAMPLES ? ', among others' : '',
				implode(', ', array_slice($missing, 0, self::EXAMPLES)),
			));
		}

		$unknown = count(array_diff($archived, $status->documentIds));
		if ($unknown > 0) {
			self::noted($output, sprintf(
				'%s file(s) of the archive account carry the marker of a document that the account of the API token doesn\'t see in Paperless, maybe one deleted there.',
				number_format($unknown),
			));
		}
	}

	/**
	 * Whether a search failed and none has worked since, as the history of the problems of
	 * the search in the settings keeps it.
	 */
	private function checkTheHistory(OutputInterface $output): void {
		$history = $this->diagnostics->getHistory();
		if ($history === null || $history->lastFailureAt === null || !$history->awaitsRecovery()) {
			return;
		}

		$failedAt = (new DateTimeImmutable('@' . $history->lastFailureAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
		self::noted($output, sprintf(
			'The last search failed at %s UTC, and none has worked since. The settings list the problems of the search.',
			$failedAt,
		));
	}

	private static function passed(OutputInterface $output, string $message): void {
		$output->writeln('<info>✓</info> ' . OutputFormatter::escape($message));
	}

	private static function noted(OutputInterface $output, string $message): void {
		$output->writeln('<comment>!</comment> ' . OutputFormatter::escape($message));
	}
}
