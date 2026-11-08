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
 * that couldn't remove the user, the workflow or both again: what remains stays in
 * Paperless, without settings that use it, until an administrator deletes it there. The
 * failure of the set-up is the previous exception.
 */
final class SearchAccountUnfinished extends RuntimeException {
	/**
	 * @param bool $userRemains whether the user remains in Paperless
	 * @param ?string $workflow the name of the workflow that remains in Paperless, if one does
	 */
	public function __construct(
		public readonly string $username,
		public readonly bool $userRemains,
		public readonly ?string $workflow,
		Throwable $failure,
	) {
		$remains = array_filter([$userRemains ? 'the user ' . $username : null, $workflow !== null ? 'the workflow "' . $workflow . '"' : null]);
		parent::__construct('The set-up of the search account ' . $username . ' failed, and ' . implode(' and ', $remains) . ' remain' . (count($remains) === 1 ? 's' : '') . ' in Paperless.', previous: $failure);
	}
}
