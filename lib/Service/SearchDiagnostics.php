<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\DiagnosticsHistory;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Throwable;

/**
 * Keeps the history of the problems of the search for the administration settings:
 * some hosters, such as managed Nextcloud offers, don't let administrators read the
 * log of Nextcloud. Only problems and the first search that works after a failure
 * write; searches that keep working read and write nothing but one value that Nextcloud
 * holds in memory anyway.
 */
final class SearchDiagnostics {
	private const HISTORY_KEY = 'diagnostics';
	/**
	 * Whether a failure of the history may wait for a search that works again: false when
	 * none does, and true or missing when one may. The history is lazy, and reading it
	 * loads the lazy values of every app; this value isn't, so that a search that works
	 * reads the history only while a failure may wait.
	 */
	private const PENDING_KEY = 'diagnostics_pending';
	private const MESSAGE_LENGTH = 300;
	// Shorter terms would blank out the letters of the message instead of the term.
	private const SHORTEST_SECRET = 3;
	/**
	 * The seconds in which problems of one kind write the history only once. During an
	 * outage every keystroke of every user fails, and every write of the configuration of
	 * the apps empties the cache of it for all of Nextcloud; the problems in between only
	 * count, in the distributed cache, until the next write takes them along. A search
	 * that works again doesn't end the interval, so that a Paperless that fails every
	 * other request writes one failure and one recovery in it, not one of each per request.
	 */
	private const WRITE_INTERVAL = 30;

	private ?ICache $cache = null;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $config,
		private ITimeFactory $timeFactory,
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * A search that failed and showed the user no documents, with the cause of a request
	 * to Paperless that failed, also of one that got no answer; an error of the files has none.
	 *
	 * @param list<string> $secrets the token, which the message must not show
	 * @param list<string> $terms the search terms, which the message must not show as words
	 */
	public function recordFailure(string $step, Throwable $error, int $durationMs, array $secrets, array $terms = []): void {
		$this->record(SearchEvent::KIND_FAILED, $step, $error, $durationMs, $secrets, $terms, self::causeOf($step, $error));
	}

	/**
	 * The cause of a failure: the one of the app, or, for a request to Paperless without an
	 * answer, the one that the error of the HTTP client names, as the test of the connection
	 * names it: a code of cURL or Nextcloud's refusal of the address, also inside another error.
	 */
	private static function causeOf(string $step, Throwable $error): ?PaperlessRequestException {
		if ($error instanceof PaperlessRequestException) {
			return $error;
		}

		if ($step !== SearchEvent::STEP_PAPERLESS) {
			return null;
		}

		for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof LocalServerException || preg_match('/\bcURL error \d+\b/', $cause->getMessage()) === 1) {
				return PaperlessRequestException::unanswered($error);
			}
		}

		return null;
	}

	/**
	 * A request to Paperless that got no answer, while its second try got one: its error is
	 * the one of the HTTP client, with the cause of a request without answer.
	 *
	 * @param list<string> $secrets the token, which the message must not show
	 * @param list<string> $terms the search terms, which the message must not show as words
	 */
	public function recordRetry(Throwable $error, int $durationMs, array $secrets, array $terms = []): void {
		$this->record(SearchEvent::KIND_RETRIED, SearchEvent::STEP_PAPERLESS, $error, $durationMs, $secrets, $terms, PaperlessRequestException::unanswered($error));
	}

	/**
	 * Notes the first search that works after a failure; every later one changes nothing.
	 * The mark goes first, and a failure that is saved after the recovery removes it
	 * again, so that the next search that works notes the recovery of that failure.
	 */
	public function recordSuccess(): void {
		if (!$this->mayAwaitRecovery()) {
			return;
		}

		$this->config->setValueBool(AppConstants::APP_ID, self::PENDING_KEY, false);
		$history = $this->getHistory();
		if ($history !== null && $history->awaitsRecovery()) {
			$this->save($this->withCounted($history)->recovered($this->timeFactory->getTime()));
		}
	}

	public function getHistory(): ?DiagnosticsHistory {
		return DiagnosticsHistory::fromArray($this->config->getValueArray(AppConstants::APP_ID, self::HISTORY_KEY, [], true));
	}

	public function clear(): void {
		$this->config->deleteKey(AppConstants::APP_ID, self::HISTORY_KEY);
		$this->config->setValueBool(AppConstants::APP_ID, self::PENDING_KEY, false);

		$cache = $this->getMemcache();
		foreach ([SearchEvent::KIND_FAILED, SearchEvent::KIND_RETRIED] as $kind) {
			$cache?->remove(self::guardKey($kind));
			$cache?->remove(self::countKey($kind));
		}
	}

	/**
	 * @param list<string> $secrets
	 * @param list<string> $terms
	 */
	private function record(string $kind, string $step, Throwable $error, int $durationMs, array $secrets, array $terms, ?PaperlessRequestException $cause): void {
		if (!$this->mayWrite($kind)) {
			return;
		}

		$time = $this->timeFactory->getTime();
		$event = new SearchEvent(
			$time,
			$kind,
			$step,
			self::shortClassName($error),
			self::describe($error->getMessage(), $secrets, $terms),
			max(0, $durationMs),
			$cause?->reason,
			$cause?->status,
		);

		$this->save($this->withCounted($this->getHistory() ?? DiagnosticsHistory::start($time))->with($event));
		if ($kind === SearchEvent::KIND_FAILED) {
			// After the history, so that a recovery saved meanwhile can't hide this failure,
			// and removed rather than set: a request that began before that recovery still
			// holds the mark as true, and Nextcloud would write no value that it holds.
			$this->config->deleteKey(AppConstants::APP_ID, self::PENDING_KEY);
		}
	}

	/**
	 * Whether a failure may wait for a search that works again: as the value of the app
	 * says, and when it has none, as after a failure or an update from 0.5.0, as the
	 * history says, which recordSuccess() reads once for it.
	 */
	private function mayAwaitRecovery(): bool {
		if (!$this->config->hasKey(AppConstants::APP_ID, self::PENDING_KEY)) {
			return true;
		}

		return $this->config->getValueBool(AppConstants::APP_ID, self::PENDING_KEY);
	}

	/**
	 * Whether this problem writes the history: the first of its kind within the interval
	 * does, and those after it only count. Without a cache that shares values between
	 * requests, every problem writes.
	 */
	private function mayWrite(string $kind): bool {
		$cache = $this->getMemcache();
		if ($cache === null || $cache->add(self::guardKey($kind), 1, self::WRITE_INTERVAL)) {
			return true;
		}

		$cache->inc(self::countKey($kind));

		return false;
	}

	/**
	 * The history with the problems that only counted since the last write.
	 */
	private function withCounted(DiagnosticsHistory $history): DiagnosticsHistory {
		return $history->counting($this->takeCount(SearchEvent::KIND_FAILED), $this->takeCount(SearchEvent::KIND_RETRIED));
	}

	/**
	 * The problems of a kind that only counted, which the cache then forgets; those that
	 * other requests count meanwhile stay for the next write.
	 */
	private function takeCount(string $kind): int {
		$cache = $this->getMemcache();
		/** @psalm-suppress MixedAssignment The cache holds what inc() left, checked below. */
		$count = $cache?->get(self::countKey($kind));
		if ($cache === null || !is_numeric($count) || (int)$count <= 0) {
			return 0;
		}

		$cache->dec(self::countKey($kind), (int)$count);

		return (int)$count;
	}

	/**
	 * The distributed cache of Nextcloud, or else its local one, when it can add and
	 * count; null when Nextcloud has none.
	 */
	private function getMemcache(): ?IMemcache {
		$this->cache ??= $this->cacheFactory->createDistributed(AppConstants::APP_ID);

		return $this->cache instanceof IMemcache ? $this->cache : null;
	}

	private static function guardKey(string $kind): string {
		return 'diagnostics-written-' . $kind;
	}

	private static function countKey(string $kind): string {
		return 'diagnostics-counted-' . $kind;
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
	 * The message of an error without the token, the term and the query of a URL, in
	 * which Guzzle and cURL repeat the term, as the history and the log may show it. The
	 * token and the term go in any case, also of letters beyond ASCII, Ärger for ärger as
	 * well, by the simple case folding of Unicode, which leaves STRASSE for straße; and
	 * after the rest is cleaned up, so that removing a part of the message can't join one
	 * of them together. The token goes wherever the message holds it; a term only where it
	 * stands as a word of its own and not in the words that cURL puts before its number,
	 * so that a search for time keeps "timed out" and one for error "cURL error 28".
	 *
	 * @param list<string> $secrets the token and whatever else goes wherever it stands
	 * @param list<string> $terms the search terms, which go where they stand as words
	 */
	public static function describe(string $message, array $secrets, array $terms = []): string {
		// Bytes that aren't UTF-8, as an error may repeat them, would fail the patterns.
		$message = mb_scrub($message, 'UTF-8');
		// cURL points to the page of its error codes, which says nothing about this one.
		$message = preg_replace('~\s*\(see https?://[^\s)]*\)~u', '', $message) ?? '';
		$message = preg_replace('~(https?://[^\s?#`"\'<>]*)[?#][^\s`"\'<>]*~u', '$1', $message) ?? '';
		$message = self::collapseSpaces($message);

		$anywhere = self::longestFirst($secrets);
		foreach (self::longestFirst([...$secrets, ...$terms]) as $secret) {
			$pattern = in_array($secret, $anywhere, true) ? '/' . preg_quote($secret, '/') . '/iu' : self::termPattern($secret);
			$message = preg_replace($pattern, '…', $message) ?? '';
		}

		return mb_strimwidth(trim($message), 0, self::MESSAGE_LENGTH, '…', 'UTF-8');
	}

	/**
	 * The secrets as the message holds them after its clean-up, the longest first, so that
	 * a term that holds the token goes as a whole; without those too short to leave out.
	 *
	 * @param list<string> $secrets
	 * @return list<string>
	 */
	private static function longestFirst(array $secrets): array {
		$cleaned = [];
		foreach ($secrets as $secret) {
			$secret = trim(self::collapseSpaces(mb_scrub($secret, 'UTF-8')));
			if (mb_strlen($secret) >= self::SHORTEST_SECRET) {
				$cleaned[] = $secret;
			}
		}
		usort($cleaned, static fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

		return $cleaned;
	}

	/**
	 * The pattern of a term as a word of its own: an end of the term that is a letter, a
	 * mark or a digit may not continue a longer word, so time stays in "timed out" and
	 * error in "errors"; and never in the words "cURL error" and the number that cURL puts
	 * before its message, which hold no term.
	 *
	 * @return non-empty-string
	 */
	private static function termPattern(string $term): string {
		$word = '[\p{L}\p{M}\p{N}]';
		$before = preg_match('/^' . $word . '/u', $term) === 1 ? '(?<!' . $word . ')' : '';
		$after = preg_match('/' . $word . '$/u', $term) === 1 ? '(?!' . $word . ')' : '';

		return '/cURL error \d+:(*SKIP)(*FAIL)|' . $before . preg_quote($term, '/') . $after . '/iu';
	}

	private static function collapseSpaces(string $text): string {
		return preg_replace('/\s+/u', ' ', $text) ?? '';
	}
}
