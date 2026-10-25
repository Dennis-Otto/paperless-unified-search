<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Command;

use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Model\TokenWarning;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * How the commands of occ name a problem: on the error output, with what to correct, and
 * with the error itself for -v, without the token and the term.
 */
trait ReportsProblems {
	/**
	 * The error output of occ, where a problem goes, so that the standard output of
	 * --json holds only JSON.
	 */
	private static function errors(OutputInterface $output): OutputInterface {
		return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
	}

	private static function error(OutputInterface $output, string $message): void {
		self::errors($output)->writeln(sprintf('<error>%s</error>', OutputFormatter::escape($message)));
	}

	/**
	 * Says why the settings don't allow a request to Paperless: a token that can't be
	 * decrypted, or no connection at all.
	 */
	private static function notConfigured(OutputInterface $output, ConfigService $configService): void {
		self::error($output, $configService->isTokenUnreadable()
			? 'The stored API token can\'t be decrypted, as after a move or a restore of Nextcloud with another secret in config.php. Enter it again with occ paperless_unified_search:setup.'
			: 'Paperless Unified Search isn\'t connected to Paperless yet. Connect it with occ paperless_unified_search:setup.');
	}

	/**
	 * Warns, with what to change, when the account of the API token may do more than the
	 * search needs, or less than the results show; the command still passes.
	 *
	 * @param list<TokenWarning> $warnings
	 */
	private static function warnAboutTheToken(OutputInterface $output, SettingsMessages $messages, array $warnings): void {
		foreach ($warnings as $warning) {
			$output->writeln('<comment>Warning:</comment> ' . OutputFormatter::escape($messages->forTokenWarning($warning)));
		}
	}

	/**
	 * Says what failed in a request to Paperless and what to correct, as the settings page
	 * does, and with -v the error itself.
	 *
	 * @param list<string> $secrets the token and the term, which the error must not show
	 */
	private static function requestFailed(OutputInterface $output, SettingsMessages $messages, PaperlessRequestException $exception, array $secrets): void {
		self::error($output, $messages->forFailure($exception));
		if ($exception->reason === RequestFailure::LocalAddress) {
			self::errors($output)->writeln('The option --allow-local of occ paperless_unified_search:setup allows this Paperless, and only this one, at an address of the local network.');
		}

		$cause = $exception->getPrevious() ?? $exception;
		self::errors($output)->writeln(
			OutputFormatter::escape($exception->reason->value . ', ' . $cause::class . ': ' . SearchDiagnostics::describe($cause->getMessage(), $secrets)),
			OutputInterface::VERBOSITY_VERBOSE,
		);
	}
}
