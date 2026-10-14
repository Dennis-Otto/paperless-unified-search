<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use JsonSerializable;

/**
 * Whether a document shows to a user in the search, and why not, with the file that
 * decides it: the one that the user opens, the one of the archive account that isn't
 * shared, or the one with the marker that another account owns.
 */
final class Explanation implements JsonSerializable {
	/**
	 * @param string $path the path of the file in the folders of the user, or of the archive
	 *                     account for a file that isn't shared; '' without a file
	 * @param string $owner the account that owns the file, '' when none is known
	 */
	public function __construct(
		public readonly int $documentId,
		public readonly Verdict $verdict,
		public readonly string $path = '',
		public readonly string $owner = '',
	) {
	}

	/**
	 * @return array{id: int, verdict: string, path: string, owner: string}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->documentId,
			'verdict' => $this->verdict->value,
			'path' => $this->path,
			'owner' => $this->owner,
		];
	}
}
