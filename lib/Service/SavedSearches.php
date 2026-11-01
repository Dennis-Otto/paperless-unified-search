<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\AlertChoice;
use OCP\Config\IUserConfig;
use OCP\Config\ValueType;

/**
 * What each user wants to be notified about, as they chose it in their personal settings:
 * a preference of the user, stored only while they want something, which Nextcloud removes
 * with the account.
 */
final class SavedSearches {
	/** The preference of a user, a lazy value: only the settings and the job read it. */
	public const PREFERENCE = 'new_document_alerts';

	/** How many searches a user may save: each costs Paperless a request per new documents. */
	public const MOST_TERMS = 10;

	/** The most characters of a saved search, longer than any that a person types. */
	public const LONGEST_TERM = 500;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	public function of(string $userId): AlertChoice {
		return AlertChoice::fromStored($this->userConfig->getValueArray($userId, AppConstants::APP_ID, self::PREFERENCE, lazy: true));
	}

	public function choose(string $userId, AlertChoice $choice): void {
		if ($choice->isNothing()) {
			$this->userConfig->deleteUserConfig($userId, AppConstants::APP_ID, self::PREFERENCE);
		} else {
			$this->userConfig->setValueArray($userId, AppConstants::APP_ID, self::PREFERENCE, $choice->jsonSerialize(), lazy: true);
		}
	}

	/**
	 * The choice of each user who wants something, by the ID of the user, which PHP turns
	 * into a number in a key when it looks like one, such as 12345.
	 *
	 * @return array<array-key, AlertChoice>
	 */
	public function ofEveryone(): array {
		$choices = [];
		foreach ($this->userConfig->getValuesByUsers(AppConstants::APP_ID, self::PREFERENCE, ValueType::ARRAY) as $userId => $stored) {
			$choice = AlertChoice::fromStored($stored);
			if (!$choice->isNothing()) {
				$choices[$userId] = $choice;
			}
		}

		return $choices;
	}

	/**
	 * The searches that a user entered, each as one line, without blank ones and without
	 * one that repeats another.
	 *
	 * @param array<array-key, mixed> $entered
	 * @return list<string>
	 */
	public static function termsOf(array $entered): array {
		$terms = [];
		/** @psalm-suppress MixedAssignment Every term is checked before use. */
		foreach ($entered as $term) {
			$line = is_string($term) ? PlainText::oneLine($term) : '';
			if ($line !== '') {
				$terms[$line] = true;
			}
		}

		return array_map('strval', array_keys($terms));
	}
}
