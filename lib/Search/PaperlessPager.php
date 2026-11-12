<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search;

/**
 * Where a page of Nextcloud's search starts in the ranked hits of Paperless, and how many
 * results it holds at most.
 *
 * Paperless can't leave out the documents that the searching user can't open, so a page
 * of results and a page of Paperless rarely match: a page of results may take a part of a
 * page of Paperless or several of them. The cursor of a page is therefore the offset of
 * its first hit in the ranking of Paperless, the number of hits that the pages before it
 * went through, and Paperless is asked for pages of one fixed size. That offset counts
 * the documents that the user can't open, so Nextcloud and its apps get it only sealed
 * in a cursor (SearchCursors), which they send back as they got it.
 */
final class PaperlessPager {
	/** The hits of a page of Paperless. */
	public const PAGE_SIZE = 25;

	/** The most results that a page of the search holds. */
	public const MAX_LIMIT = 50;

	/** The number of hits that a search pages through; a cursor past them finds nothing. */
	public const MAX_OFFSET = 10000;

	/**
	 * @param int $offset the number of hits of Paperless that the pages before went through
	 * @param int $limit the most results that the page holds
	 */
	private function __construct(
		public readonly int $offset,
		public readonly int $limit,
	) {
	}

	/**
	 * The pager of a page of the search, from the offset that the cursor of the page
	 * before names, 0 for the first page, and the number of results that Nextcloud asks for.
	 */
	public static function at(int $offset, int $limit): self {
		return new self(max(0, $offset), max(1, min(self::MAX_LIMIT, $limit)));
	}

	/**
	 * Whether the offset lies past the hits that a search pages through.
	 */
	public function isPastTheEnd(): bool {
		return $this->offset >= self::MAX_OFFSET;
	}

	/**
	 * The page of Paperless, counted from 1, that holds the hit at the offset.
	 */
	public function getPage(): int {
		return intdiv($this->offset, self::PAGE_SIZE) + 1;
	}

	/**
	 * The hits of that page from the one at the offset on, since the pages before went
	 * through those in front of it; never more than a page, even when Paperless sends more.
	 *
	 * @template T
	 * @param list<T> $hits the hits of the page, in the order of Paperless
	 * @return list<T>
	 */
	public function getHitsFromOffset(array $hits): array {
		$skip = $this->offset % self::PAGE_SIZE;

		return array_slice($hits, $skip, self::PAGE_SIZE - $skip);
	}

	/**
	 * The pager $count hits further on.
	 */
	public function advance(int $count): self {
		return new self($this->offset + max(0, $count), $this->limit);
	}

	/**
	 * The pager at the first hit of the next page of Paperless.
	 */
	public function nextPage(): self {
		return new self($this->getPage() * self::PAGE_SIZE, $this->limit);
	}
}
