<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Model;

use OCA\PaperlessUnifiedSearch\Model\PaperlessVersion;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaperlessVersionTest extends TestCase {
	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function versions(): array {
		return [
			// Measured on the containers of both versions.
			'Paperless-ngx 2.20.13' => ['2.20.13', '9', false],
			'Paperless-ngx 3.3.0' => ['3.3.0', '10', true],
			'a prerelease of 3.0' => ['3.0.0-beta.1', '10', true],
			'a later major version' => ['10.1', '12', true],
			'blanks around' => [' 3.3.0 ', ' 10 ', true],
		];
	}

	/**
	 * Only Paperless 3 and later have the simple mode of the search, the parameter text;
	 * Paperless 2 ignores it and lists all documents.
	 */
	#[DataProvider('versions')]
	public function testOnlyPaperless3AndLaterSearchInASimpleMode(string $version, string $apiVersion, bool $simpleSearch): void {
		$paperless = PaperlessVersion::of($version, $apiVersion);

		self::assertNotNull($paperless);
		self::assertSame(trim($version), $paperless->version);
		self::assertSame(trim($apiVersion), $paperless->apiVersion);
		self::assertSame($simpleSearch, $paperless->hasSimpleSearch());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function valuesOfNoVersion(): array {
		return [
			'no version' => ['', '10'],
			'no version of the API' => ['3.3.0', ''],
			'a name' => ['latest', '10'],
			'markup, which the settings would show' => ['3.3.0<script>', '10'],
			'a version of the API with a point' => ['3.3.0', '10.1'],
			'too long' => ['3.3.0-' . str_repeat('a', 33), '10'],
			'a line break inside' => ["3.3.0\n4", '10'],
		];
	}

	#[DataProvider('valuesOfNoVersion')]
	public function testValuesThatAreNoVersionAreNone(string $version, string $apiVersion): void {
		self::assertNull(PaperlessVersion::of($version, $apiVersion));
	}

	/**
	 * Paperless names its versions in the headers X-Version and X-Api-Version of every
	 * answer to a request with a valid token.
	 */
	public function testTheVersionComesFromTheHeadersOfAnAnswer(): void {
		$response = $this->createStub(IResponse::class);
		$response->method('getHeader')->willReturnMap([
			['X-Version', '3.3.0'],
			['X-Api-Version', '10'],
		]);

		$version = PaperlessVersion::fromResponse($response);

		self::assertTrue($version?->equals(PaperlessVersion::of('3.3.0', '10')));
		self::assertFalse($version->equals(PaperlessVersion::of('3.3.0', '9')));
		self::assertFalse($version->equals(PaperlessVersion::of('3.3.1', '10')));
		self::assertFalse($version->equals(null));
	}
}
