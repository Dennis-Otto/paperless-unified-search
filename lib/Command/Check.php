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
use OCA\PaperlessUnifiedSearch\Service\ArchiveShares;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\DocumentOwners;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCA\PaperlessUnifiedSearch\Service\TrustedMounts;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * occ paperless_unified_search:check: everything that the search needs, in one go. The
 * connection to Paperless, what the account of the API token may do, the archive account,
 * with a warning when Paperless Sync gives each user copies of the documents that they may
 * view, which the search doesn't show, how many documents of Paperless have a file of the
 * archive account, or of the user of their owner when Paperless Sync gives each owner an
 * archive, which the search can show, and with whom the archive account shares its
 * files, with a warning for each share that allows changes. It exits with 1 when the
 * search can show no document at all, also when no document has a file, and with 0
 * otherwise: an account that may do more or less than the search needs gets a warning,
 * and the search works with it, as it does with the copies for the users who may view a
 * document.
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
		private ArchiveShares $archiveShares,
		private DocumentOwners $documentOwners,
		private TrustedMounts $trustedMounts,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_unified_search:check')
			->setDescription('Check the connection to Paperless-ngx, the API token, the archive account, how many documents have a file and how the archive is shared')
			->setHelp('Tests the connection to Paperless, warns when the account of the API token may change documents or may not view correspondents, document types and tags, checks that the archive account exists, warns when Paperless Sync also puts the documents that each user may view into their own files, which the search does not show, compares the documents of Paperless with the files whose names carry their markers, such as [P412], of the archive account and, when Paperless Sync gives each user the documents that they own, of the user of their owner, and lists with whom the archive account shares its files, with a warning for each share that allows changes. Exits with 1 when the search can show no Paperless document, and with 0 otherwise.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$status = $this->checkTheConnection($output);
		$archive = $this->checkTheArchiveAccount($output);
		$showsDocuments = $status !== null && $archive !== null && $this->checkTheCoverage($output, $status, $archive);
		if ($archive !== null) {
			$this->checkTheShares($output, $archive);
		}
		$this->checkTheHistory($output);

		return $showsDocuments ? self::SUCCESS : self::FAILURE;
	}

	private function checkTheConnection(OutputInterface $output): ?PaperlessStatus {
		if (!$this->configService->isConfigured()) {
			self::notConfigured($output, $this->configService);

			return null;
		}

		try {
			// For the coverage, also where version 10 of the API lists no IDs with the first document.
			$status = $this->paperlessApi->probeTheSettings(withDocumentIds: true);
		} catch (PaperlessRequestException $exception) {
			self::requestFailed($output, $this->messages, $exception, [$this->configService->getToken()]);

			return null;
		}

		$url = $this->configService->getUrl();
		self::passed($output, $status->version === null
			? sprintf('Paperless answers at %s.', $url)
			: sprintf('Paperless %s (API %s) answers at %s.', $status->version->version, $status->version->apiVersion, $url));
		$this->checkTheToken($output, $status);

		return $status;
	}

	/**
	 * What the account of the API token may do beyond what the search needs, or may not do
	 * of what the results show, as Paperless named it after the test of the connection.
	 */
	private function checkTheToken(OutputInterface $output, PaperlessStatus $status): void {
		if ($status->tokenWarnings === null) {
			self::noted($output, $status->privilegesRefused
				? 'Paperless doesn\'t say what the account of the API token may do, so the check can\'t tell whether it may change documents. Give the account the permission View for UI Settings in Paperless, so that the check can tell.'
				: 'Paperless didn\'t answer in time, or not as Paperless does, what the account of the API token may do, so the check can\'t tell this time whether it may change documents, and the overview keeps the warnings of the last test. Run the check again.');

			return;
		}

		$warnings = $this->configService->shownTokenWarnings($status->tokenWarnings);
		if ($warnings === []) {
			self::passed($output, 'The account of the API token may change no document, and may view correspondents, document types and tags.');
		}
		foreach ($warnings as $warning) {
			self::noted($output, $this->messages->forTokenWarning($warning));
		}
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
		if ($this->configService->hasPerUserArchives()) {
			self::noted($output, sprintf('%s See %s', $this->messages->forPerUserArchives(), $this->messages->perUserArchivesHelp()));
		}

		return $archive;
	}

	/**
	 * Compares the documents of Paperless with the files of the archive account: by their
	 * IDs where Paperless lists them, and else only by their number. When Paperless Sync
	 * gives each owner an archive, the files of the user of the owner of each document count
	 * too, as for the search: the comparison looks through them where Paperless names the
	 * owners of the documents and its users, and otherwise says that it leaves them out, so
	 * that the documents without a file of the archive account may have one there.
	 *
	 * @return bool whether the search can show a document, or Paperless has none to show
	 */
	private function checkTheCoverage(OutputInterface $output, PaperlessStatus $status, IUser $archive): bool {
		$archived = $this->fileLocator->findArchivedDocumentIds($archive);
		$eachOwner = $this->configService->hasArchivesOfEachOwner();
		$owned = $eachOwner && $status->documentIds !== null && $status->documentIds !== [] ? $this->findTheFilesOfTheOwners() : null;
		$others = ($eachOwner && $owned === null ? ' Paperless Sync puts the documents of each user into their own files, which this count leaves out.' : '') . $this->trustedMountsLeftOut();
		if ($status->documentIds === null) {
			self::noted($output, sprintf(
				'Coverage: %s document(s) have a file of the archive account; Paperless has %s document(s), without naming them.%s',
				number_format(count($archived)),
				number_format($status->documents),
				$others,
			));

			return self::showsDocuments($output, $archive, $others !== '' || $archived !== [] || $status->documents === 0);
		}

		$missing = array_diff($status->documentIds, $archived, $owned ?? []);
		$covered = count($status->documentIds) - count($missing);
		$coverage = sprintf(
			'Coverage: %s of %s Paperless document(s) have a file of the archive account%s.',
			number_format($covered),
			number_format(count($status->documentIds)),
			$owned === null ? '' : ' or of the user of their owner',
		);
		if ($missing === []) {
			self::passed($output, $coverage);
		} else {
			self::noted($output, $coverage . sprintf(
				' Without one%s: %s.%s',
				count($missing) > self::EXAMPLES ? ', among others' : '',
				implode(', ', array_slice($missing, 0, self::EXAMPLES)),
				$others,
			));
		}

		$unknown = count(array_diff($archived, $status->documentIds));
		if ($unknown > 0) {
			self::noted($output, sprintf(
				'%s document(s) have a file of the archive account, but the account of the API token doesn\'t see them in Paperless, maybe because they were deleted there.',
				number_format($unknown),
			));
		}

		return self::showsDocuments($output, $archive, $others !== '' || $covered > 0 || $status->documentIds === [], $owned !== null);
	}

	/**
	 * The documents that have a file of the user whom Paperless Sync gives their owner, in
	 * the folders of that user, as the search finds them (decision 0013), or null when the
	 * check can't tell: when Paperless names no users, or not the owners of its documents.
	 * The users of Paperless come anew, not from the cache, so that the check tells what
	 * the search finds now, also right after the account got View for Users.
	 *
	 * @return ?list<int>
	 */
	private function findTheFilesOfTheOwners(): ?array {
		try {
			$documents = $this->paperlessApi->findDocumentOwners();
		} catch (Throwable) {
			// Such as a request without answer, or more documents than the check reads.
			return null;
		}
		$this->documentOwners->forget();
		$accounts = $this->documentOwners->accountsOf($documents);
		if ($accounts === null) {
			return null;
		}

		// Each user once; PHP would turn the key of an ID such as 1234 into a number.
		$users = [];
		foreach ($accounts as $account) {
			$users[$account->getUID()] = $account;
		}
		$owned = [];
		foreach ($users as $account) {
			$uid = $account->getUID();
			foreach ($this->fileLocator->findArchivedDocumentIds($account) as $documentId) {
				if (isset($accounts[$documentId]) && $accounts[$documentId]->getUID() === $uid) {
					$owned[] = $documentId;
				}
			}
		}

		return $owned;
	}

	/**
	 * The sentence that the count leaves out the files in the Team folders and external
	 * storages that the administrator trusts (decision 0019), which no account owns: who
	 * finds them there depends on who may only read them. Empty without such a mount.
	 */
	private function trustedMountsLeftOut(): string {
		$names = $this->trustedMounts->names();

		return $names === [] ? '' : sprintf(' The files in the trusted mounts %s stand for documents too, and this count leaves them out.', implode(', ', $names));
	}

	/**
	 * Says why the search shows no Paperless document although Paperless has some and the
	 * archive account exists, the mistake that the check is above all there to find.
	 *
	 * @param bool $shows whether a document has a file, or Paperless has none
	 * @param bool $ofTheOwners whether the files of the users of the owners counted as well
	 * @return bool the same, for the exit code of the check
	 */
	private static function showsDocuments(OutputInterface $output, IUser $archive, bool $shows, bool $ofTheOwners = false): bool {
		if (!$shows) {
			self::error($output, sprintf(
				'No document of Paperless has a file of the archive account %s%s, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account, and check that their names carry the markers of the documents, such as [P412].',
				$archive->getUID(),
				$ofTheOwners ? ' or of the user of its owner' : '',
			));
		}

		return $shows;
	}

	/**
	 * With whom the archive account shares its files, which decides who finds which
	 * document, and what is wrong with each share that allows changes: whoever may write
	 * into the archive can give a file the marker of any document.
	 */
	private function checkTheShares(OutputInterface $output, IUser $archive): void {
		$overview = $this->archiveShares->of($archive->getUID());
		$warnings = $this->archiveShares->warnings($overview);
		$summary = $this->archiveShares->summarize($overview);
		if ($overview->shares === [] || $warnings !== [] || $overview->error !== null) {
			self::noted($output, $summary);
		} else {
			self::passed($output, $summary);
		}
		foreach ($warnings as $warning) {
			self::noted($output, $warning);
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
