<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search;

use OCA\PaperlessUnifiedSearch\Search\PaperlessPager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaperlessPagerTest extends TestCase {
	/**
	 * @return array<string, array{int, int}>
	 */
	public static function startOffsets(): array {
		return [
			'the first page' => [0, 0],
			'a later hit' => [30, 30],
			'a negative number' => [-2, 0],
			'the largest integer' => [PHP_INT_MAX, PHP_INT_MAX],
		];
	}

	#[DataProvider('startOffsets')]
	public function testAPageStartsAtTheOffsetOfItsCursor(int $start, int $offset): void {
		self::assertSame($offset, PaperlessPager::at($start, 5)->offset);
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function limits(): array {
		return [
			'the first page of the web search' => [5, 5],
			'the most' => [50, 50],
			'more than the most' => [100, 50],
			'one' => [1, 1],
			'none' => [0, 1],
			'negative' => [-5, 1],
		];
	}

	#[DataProvider('limits')]
	public function testAPageHoldsBetweenOneAndFiftyResults(int $asked, int $limit): void {
		self::assertSame($limit, PaperlessPager::at(0, $asked)->limit);
	}

	/**
	 * @return array<string, array{int, int, list<int>}>
	 */
	public static function offsets(): array {
		$page = range(1, 25);

		return [
			'start of the first page' => [0, 1, $page],
			'within the first page' => [6, 1, range(7, 25)],
			'last hit of the first page' => [24, 1, [25]],
			'start of the second page' => [25, 2, $page],
			'within the second page' => [31, 2, range(7, 25)],
			'start of the last page that a search pages to' => [9975, 400, $page],
		];
	}

	/**
	 * @param list<int> $hits the hits of a full page from the offset on, by their place in the page
	 */
	#[DataProvider('offsets')]
	public function testTheOffsetChoosesThePageOfPaperlessAndItsFirstHit(int $offset, int $page, array $hits): void {
		$pager = PaperlessPager::at($offset, 5);

		self::assertSame($page, $pager->getPage());
		self::assertSame($hits, $pager->getHitsFromOffset(range(1, 25)));
	}

	public function testAShortPageHasNoHitsBeyondItsEnd(): void {
		self::assertSame([], PaperlessPager::at(10, 5)->getHitsFromOffset(range(1, 8)));
		self::assertSame([8], PaperlessPager::at(7, 5)->getHitsFromOffset(range(1, 8)));
	}

	/**
	 * Hits that Paperless sends beyond the size of a page belong to no offset of the pager.
	 */
	public function testHitsBeyondAPageAreLeftOut(): void {
		self::assertSame(range(21, 25), PaperlessPager::at(45, 5)->getHitsFromOffset(range(1, 40)));
	}

	public function testAdvancingGoesOnByTheHitsGoneThrough(): void {
		$pager = PaperlessPager::at(6, 10)->advance(4);

		self::assertSame(10, $pager->offset);
		self::assertSame(10, $pager->limit);
		self::assertSame(10, $pager->advance(-3)->offset);
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function nextPages(): array {
		return [
			'from the start of a page' => [0, 25],
			'from within a page' => [6, 25],
			'from the last hit of a page' => [24, 25],
			'from within the second page' => [31, 50],
		];
	}

	#[DataProvider('nextPages')]
	public function testTheNextPageStartsAtItsFirstHit(int $offset, int $next): void {
		$pager = PaperlessPager::at($offset, 15)->nextPage();

		self::assertSame($next, $pager->offset);
		self::assertSame(15, $pager->limit);
	}

	/**
	 * @return array<string, array{int, bool}>
	 */
	public static function ends(): array {
		return [
			'the first hit' => [0, false],
			'the last hit that a search pages to' => [9999, false],
			'the hit after it' => [10000, true],
			'far beyond' => [PHP_INT_MAX, true],
		];
	}

	#[DataProvider('ends')]
	public function testASearchPagesThroughTheFirstTenThousandHits(int $offset, bool $pastTheEnd): void {
		self::assertSame($pastTheEnd, PaperlessPager::at($offset, 5)->isPastTheEnd());
	}

	public function testTheNextPageAfterTheLastOneIsPastTheEnd(): void {
		self::assertTrue(PaperlessPager::at(9990, 5)->nextPage()->isPastTheEnd());
	}
}
