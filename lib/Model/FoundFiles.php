<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use OCP\Files\File;
use Throwable;

/**
 * The files of the documents of a page of Paperless, as the file locator found them, why
 * it left out a file that failed to answer, and the files that it left out because the
 * user may write them.
 */
final class FoundFiles {
	/**
	 * @param array<int, File> $files by document ID, in the order of the IDs
	 * @param ?Throwable $error the error of the first file that failed to answer, if one did
	 * @param array<int, File> $writable the files of the archive account that the user sees
	 *                                   but may write, which stand for no document, by
	 *                                   document ID, of the documents without a file in $files
	 */
	public function __construct(
		public readonly array $files,
		public readonly ?Throwable $error = null,
		public readonly array $writable = [],
	) {
	}
}
