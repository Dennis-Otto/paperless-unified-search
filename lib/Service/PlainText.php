<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

/**
 * Text of Paperless, such as the name of a correspondent or the excerpt of a document,
 * as a line that the search and the dashboard show: Nextcloud's web pages show it as
 * text, and no other client may take a part of it for a tag of HTML either.
 */
final class PlainText {
	/**
	 * The start of a tag of HTML: < before a letter, /, ! or ?. Before anything else, a
	 * browser shows < as text.
	 */
	private const TAG_START = '/<(?=[a-z\/!?])/i';

	/**
	 * The text with every run of white space, line breaks too, as one space, and none at
	 * either end.
	 */
	public static function oneLine(string $text): string {
		return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
	}

	/**
	 * The text with a space after every < that would start a tag, such as "< GmbH>" for
	 * "<GmbH>", so that it holds no tag of HTML, whoever shows it.
	 */
	public static function withoutMarkup(string $text): string {
		return preg_replace(self::TAG_START, '< ', $text) ?? '';
	}
}
