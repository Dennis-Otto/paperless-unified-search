<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\SearchFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Throwable;

/**
 * Remembers the last failed search and when searches worked again, for the
 * administration settings: some hosters, such as managed Nextcloud offers, don't let
 * administrators read the log of Nextcloud.
 */
final class SearchDiagnostics {
	private const FAILURE_KEY = 'last_failure';
	private const RECOVERY_KEY = 'last_recovery';
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
	 * @param list<string> $secrets the token and the term, which the message must not show
	 */
	public function recordFailure(string $step, Throwable $error, int $durationMs, array $secrets): void {
		$failure = new SearchFailure(
			$this->timeFactory->getTime(),
			$step,
			self::shortClassName($error),
			self::describe($error->getMessage(), $secrets),
			max(0, $durationMs),
		);

		$this->config->setValueArray(AppConstants::APP_ID, self::FAILURE_KEY, $failure->jsonSerialize(), true);
		$this->config->deleteKey(AppConstants::APP_ID, self::RECOVERY_KEY);
	}

	/**
	 * Notes the first search that works after a failure; every later one changes nothing.
	 */
	public function recordSuccess(): void {
		if ($this->getLastFailure() === null || $this->getRecoveredAt() !== null) {
			return;
		}

		$this->config->setValueInt(AppConstants::APP_ID, self::RECOVERY_KEY, $this->timeFactory->getTime(), true);
	}

	public function getLastFailure(): ?SearchFailure {
		return SearchFailure::fromArray($this->config->getValueArray(AppConstants::APP_ID, self::FAILURE_KEY, [], true));
	}

	/**
	 * When the first search after the last failure worked, or null while none has.
	 */
	public function getRecoveredAt(): ?int {
		$time = $this->config->getValueInt(AppConstants::APP_ID, self::RECOVERY_KEY, 0, true);

		return $time > 0 ? $time : null;
	}

	public function clear(): void {
		$this->config->deleteKey(AppConstants::APP_ID, self::FAILURE_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::RECOVERY_KEY);
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
