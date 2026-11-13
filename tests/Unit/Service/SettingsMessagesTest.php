<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;
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

	/**
	 * HTTP 406 refuses the version of the API that a request asked for: version 9 only a
	 * Paperless older than 2.16, and version 10, which the app asks for once Paperless named
	 * it, a Paperless that is newer than the app.
	 */
	public function testARefusedVersionOfTheApiSaysWhichSideIsTooOld(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$messages = new SettingsMessages($l10n);

		$older = PaperlessRequestException::forStatus(406);
		$newer = PaperlessRequestException::forStatus(406, PaperlessVersion::NEWEST_API);

		self::assertSame(RequestFailure::UnsupportedVersion, PaperlessRequestException::forStatus(406, PaperlessVersion::OLDEST_API)->reason);
		self::assertSame([RequestFailure::UnsupportedVersion, 406], [$older->reason, $older->status]);
		self::assertSame([RequestFailure::NewerApi, 406], [$newer->reason, $newer->status]);
		self::assertSame(RequestFailure::Http, PaperlessRequestException::forStatus(500, PaperlessVersion::NEWEST_API)->reason);
		self::assertSame('Paperless is older than 2.16 and does not speak version 9 of its API (HTTP 406). Update Paperless-ngx.', $messages->forFailure($older));
		self::assertSame('Paperless no longer speaks version 10 of its API, the newest that this app knows (HTTP 406). Update Paperless Unified Search.', $messages->forFailure($newer));
	}
}
