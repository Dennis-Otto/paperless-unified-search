<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search\Files;

use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/**
 * A search of the file cache of a user for every file that meets a condition, in all of
 * the user's folders and in those shared with the user, in no particular order.
 * Nextcloud's public API has only the interface; the class of Nextcloud's own searches is
 * private, so the app brings its own.
 */
final class SearchQuery implements ISearchQuery {
	public function __construct(
		private ISearchOperator $operation,
		private IUser $user,
	) {
	}

	public function getSearchOperation(): ISearchOperator {
		return $this->operation;
	}

	/**
	 * No limit: the files of a page of documents are few.
	 */
	public function getLimit(): int {
		return 0;
	}

	public function getOffset(): int {
		return 0;
	}

	/**
	 * @return list<ISearchOrder>
	 */
	public function getOrder(): array {
		return [];
	}

	public function getUser(): IUser {
		return $this->user;
	}

	/**
	 * The files that others shared with the user count as well.
	 */
	public function limitToHome(): bool {
		return false;
	}

	/**
	 * No columns beyond those of every search.
	 *
	 * @return list<string>
	 */
	public function getSelectFields(): array {
		return [];
	}
}
