<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The class of Nextcloud behind OCP\Util::addStyle(), which the package of its public API doesn't
 * have: it keeps the styles of the page, for the tests to read.
 */
final class OC_Util {
	/** @var list<string> the styles of the page, as app/file */
	public static array $styles = [];

	public static function addStyle(string $application, ?string $file = null, bool $prepend = false): void {
		self::$styles[] = $application . '/' . (string)$file;
	}
}
