<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Model;

use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use PHPUnit\Framework\TestCase;

final class PublicConfigTest extends TestCase {
	/**
	 * What the settings page reads: without an account, a problem or a version named, the
	 * configuration names none and warns of nothing.
	 */
	public function testAConfigurationOfAUrlAndATokenWarnsOfNothing(): void {
		self::assertSame([
			'url' => 'https://paperless.example.com',
			'tokenConfigured' => true,
			'alwaysSearch' => false,
			'archiveOwner' => '',
			'syncAccount' => '',
			'tokenUnreadable' => false,
			'archiveOwnerMissing' => false,
			'allowLocal' => false,
			'paperlessVersion' => '',
			'paperlessApiVersion' => '',
		], (new PublicConfig('https://paperless.example.com', true, false))->jsonSerialize());
	}

	public function testTheConfigurationNamesTheVersionOfPaperless(): void {
		$config = new PublicConfig('https://paperless.example.com', true, false, paperlessVersion: PaperlessVersion::of('3.3.0', '10'));

		self::assertSame('3.3.0', $config->jsonSerialize()['paperlessVersion']);
		self::assertSame('10', $config->jsonSerialize()['paperlessApiVersion']);
	}
}
