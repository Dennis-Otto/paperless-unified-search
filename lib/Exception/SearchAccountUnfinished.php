<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Exception;

use RuntimeException;
use Throwable;

/**
 * A set-up of the search account that failed after Paperless had added its user, and
 * that couldn't remove the user again: the user stays in Paperless, without settings
 * that use it, until an administrator deletes it there. The failure of the set-up is the
 * previous exception.
 */
final class SearchAccountUnfinished extends RuntimeException {
	public function __construct(
		public readonly string $username,
		Throwable $failure,
	) {
		parent::__construct('The set-up of the search account ' . $username . ' failed, and the user remains in Paperless.', previous: $failure);
	}
}
