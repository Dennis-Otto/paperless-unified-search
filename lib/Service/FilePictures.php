<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\IPreview;
use OCP\IURLGenerator;

/**
 * The picture of the file of a document, which a result of the search and an entry of
 * the dashboard show: both link the same preview, which the browser loads only once.
 */
final class FilePictures {
	/**
	 * The size of the preview of a file in pixels: twice the size at which Nextcloud's
	 * search shows it, for screens of twice the density, and about twice the size of an
	 * entry of the dashboard.
	 */
	public const PREVIEW_SIZE = 64;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IPreview $previewManager,
		private IMimeTypeDetector $mimeTypeDetector,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The picture of a file, as an absolute URL, which the apps for phones load as well:
	 * its preview, for a type of file of which Nextcloud draws previews, as its search of
	 * files shows it, or else the icon of the type; the icon of the app only when Nextcloud
	 * has none. Should a preview fail, Nextcloud answers with the icon of the type.
	 */
	public function urlOf(File $file): string {
		$mimeType = $file->getMimetype();
		if ($this->previewManager->isMimeSupported($mimeType)) {
			return $this->urlGenerator->linkToRouteAbsolute('core.Preview.getPreviewByFileId', [
				'x' => self::PREVIEW_SIZE,
				'y' => self::PREVIEW_SIZE,
				'fileId' => $file->getId(),
				'mimeFallback' => true,
			]);
		}

		$icon = $this->mimeTypeDetector->mimeTypeIcon($mimeType);
		if ($icon === '') {
			$icon = $this->urlGenerator->imagePath(AppConstants::APP_ID, 'app.svg');
		}

		return $this->urlGenerator->getAbsoluteURL($icon);
	}
}
