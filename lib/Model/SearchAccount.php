<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use JsonSerializable;

/**
 * The search account that the set-up created in Paperless, and the settings saved with its
 * token, which neither holds: what the settings page and occ report.
 */
final class SearchAccount implements JsonSerializable {
	/**
	 * @param string $username its user in Paperless
	 * @param bool $workflow whether a workflow lets it view each new document
	 * @param int $documents how many documents of Paperless it may view since the set-up
	 *                       added it to their permissions, 0 when it didn't
	 * @param int $names how many correspondents, document types and tags it may view since
	 *                   the set-up added it to their permissions, 0 when it didn't
	 * @param PublicConfig $config the settings, saved with its token
	 */
	public function __construct(
		public readonly string $username,
		public readonly bool $workflow,
		public readonly int $documents,
		public readonly int $names,
		public readonly PublicConfig $config,
	) {
	}

	/**
	 * The settings as the page shows them, with what the set-up did.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->config->jsonSerialize() + [
			'searchAccount' => [
				'username' => $this->username,
				'workflow' => $this->workflow,
				'documents' => $this->documents,
				'names' => $this->names,
			],
		];
	}
}
