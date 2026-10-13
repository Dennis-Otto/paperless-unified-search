<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search\Files;

use OCA\PaperlessUnifiedSearch\Search\Files\SearchBinaryOperator;
use OCA\PaperlessUnifiedSearch\Search\Files\SearchComparison;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use PHPUnit\Framework\TestCase;

final class SearchBinaryOperatorTest extends TestCase {
	public function testJoinsItsConditions(): void {
		$first = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%[P1]%');
		$second = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%[P2]%');

		$operator = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, [$first, $second]);

		self::assertSame(ISearchBinaryOperator::OPERATOR_OR, $operator->getType());
		self::assertSame([$first, $second], $operator->getArguments());
	}

	public function testKeepsTheHintsOfTheQueryOptimizer(): void {
		$operator = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, []);

		self::assertSame('none', $operator->getQueryHint('hint', 'none'));

		$operator->setQueryHint('hint', 'set');

		self::assertSame('set', $operator->getQueryHint('hint', 'none'));
	}

	/**
	 * Nextcloud's query optimizer groups conditions by their text, which is that of
	 * Nextcloud's own class.
	 */
	public function testReadsAsNextcloudsOwnOperator(): void {
		$first = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%[P1]%');
		$second = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%[P2]%');

		self::assertSame(
			'(name like "%[P1]%" or (not name like "%[P2]%"))',
			(string)new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, [
				$first,
				new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_NOT, [$second]),
			]),
		);
	}

	/**
	 * A condition of another class without a text of its own reads as its class.
	 */
	public function testAConditionWithoutTextReadsAsItsClass(): void {
		$condition = $this->createStub(ISearchOperator::class);

		self::assertSame(
			'(' . $condition::class . ' and ' . $condition::class . ')',
			(string)new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_AND, [$condition, $condition]),
		);
	}
}
