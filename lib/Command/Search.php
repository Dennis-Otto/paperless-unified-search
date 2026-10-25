<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Command;

use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\Explanation;
use OCA\PaperlessUnifiedSearch\Model\Verdict;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchExplainer;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * occ paperless_unified_search:search: the search of a user, with the documents that
 * Paperless found and whether each shows to the user, and with --explain why not. The
 * search itself leaves out a document without a file of the archive account that the user
 * can open, without a trace: a search where Paperless found twelve documents that the
 * user may not open looks exactly like one where it found none.
 */
final class Search extends Command {
	use ReportsProblems;

	private const DEFAULT_LIMIT = 10;

	/** The most hits of one page of Paperless, as the app asks for them. */
	private const MAX_LIMIT = 50;

	/** The words of the table for each verdict. */
	private const LABELS = [
		'shown' => 'shown',
		'hidden' => 'hidden',
		'writable' => 'writable',
		'not_shared' => 'not shared',
		'not_owned' => 'not owned',
		'no_file' => 'no file',
	];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private SearchExplainer $explainer,
		private IUserManager $userManager,
		private SettingsMessages $messages,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_unified_search:search')
			->setDescription('Search Paperless as a user, and see which documents show to the user and why the others don\'t')
			->setHelp(<<<'HELP'
				Searches Paperless for the term as the search of the user does, and lists the
				documents that Paperless ranks first, with whether each shows to the user. With
				--explain, each hidden document names its reason:

				  not shared  the archive account has a file of it, but hasn't shared it with the user
				  not owned   the user sees a file with its marker, but another account owns that file
				  no file     no file of the archive account carries its marker, such as [P412]
				HELP)
			->addArgument('user', InputArgument::REQUIRED, 'The ID of the Nextcloud account that searches')
			->addArgument('term', InputArgument::REQUIRED, 'What to search for, as in the search of Nextcloud')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many documents of the ranking of Paperless to go through, up to ' . self::MAX_LIMIT, (string)self::DEFAULT_LIMIT)
			->addOption('explain', null, InputOption::VALUE_NONE, 'Name why each hidden document doesn\'t show')
			->addOption('json', null, InputOption::VALUE_NONE, 'Write the documents as JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');
		$term = trim((string)$input->getArgument('term'));
		$limit = $input->getOption('limit');
		if (!is_string($limit) || !ctype_digit($limit) || (int)$limit < 1 || (int)$limit > self::MAX_LIMIT) {
			self::error($output, sprintf('The limit is a number from 1 to %d.', self::MAX_LIMIT));

			return self::INVALID;
		}
		if ($term === '') {
			self::error($output, 'Name a term to search for.');

			return self::INVALID;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			self::error($output, sprintf('There is no account "%s".', $uid));

			return self::FAILURE;
		}
		if (!$this->configService->isConfigured()) {
			self::notConfigured($output, $this->configService);

			return self::FAILURE;
		}
		$archiveOwner = $this->configService->getArchiveOwner();
		if ($archiveOwner === '') {
			self::error($output, 'No archive account exists, so the search shows no Paperless documents. Name the account that owns the synchronized files with occ paperless_unified_search:setup --archive-account.');

			return self::FAILURE;
		}

		try {
			$response = $this->paperlessApi->searchDocuments($term, 1, (int)$limit);
		} catch (PaperlessQueryRejected $rejection) {
			self::error($output, sprintf('Paperless refused the query (HTTP %d): it can\'t read it in its advanced syntax, as a range of dates that isn\'t complete, such as created:[2026 to.', (int)$rejection->status));

			return self::FAILURE;
		} catch (Throwable $error) {
			// An answer of Paperless that isn't the documents, or a request without answer.
			$exception = $error instanceof PaperlessRequestException ? $error : PaperlessRequestException::unanswered($error);
			self::requestFailed($output, $this->messages, $exception, [$this->configService->getToken(), $term]);

			return self::FAILURE;
		}

		// The explainer leaves out an ID that stands for no document, such as 0, as the
		// search does.
		/** @var array<int, string> $titles by document ID, in the order of Paperless */
		$titles = [];
		foreach (array_slice($response['results'], 0, (int)$limit) as $hit) {
			if (isset($hit['id']) && is_numeric($hit['id'])) {
				$titles[(int)$hit['id']] = isset($hit['title']) && is_string($hit['title']) ? trim($hit['title']) : '';
			}
		}

		$explain = $input->getOption('explain') === true;
		[$explanations, $fileError] = $explain
			? $this->explainer->explain($user, array_keys($titles), $archiveOwner)
			: $this->explainer->verdicts($user, array_keys($titles), $archiveOwner);

		if ($input->getOption('json') === true) {
			$output->writeln((string)json_encode([
				'user' => $user->getUID(),
				'term' => $term,
				'count' => $response['count'],
				'documents' => array_map(
					static fn (Explanation $explanation): array => ['title' => $titles[$explanation->documentId] ?? ''] + $explanation->jsonSerialize(),
					$explanations,
				),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
		} else {
			$this->table($output, $explanations, $titles, $user);
			$this->summarize($output, $explanations, $response['count'], $term, $user, $archiveOwner, $explain);
		}

		if ($fileError !== null) {
			self::errors($output)->writeln(OutputFormatter::escape(sprintf(
				'Nextcloud failed to read a file (%s), so a document without a file above may have one.',
				$fileError::class,
			)));
		}

		return self::SUCCESS;
	}

	/**
	 * @param list<Explanation> $explanations
	 * @param array<int, string> $titles
	 */
	private function table(OutputInterface $output, array $explanations, array $titles, IUser $user): void {
		if ($explanations === []) {
			return;
		}

		$table = new Table($output);
		$table->setHeaders(['ID', 'Title', 'Verdict', 'File']);
		foreach ($explanations as $explanation) {
			$table->addRow(array_map([OutputFormatter::class, 'escape'], [
				(string)$explanation->documentId,
				$titles[$explanation->documentId] ?? '',
				self::LABELS[$explanation->verdict->value],
				$this->fileOf($explanation, $user),
			]));
		}
		$table->render();
	}

	/**
	 * The file that decides the verdict, with whom it belongs to when that matters.
	 */
	private function fileOf(Explanation $explanation, IUser $user): string {
		return match ($explanation->verdict) {
			Verdict::Writable => sprintf('%s of %s, %s may write it', $explanation->path, $explanation->owner, $user->getUID()),
			Verdict::NotShared => sprintf('%s of %s, not shared with %s', $explanation->path, $explanation->owner, $user->getUID()),
			Verdict::NotOwned => sprintf('%s of %s', $explanation->path, $explanation->owner !== '' ? $explanation->owner : 'no account'),
			default => $explanation->path,
		};
	}

	/**
	 * @param list<Explanation> $explanations
	 */
	private function summarize(OutputInterface $output, array $explanations, int $count, string $term, IUser $user, string $archiveOwner, bool $explained): void {
		$shown = count(array_filter($explanations, static fn (Explanation $explanation): bool => $explanation->verdict === Verdict::Shown));
		$output->writeln(OutputFormatter::escape(sprintf(
			'Paperless found %d document(s) for "%s"; %d of the %d above show to %s.',
			$count,
			$term,
			$shown,
			count($explanations),
			$user->getUID(),
		)));
		if ($shown === count($explanations)) {
			return;
		}

		if ($explained) {
			$this->explainTheOthers($output, $explanations, $user, $archiveOwner);
		} else {
			$output->writeln('Add --explain to see why the others don\'t.');
		}
	}

	/**
	 * Why the documents that don't show to the user don't, and what corrects it.
	 *
	 * @param list<Explanation> $explanations
	 */
	private function explainTheOthers(OutputInterface $output, array $explanations, IUser $user, string $archiveOwner): void {
		// What each reason means and what corrects it, with the documents that it hides:
		// %1$s is the user, %2$s the archive account, %3$s the IDs and %4$s the first marker.
		$reasons = [
			[Verdict::Writable, 'Writable (%3$s): %1$s may change the file of the archive account %2$s, or add files to its folder, so anyone with that share could have given it the marker of the document. Share it read-only with %1$s.'],
			[Verdict::NotShared, 'Not shared (%3$s): the archive account %2$s has the file, but hasn\'t shared it with %1$s. Share the file, or a folder that holds it, read-only with %1$s.'],
			[Verdict::NotOwned, 'Not owned (%3$s): %1$s sees a file with the marker of the document, but only the files of the archive account %2$s stand for documents.'],
			[Verdict::NoFile, 'No file (%3$s): no file of the archive account %2$s has the marker of the document, such as %4$s, in its name. The synchronization hasn\'t written it yet, or names files without it.'],
		];
		foreach ($reasons as [$verdict, $reason]) {
			$documentIds = array_map(
				static fn (Explanation $explanation): int => $explanation->documentId,
				array_values(array_filter($explanations, static fn (Explanation $explanation): bool => $explanation->verdict === $verdict)),
			);
			if ($documentIds !== []) {
				$output->writeln(OutputFormatter::escape(sprintf($reason, $user->getUID(), $archiveOwner, implode(', ', $documentIds), '[P' . $documentIds[0] . ']')));
			}
		}
	}
}
