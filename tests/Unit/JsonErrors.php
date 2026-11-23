<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use JsonException;

/**
 * The message that the running PHP gives for a body that is no JSON. The app shows it as
 * a detail after its own words, and its wording is PHP's: 8.6 added the location, such as
 * "Syntax error near location 1:1" where 8.5 said "Syntax error" (#513). A test that
 * takes it from here keeps an exact comparison on every PHP, without writing the wording
 * of one version into the test.
 */
trait JsonErrors {
	private static function jsonError(string $body): string {
		try {
			json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			return $error->getMessage();
		}

		self::fail('The body is JSON: ' . $body);
	}
}
