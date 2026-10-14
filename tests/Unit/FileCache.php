<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchQuery;

/**
 * The file cache of a Nextcloud, for the tests that look for files.
 */
trait FileCache {
	/** @var list<ISearchQuery> the searches of the file cache so far, oldest first */
	private array $searches = [];

	/**
	 * The folder /dennis/files of the user dennis, or of another user, with these files and
	 * folders in it or in the folders shared with the user. Its search answers a query as
	 * Nextcloud's file cache does, with the nodes whose names match it, and fails the test
	 * for a search by text, which Nextcloud runs as a query of its own for every call.
	 *
	 * @param list<Node> $nodes
	 */
	private function userFolder(array $nodes, string $uid = 'dennis'): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('search')->willReturnCallback(function (string|ISearchQuery $query) use ($nodes): array {
			self::assertInstanceOf(ISearchQuery::class, $query, 'The file cache was searched by text.');
			$this->searches[] = $query;

			return array_values(array_filter(
				$nodes,
				fn (Node $node): bool => $this->finds($query->getSearchOperation(), $node->getName()),
			));
		});
		$home = '/' . $uid . '/files';
		$folder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): ?string => str_starts_with($path, $home . '/') ? substr($path, strlen($home)) : null,
		);

		return $folder;
	}

	/**
	 * Whether the file cache finds a node of this name for the condition, one of several
	 * names or a name: the database compares names with LIKE regardless of their case,
	 * where % stands for any text and _ for one character.
	 */
	private function finds(ISearchOperator $operator, string $name): bool {
		if ($operator instanceof ISearchBinaryOperator) {
			self::assertSame(ISearchBinaryOperator::OPERATOR_OR, $operator->getType());
			// Nextcloud's own operators hold their conditions as a list.
			self::assertTrue(array_is_list($operator->getArguments()), 'The conditions of the search are no list.');
			foreach ($operator->getArguments() as $argument) {
				if ($this->finds($argument, $name)) {
					return true;
				}
			}

			return false;
		}

		self::assertInstanceOf(ISearchComparison::class, $operator);
		self::assertSame(ISearchComparison::COMPARE_LIKE, $operator->getType());
		self::assertSame('name', $operator->getField());
		$value = $operator->getValue();
		self::assertIsString($value);

		return preg_match('/^' . strtr(preg_quote($value, '/'), ['%' => '.*', '_' => '.']) . '$/is', $name) === 1;
	}
}
