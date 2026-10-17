<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Service\FilePictures;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\IPreview;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class FilePicturesTest extends TestCase {
	/**
	 * A type of file of which Nextcloud draws previews, such as PDF once an administrator
	 * enabled them, shows its preview, as Nextcloud's search of files does.
	 */
	public function testAFileThatNextcloudDrawsShowsItsPreview(): void {
		self::assertSame(
			'https://cloud.example.com/index.php/core/preview?x=64&y=64&fileId=4711&mimeFallback=1',
			$this->pictures(previews: true)->urlOf($this->file('application/pdf')),
		);
	}

	public function testAnyOtherFileShowsTheIconOfItsType(): void {
		self::assertSame(
			'https://cloud.example.com/core/img/filetypes/application-pdf.svg',
			$this->pictures(previews: false)->urlOf($this->file('application/pdf')),
		);
	}

	public function testATypeWithoutIconShowsTheIconOfTheApp(): void {
		self::assertSame(
			'https://cloud.example.com/apps/paperless_unified_search/img/app.svg',
			$this->pictures(previews: false)->urlOf($this->file('application/x-unknown')),
		);
	}

	private function file(string $mimeType): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn(4711);
		$file->method('getMimetype')->willReturn($mimeType);

		return $file;
	}

	private function pictures(bool $previews): FilePictures {
		$previewManager = $this->createStub(IPreview::class);
		$previewManager->method('isMimeSupported')->willReturn($previews);

		$mimeTypeDetector = $this->createStub(IMimeTypeDetector::class);
		$mimeTypeDetector->method('mimeTypeIcon')->willReturnCallback(
			static fn (string $mimeType): string => $mimeType === 'application/pdf' ? '/core/img/filetypes/application-pdf.svg' : '',
		);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route, array $parameters): string {
				self::assertSame('core.Preview.getPreviewByFileId', $route);

				return 'https://cloud.example.com/index.php/core/preview?' . http_build_query($parameters);
			},
		);
		$urlGenerator->method('imagePath')->willReturnCallback(
			static fn (string $app, string $image): string => '/apps/' . $app . '/img/' . $image,
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.com' . $url);

		return new FilePictures($previewManager, $mimeTypeDetector, $urlGenerator);
	}
}
