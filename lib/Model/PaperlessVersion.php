<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use OCP\Http\Client\IResponse;

/**
 * The version of Paperless-ngx and the one of its REST API, such as 3.3.0 and 10, as
 * Paperless names them in the headers X-Version and X-Api-Version of every answer to a
 * request with a valid token.
 */
final class PaperlessVersion {
	/**
	 * A version as Paperless writes it, such as 2.20.13, 3.3.0 or 3.0.0-beta.1: the
	 * settings show it, so it is no more than that. $ with D ends at the end of the text,
	 * not before a line break at its end.
	 */
	private const VERSION = '/^\d{1,4}(?:\.\d{1,4}){0,3}(?:[-+][0-9A-Za-z.-]{1,32})?$/D';
	private const API_VERSION = '/^\d{1,4}$/D';
	/**
	 * The first major version whose search has a simple mode, the parameter text, which
	 * takes any input. Paperless 2 ignores the parameter and lists all documents.
	 */
	private const SIMPLE_SEARCH_SINCE = 3;
	/**
	 * The first version that checks the document of more_like_id. Before it, Paperless
	 * answers an unknown ID with the error of a search that failed.
	 */
	private const CHECKS_SIMILAR_DOCUMENT_SINCE = '2.20.13';

	private function __construct(
		public readonly string $version,
		public readonly string $apiVersion,
	) {
	}

	/**
	 * The version of these values, or null when either isn't one.
	 */
	public static function of(string $version, string $apiVersion): ?self {
		$version = trim($version);
		$apiVersion = trim($apiVersion);
		if (preg_match(self::VERSION, $version) !== 1 || preg_match(self::API_VERSION, $apiVersion) !== 1) {
			return null;
		}

		return new self($version, $apiVersion);
	}

	/**
	 * The version that an answer of Paperless names, or null when it names none, as an
	 * answer without a valid token does.
	 */
	public static function fromResponse(IResponse $response): ?self {
		return self::of($response->getHeader('X-Version'), $response->getHeader('X-Api-Version'));
	}

	/**
	 * Whether the search of this Paperless has a simple mode, which searches the words of
	 * any input in the titles and the texts of the documents.
	 */
	public function hasSimpleSearch(): bool {
		return (int)$this->version >= self::SIMPLE_SEARCH_SINCE;
	}

	/**
	 * Whether this Paperless checks the document that a search for similar ones names, and
	 * refuses it in its own words when it doesn't have it or the account may not view it.
	 */
	public function checksSimilarDocument(): bool {
		return version_compare($this->version, self::CHECKS_SIMILAR_DOCUMENT_SINCE, '>=');
	}

	public function equals(?self $other): bool {
		return $other !== null && $other->version === $this->version && $other->apiVersion === $this->apiVersion;
	}
}
