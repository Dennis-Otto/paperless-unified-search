<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Model;

use OCA\PaperlessUnifiedSearch\Model\PaperlessStatus;
use PHPUnit\Framework\TestCase;

final class PaperlessStatusTest extends TestCase {
	/**
	 * A status that names only the version and the number of the documents names no IDs, no
	 * warnings about the account of the token, and no refusal to say what it may do: the
	 * stored warnings stay as they are.
	 */
	public function testAStatusOfTheNumberAloneNamesNothingElse(): void {
		$status = new PaperlessStatus(null, 3);

		self::assertSame([null, 3, null, null, false], [$status->version, $status->documents, $status->documentIds, $status->tokenWarnings, $status->privilegesRefused]);
	}
}
