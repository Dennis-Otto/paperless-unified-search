<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Model\TokenWarning;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class SettingsMessagesTest extends TestCase {
	/**
	 * Every warning about the account of the API token that the settings page and occ show
	 * has a message of its own, which says what to change.
	 */
	public function testEveryWarningAboutTheTokenHasAMessageOfItsOwn(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$messages = new SettingsMessages($l10n);

		$texts = array_map(static fn (TokenWarning $warning): string => $messages->forTokenWarning($warning), TokenWarning::cases());

		self::assertSame($texts, array_values(array_unique($texts)));
		self::assertSame(
			'The account of the API token may not view some correspondents, document types or tags in Paperless, which another user owns, so results show without them. Select them in their lists in Paperless and add the account under Permissions, View.',
			$messages->forTokenWarning(TokenWarning::HidesNamesOfOthers),
		);
	}
}
