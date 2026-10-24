<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The preferences of the users in memory, as $this->preferences by "user.app.key", and
 * the session of the user who signed in.
 */
trait UserPreferences {
	/** @var array<string, bool> */
	private array $preferences = [];

	private function userConfig(): IUserConfig {
		$config = $this->createStub(IUserConfig::class);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $userId, string $app, string $key, bool $default = false): bool => $this->preferences[$userId . '.' . $app . '.' . $key] ?? $default,
		);
		$config->method('setValueBool')->willReturnCallback(function (string $userId, string $app, string $key, bool $value): bool {
			$this->preferences[$userId . '.' . $app . '.' . $key] = $value;

			return true;
		});
		$config->method('deleteUserConfig')->willReturnCallback(function (string $userId, string $app, string $key): void {
			unset($this->preferences[$userId . '.' . $app . '.' . $key]);
		});

		return $config;
	}

	/**
	 * The session of the user with the ID, or of nobody, as in occ and background jobs.
	 */
	private function session(?string $userId): IUserSession {
		$user = null;
		if ($userId !== null) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}
}
