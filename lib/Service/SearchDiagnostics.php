<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Throwable;

/**
 * Keeps the history of the problems of the search for the administration settings:
 * some hosters, such as managed Nextcloud offers, don't let administrators read the
 * log of Nextcloud. Only problems and the first search that works after a failure
 * write; searches that keep working write nothing.
 */
final class SearchDiagnostics {
	private const HISTORY_KEY = 'diagnostics';
	/** The keys of the last failure of version 0.3.0, which the history replaces. */
	private const LEGACY_KEYS = ['last_failure', 'last_recovery'];
	private const MESSAGE_LENGTH = 300;
	// Shorter terms would blank out the letters of the message instead of the term.
	private const SHORTEST_SECRET = 3;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $config,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * A search that failed and showed the user no documents.
	 *
	 * @param list<string> $secrets the token and the term, which the message must not show
	 */
	public function recordFailure(string $step, Throwable $error, int $durationMs, array $secrets): void {
		$this->record(SearchEvent::KIND_FAILED, $step, $error, $durationMs, $secrets);
	}

	/**
	 * A request to Paperless that got no answer, while its second try got one.
	 *
	 * @param list<string> $secrets the token and the term, which the message must not show
	 */
	public function recordRetry(Throwable $error, int $durationMs, array $secrets): void {
		$this->record(SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS, $error, $durationMs, $secrets);
	}

	/**
	 * Notes the first search that works after a failure; every later one changes nothing.
	 */
	public function recordSuccess(): void {
		$history = $this->getHistory();
		if ($history === null || !$history->awaitsRecovery()) {
			return;
		}

		$this->save($history->recovered($this->timeFactory->getTime()));
	}

	public function getHistory(): ?DiagnosticsHistory {
		return DiagnosticsHistory::fromArray($this->config->getValueArray(AppConstants::APP_ID, self::HISTORY_KEY, [], true));
	}

	public function clear(): void {
		foreach ([self::HISTORY_KEY, ...self::LEGACY_KEYS] as $key) {
			$this->config->deleteKey(AppConstants::APP_ID, $key);
		}
	}

	/**
	 * @param list<string> $secrets
	 */
	private function record(string $kind, string $step, Throwable $error, int $durationMs, array $secrets): void {
		$time = $this->timeFactory->getTime();
		$event = new SearchEvent(
			$time,
			$kind,
			$step,
			self::shortClassName($error),
			self::describe($error->getMessage(), $secrets),
			max(0, $durationMs),
		);

		$this->save(($this->getHistory() ?? DiagnosticsHistory::start($time))->with($event));
	}

	private function save(DiagnosticsHistory $history): void {
		$this->config->setValueArray(AppConstants::APP_ID, self::HISTORY_KEY, $history->jsonSerialize(), true);
	}

	private static function shortClassName(Throwable $error): string {
		$class = $error::class;
		$separator = strrpos($class, '\\');

		return $separator === false ? $class : substr($class, $separator + 1);
	}

	/**
	 * The message of the error without the token, the term and the query of a URL, in
	 * which Guzzle and cURL repeat the term.
	 *
	 * @param list<string> $secrets
	 */
	private static function describe(string $message, array $secrets): string {
		foreach ($secrets as $secret) {
			if (mb_strlen($secret) >= self::SHORTEST_SECRET) {
				$message = str_ireplace($secret, '…', $message);
			}
		}

		// cURL points to the page of its error codes, which says nothing about this one.
		$message = preg_replace('~\s*\(see https?://[^\s)]*\)~', '', $message) ?? '';
		$message = preg_replace('~(https?://[^\s?#`"\'<>]*)[?#][^\s`"\'<>]*~', '$1', $message) ?? '';
		$message = preg_replace('/\s+/u', ' ', $message) ?? '';

		return mb_strimwidth(trim($message), 0, self::MESSAGE_LENGTH, '…', 'UTF-8');
	}
}
