<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

/**
 * What a Paperless answered to the test of the connection: its version, the documents
 * that the account of the token may view, as their number and, where Paperless lists them
 * in the field all of its answer, as their IDs, and what the account may do beyond what
 * the search needs, or may not do of what the results show.
 */
final class PaperlessStatus {
	/**
	 * @param ?PaperlessVersion $version null when the answer names none
	 * @param int $documents the number of documents that the account of the token may view
	 * @param ?list<int> $documentIds the IDs of these documents, null when the answer has
	 *                                no list of them
	 * @param ?list<TokenWarning> $tokenWarnings the warnings about the account of the
	 *                                           token, null when Paperless doesn't say
	 *                                           what it may do
	 */
	public function __construct(
		public readonly ?PaperlessVersion $version,
		public readonly int $documents,
		public readonly ?array $documentIds = null,
		public readonly ?array $tokenWarnings = null,
	) {
	}
}
