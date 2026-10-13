<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCP\IUser;
use OCP\IUserManager;

/**
 * The accounts of a Nextcloud, for the tests that need some.
 */
trait Accounts {
	/**
	 * Nextcloud's manager of the accounts with these IDs. Like Nextcloud, it finds an
	 * account whatever the case of the ID that it is asked for, and the account carries
	 * its ID as Nextcloud spells it.
	 */
	private function accounts(string ...$ids): IUserManager {
		$accounts = [];
		foreach ($ids as $id) {
			$account = $this->createStub(IUser::class);
			$account->method('getUID')->willReturn($id);
			$accounts[strtolower($id)] = $account;
		}

		$manager = $this->createStub(IUserManager::class);
		$manager->method('get')->willReturnCallback(
			static fn (string $id): ?IUser => $accounts[strtolower($id)] ?? null,
		);

		return $manager;
	}
}
