<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search;

use Exception;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * The cursors of the pages of the search, sealed so that only the app can read or make
 * one (decision 0016).
 *
 * The offset of a page in the ranking of Paperless counts the documents that the
 * searching user can't open, so the cursor that goes back to Nextcloud and its apps holds
 * it encrypted and authenticated with a key of the instance, together with the user and
 * the question of the search that it belongs to. A user reads nothing from a cursor, and
 * a cursor that the app didn't hand out for this question of this user names no page.
 */
final class SearchCursors {
	/** The format of the sealed text, which a later format changes. */
	private const FORMAT = '1';

	/** The bytes of the hash of the user and the question in the sealed text. */
	private const QUESTION_LENGTH = 32;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ICrypto $crypto,
		private IConfig $config,
	) {
	}

	/**
	 * The cursor of the page of results that starts at the offset, for this user and this
	 * question, or none while the instance has no secret to seal it with.
	 *
	 * @param array<string, string> $filters the filters of Paperless of the search, such as its dates
	 */
	public function seal(int $offset, string $userId, string $term, array $filters): ?string {
		$key = $this->key();
		if ($key === null) {
			return null;
		}

		return $this->crypto->encrypt(self::FORMAT . self::question($userId, $term, $filters) . $offset, $key);
	}

	/**
	 * The offset of the page that a cursor names: 0 for the first page, which has no
	 * cursor, and none for a cursor that the app didn't hand out for this user and this
	 * question.
	 *
	 * @param array<string, string> $filters the filters of Paperless of the search, such as its dates
	 */
	public function open(int|string|null $cursor, string $userId, string $term, array $filters): ?int {
		// Nextcloud's apps and the API ask for the first page without a cursor, or with 0.
		if ($cursor === null || $cursor === '' || $cursor === 0 || $cursor === '0') {
			return 0;
		}

		$key = $this->key();
		if (!is_string($cursor) || $key === null) {
			return null;
		}

		// Nextcloud throws an exception for a text that its key didn't seal.
		try {
			$sealed = $this->crypto->decrypt($cursor, $key);
		} catch (Exception) {
			return null;
		}

		$format = substr($sealed, 0, 1);
		$question = substr($sealed, 1, self::QUESTION_LENGTH);
		$offset = substr($sealed, 1 + self::QUESTION_LENGTH);
		if ($format !== self::FORMAT || !hash_equals(self::question($userId, $term, $filters), $question) || !ctype_digit($offset)) {
			return null;
		}

		return (int)$offset;
	}

	/**
	 * The key of the cursors, made from the secret of the instance for this purpose alone.
	 * Never the empty one: with it, Nextcloud opens a text with the secret and, when that
	 * fails, with the empty key, with which anyone can seal a text.
	 */
	private function key(): ?string {
		$secret = $this->config->getSystemValueString('secret');

		return $secret === '' ? null : hash_hmac('sha256', AppConstants::APP_ID . ' search cursor', $secret);
	}

	/**
	 * The hash of the user and the question, of the same length for every question.
	 *
	 * @param array<string, string> $filters
	 */
	private static function question(string $userId, string $term, array $filters): string {
		return hash('sha256', serialize([$userId, $term, $filters]), true);
	}
}
