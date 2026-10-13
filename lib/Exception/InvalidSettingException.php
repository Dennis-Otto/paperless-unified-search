<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Exception;

use InvalidArgumentException;

/**
 * A setting that can't be saved, with the problem that the settings name. Its message is
 * the English one; the settings show a translated message for its problem instead.
 */
final class InvalidSettingException extends InvalidArgumentException {
	public function __construct(
		public readonly SettingProblem $problem,
		string $message,
	) {
		parent::__construct($message);
	}
}
