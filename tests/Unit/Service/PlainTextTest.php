<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Service\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlainTextTest extends TestCase {
	public function testEveryRunOfWhiteSpaceIsOneSpace(): void {
		self::assertSame('ACME Energy GmbH', PlainText::oneLine("  ACME\n\tEnergy \u{00A0} GmbH \r\n"));
		self::assertSame('', PlainText::oneLine(" \n "));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function markup(): array {
		return [
			'a tag' => ['<script>alert(1)</script>', '< script>alert(1)< /script>'],
			'a tag in capitals' => ['<IMG src=x>', '< IMG src=x>'],
			'a comment' => ['<!-- note -->', '< !-- note -->'],
			'an instruction' => ['<?php', '< ?php'],
			'a name in angle brackets' => ['Harbor & Sons <Insurance>', 'Harbor & Sons < Insurance>'],
			// Before a space, a digit or another character, a browser shows < as text.
			'a comparison' => ['Total < 5 EUR, 3<4', 'Total < 5 EUR, 3<4'],
			'no markup' => ['Northstar Energy', 'Northstar Energy'],
		];
	}

	#[DataProvider('markup')]
	public function testNoTagOfHtmlIsLeft(string $text, string $expected): void {
		self::assertSame($expected, PlainText::withoutMarkup($text));
	}
}
