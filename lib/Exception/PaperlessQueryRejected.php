<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Exception;

use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;

/**
 * An answer of Paperless that refuses what a user searches for, while Paperless itself
 * works: HTTP 400 with the errors of the field query, which Paperless 3 answers to a query
 * in its advanced syntax that it can't parse, such as the range of dates in
 * created:[2026 to that is still being typed, or to one longer than 4096 characters; and
 * HTTP 404 for a page past the last one. Nextcloud searches while the user types, so the
 * search finds nothing for it, and the history of the problems of the search doesn't
 * count it.
 */
final class PaperlessQueryRejected extends PaperlessRequestException {
	/**
	 * @param int $status 400 or 404
	 * @param ?PaperlessVersion $version the version that the answer names, null without one
	 */
	public function __construct(
		int $status,
		public readonly ?PaperlessVersion $version = null,
	) {
		parent::__construct(
			$status === 404 ? RequestFailure::NotFound : RequestFailure::Http,
			'Paperless rejected the query with HTTP ' . $status . '.',
			$status,
		);
	}

	/**
	 * Whether the simple mode of the search of this Paperless may take the term instead: it
	 * refused the query as one of its advanced syntax, and it is Paperless 3 or later.
	 */
	public function allowsSimpleSearch(): bool {
		return $this->status === 400 && $this->version !== null && $this->version->hasSimpleSearch();
	}
}
