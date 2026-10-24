<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use JsonSerializable;

final class PublicConfig implements JsonSerializable {
	/**
	 * @param bool $tokenUnreadable a token is stored that can't be decrypted, such as after
	 *                              the secret of config.php changed, and has to be entered again
	 * @param bool $archiveOwnerMissing the archive account in use, the one of the settings or
	 *                                  of Paperless Sync, doesn't exist
	 * @param bool $allowLocal Nextcloud may reach this Paperless at an address of the local
	 *                         network, which it refuses to other requests
	 * @param ?PaperlessVersion $paperlessVersion the version of this Paperless and of its API,
	 *                                            as its last answer named them
	 * @param bool $personalAlwaysSearch users may include Paperless in every search of their
	 *                                   own, in their personal settings
	 */
	public function __construct(
		public readonly string $url,
		public readonly bool $tokenConfigured,
		public readonly bool $alwaysSearch,
		public readonly string $archiveOwner = '',
		public readonly string $syncAccount = '',
		public readonly bool $tokenUnreadable = false,
		public readonly bool $archiveOwnerMissing = false,
		public readonly bool $allowLocal = false,
		public readonly ?PaperlessVersion $paperlessVersion = null,
		public readonly bool $personalAlwaysSearch = true,
	) {
	}

	/**
	 * @return array{url: string, tokenConfigured: bool, alwaysSearch: bool, archiveOwner: string, syncAccount: string, tokenUnreadable: bool, archiveOwnerMissing: bool, allowLocal: bool, paperlessVersion: string, paperlessApiVersion: string, personalAlwaysSearch: bool}
	 */
	public function jsonSerialize(): array {
		return [
			'url' => $this->url,
			'tokenConfigured' => $this->tokenConfigured,
			'alwaysSearch' => $this->alwaysSearch,
			'archiveOwner' => $this->archiveOwner,
			'syncAccount' => $this->syncAccount,
			'tokenUnreadable' => $this->tokenUnreadable,
			'archiveOwnerMissing' => $this->archiveOwnerMissing,
			'allowLocal' => $this->allowLocal,
			// Empty while no answer of Paperless named them.
			'paperlessVersion' => $this->paperlessVersion?->version ?? '',
			'paperlessApiVersion' => $this->paperlessVersion?->apiVersion ?? '',
			'personalAlwaysSearch' => $this->personalAlwaysSearch,
		];
	}
}
