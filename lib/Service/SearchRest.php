<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCP\Http\Client\LocalServerException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether the search leaves a Paperless alone for a while after a request to it got no
 * answer (decision 0018): a request that timed out, whose connection failed or whose host
 * didn't resolve. Such a request holds a PHP worker of Nextcloud for 6 to 10 seconds, and
 * the search asks after every pause of typing, also with Paperless in every search of
 * Nextcloud, so that during an outage a few users who type would use up the workers of
 * all of Nextcloud. While it rests, the search answers with no results at once; its
 * failure is in the history of the diagnostics already.
 *
 * Nextcloud's distributed cache keeps the rest for every user, by a hash of the URL of
 * Paperless. A cache that fails keeps no rest, and without a distributed cache, which
 * keeps nothing, every search asks Paperless, as before. An answer of Paperless, whatever
 * its status, makes no rest: Paperless answers, so the search costs no long wait. A test
 * of the connection that Paperless answers ends the rest at once, as a save of the
 * settings and occ paperless_unified_search:test or :check run it (end()).
 */
final class SearchRest {
	/** The seconds that the search rests after a request without answer. */
	private const TTL = 30;

	/** The failures of a request that got no answer of Paperless at all. */
	private const UNANSWERED = [RequestFailure::Timeout, RequestFailure::Unreachable, RequestFailure::UnknownHost];

	private ?ICache $cache = null;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the search rests for the Paperless at this URL; not when the cache fails.
	 */
	public function isResting(string $url): bool {
		try {
			return $this->getCache()->get(self::key($url)) === true;
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Rests for the Paperless at this URL when this failure of a request to it got no
	 * answer. A cache that fails keeps no rest, and the next search asks Paperless again.
	 */
	public function restAfter(string $url, Throwable $failure): void {
		if (!self::isUnanswered($failure)) {
			return;
		}

		try {
			$this->getCache()->set(self::key($url), true, self::TTL);
		} catch (Throwable $error) {
			$this->logger->warning('The search could not rest after Paperless gave no answer, so the next search asks it again ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);
		}
	}

	/**
	 * Ends the rest for the Paperless at this URL, which has just answered. A cache that
	 * fails only leaves the rest until it expires.
	 */
	public function end(string $url): void {
		try {
			$this->getCache()->remove(self::key($url));
		} catch (Throwable $error) {
			$this->logger->warning('The rest of the search could not be ended, so the search may find nothing for up to 30 seconds more ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);
		}
	}

	/**
	 * Whether a failure got no answer of Paperless: an error of the request itself, which
	 * names a code of cURL or is Nextcloud's refusal of a name without a record of DNS, or a
	 * failure of the app that names no answer either, also inside another error. Any other
	 * failure, such as an error of the app after Paperless answered, makes no rest.
	 */
	private static function isUnanswered(Throwable $failure): bool {
		for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof PaperlessRequestException) {
				return in_array($cause->reason, self::UNANSWERED, true);
			}

			if ($cause instanceof LocalServerException || preg_match('/\bcURL error \d+\b/', $cause->getMessage()) === 1) {
				return in_array(PaperlessRequestException::unanswered($cause)->reason, self::UNANSWERED, true);
			}
		}

		return false;
	}

	private function getCache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed(AppConstants::APP_ID);
	}

	private static function key(string $url): string {
		return 'search-resting-' . hash('sha256', $url);
	}
}
