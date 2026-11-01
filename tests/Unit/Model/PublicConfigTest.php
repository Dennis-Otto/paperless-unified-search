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
			// Users may choose to include Paperless in every search of their own.
			'personalAlwaysSearch' => true,
			'tokenWarnings' => [],
			'newDocumentAlerts' => false,
		], (new PublicConfig('https://paperless.example.com', true, false))->jsonSerialize());
	}

	public function testTheConfigurationTellsWhetherUsersMayAlwaysSearchPaperless(): void {
		$config = new PublicConfig('https://paperless.example.com', true, false, personalAlwaysSearch: false);

		self::assertFalse($config->jsonSerialize()['personalAlwaysSearch']);
		self::assertTrue((new PublicConfig('https://paperless.example.com', true, false, newDocumentAlerts: true))->jsonSerialize()['newDocumentAlerts']);
	}

	public function testTheConfigurationNamesTheVersionOfPaperless(): void {
		$config = new PublicConfig('https://paperless.example.com', true, false, paperlessVersion: PaperlessVersion::of('3.3.0', '10'));

		self::assertSame('3.3.0', $config->jsonSerialize()['paperlessVersion']);
		self::assertSame('10', $config->jsonSerialize()['paperlessApiVersion']);
	}
}
