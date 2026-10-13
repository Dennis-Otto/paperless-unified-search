<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search\Files;

use OCA\PaperlessUnifiedSearch\Search\Files\SearchComparison;
use OCP\Files\Search\ISearchComparison;
use PHPUnit\Framework\TestCase;

final class SearchComparisonTest extends TestCase {
	public function testIsAConditionOnAColumnOfTheFileCache(): void {
		$comparison = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%[P412]%');

		self::assertSame(ISearchComparison::COMPARE_LIKE, $comparison->getType());
		self::assertSame('name', $comparison->getField());
		self::assertSame('%[P412]%', $comparison->getValue());
		self::assertSame('', $comparison->getExtra());
	}

	/**
	 * Nextcloud's query optimizer leaves hints for the database query, such as whether to
	 * compare a path by its hash.
	 */
	public function testKeepsTheHintsOfTheQueryOptimizer(): void {
		$comparison = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%[P412]%');

		self::assertTrue($comparison->getQueryHint(ISearchComparison::HINT_PATH_EQ_HASH, true));

		$comparison->setQueryHint(ISearchComparison::HINT_PATH_EQ_HASH, false);

		self::assertFalse($comparison->getQueryHint(ISearchComparison::HINT_PATH_EQ_HASH, true));
	}

	/**
	 * Nextcloud's query optimizer groups conditions by their text, which is that of
	 * Nextcloud's own class.
	 */
	public function testReadsAsNextcloudsOwnCondition(): void {
		self::assertSame('name like "%[P412]%"', (string)new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%[P412]%'));
	}
}
