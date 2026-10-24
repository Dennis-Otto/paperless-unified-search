<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCP\Config\IUserConfig;
use OCP\IUser;

/**
 * Whether a search of Nextcloud includes Paperless without the switch Search connected
 * services, which Nextcloud turns off on every page that loads: for every user while an
 * administrator trusts the Paperless server, and for each user who chose it in their
 * personal settings while the administrators let users choose it.
 */
final class AlwaysSearch {
	/**
	 * The preference of a user who chose to include Paperless in every search of their
	 * own. It is stored only while it is chosen.
	 */
	public const PREFERENCE = 'always_search';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * Whether every search of the user includes Paperless. Without a user, such as in occ
	 * or a background job, only the trusted-service mode of the administrators does.
	 */
	public function isOnFor(?IUser $user): bool {
		if ($this->configService->isAlwaysSearchEnabled()) {
			return true;
		}

		return $user !== null
			&& $this->configService->isPersonalAlwaysSearchAllowed()
			&& $this->isChosenBy($user->getUID());
	}

	/**
	 * Whether the user chose it, as stored, also while the administrators don't let users
	 * choose it and the search ignores it.
	 */
	public function isChosenBy(string $userId): bool {
		return $this->userConfig->getValueBool($userId, AppConstants::APP_ID, self::PREFERENCE);
	}

	public function choose(string $userId, bool $always): void {
		if ($always) {
			$this->userConfig->setValueBool($userId, AppConstants::APP_ID, self::PREFERENCE, true);
		} else {
			$this->userConfig->deleteUserConfig($userId, AppConstants::APP_ID, self::PREFERENCE);
		}
	}
}
