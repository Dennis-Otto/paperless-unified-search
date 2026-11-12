<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Command;

use OCA\PaperlessUnifiedSearch\Service\TrustedMounts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_unified_search:trusted-mounts: the Team folders and the external storages
 * whose files stand for documents besides those of the archive account (decision 0019).
 */
final class TrustedMountsCommand extends Command {
	use ReportsProblems;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private TrustedMounts $trustedMounts,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_unified_search:trusted-mounts')
			->setDescription('List or set the Team folders and external storages that hold the archive')
			->setHelp(<<<'HELP'
				Besides the files of the archive account, a file with the marker of a document,
				such as [P412], stands for that document in a Team folder or an external storage
				that you trust here, for each user who may only read it there. Such a mount has no
				account of its own that owns its files, so the archive account can't name it.

				Name a Team folder by its ID, as occ groupfolders:list shows it, and an external
				storage by its mount ID, as occ files_external:list shows it. The names replace
				the stored ones:

				  occ paperless_unified_search:trusted-mounts team:3 external:7

				Without a name, the command lists the trusted mounts; --none trusts none again.

				Trust a mount only when nobody but the synchronization writes it, also outside
				Nextcloud: whoever may write it can give a file the marker of any document, and
				each user who may only read it then sees the title and the excerpt of that
				document. A user who may write it sees none of its documents. An external storage
				that a user adds in their personal settings counts never.

				Exits with 0, or with 2 for a name that is neither team:<ID> nor external:<ID>.
				HELP)
			->addArgument('mounts', InputArgument::IS_ARRAY, 'The trusted mounts, such as team:3 and external:7, which replace the stored ones')
			->addOption('none', null, InputOption::VALUE_NONE, 'Trust no Team folder and no external storage');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		/** @var list<string> $entries the arguments of an array, which the console checks */
		$entries = $input->getArgument('mounts');
		$none = $input->getOption('none') === true;
		if ($none && $entries !== []) {
			self::error($output, 'Name trusted mounts or pass --none, not both.');

			return self::INVALID;
		}

		if ($none || $entries !== []) {
			[$names, $unknown] = TrustedMounts::parse(implode(' ', $entries));
			if ($unknown !== []) {
				self::error($output, sprintf('Name a Team folder as team:<ID> and an external storage as external:<mount ID>, not %s.', implode(', ', $unknown)));

				return self::INVALID;
			}
			$this->trustedMounts->save($names);
		}

		$this->list($output);

		return self::SUCCESS;
	}

	private function list(OutputInterface $output): void {
		$names = $this->trustedMounts->names();
		$output->writeln($names === []
			? 'No Team folder and no external storage is trusted: only the files of the archive account stand for documents.'
			: OutputFormatter::escape(sprintf('Trusted mounts: %s. Their files stand for documents for each user who may only read them there.', implode(', ', $names))));

		$unknown = $this->trustedMounts->unknown();
		if ($unknown !== []) {
			self::error($output, sprintf('The setting %s also holds %s, which names no Team folder and no external storage and counts for nothing.', TrustedMounts::KEY, implode(', ', $unknown)));
		}
	}
}
