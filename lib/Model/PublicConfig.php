<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use JsonSerializable;

final class PublicConfig implements JsonSerializable {
	public function __construct(
		public readonly string $url,
		public readonly bool $tokenConfigured,
		public readonly bool $alwaysSearch,
	) {
	}

	/**
	 * @return array{url: string, tokenConfigured: bool, alwaysSearch: bool}
	 */
	public function jsonSerialize(): array {
		return [
			'url' => $this->url,
			'tokenConfigured' => $this->tokenConfigured,
			'alwaysSearch' => $this->alwaysSearch,
		];
	}
}
