<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Mount\ISystemMountPoint;
use OCP\IAppConfig;

/**
 * The Team folders and the external storages that the administrator trusts with the
 * archive (decision 0019): besides the files of the archive account, a file in one of them
 * stands for its document for each user who may only read it there. A Team folder or an
 * external storage that is mounted for several users has no account of its own that owns
 * its files, so the rule of the archive account (decision 0002) can't find them.
 *
 * Each is named by the number that Nextcloud shows for it, a Team folder as team:3 by the
 * ID that occ groupfolders:list shows, an external storage as external:7 by the mount ID
 * that occ files_external:list shows. Only a mount of the administrator counts: an
 * external storage that a user adds in their personal settings is no system mount, and
 * its user could write it outside Nextcloud.
 */
final class TrustedMounts {
	/** The setting that names them, separated by spaces, such as "team:3 external:7". */
	public const KEY = 'trusted_mounts';

	/** A Team folder by its ID, or an external storage by the ID of its mount. */
	private const NAME = '/^(?:team|external):[1-9][0-9]{0,9}$/';

	/** What separates the names in the setting, as an administrator may type it. */
	private const SEPARATORS = '/[\s,;]/';

	/** The type of the mounts of the app groupfolders, which names Team folders. */
	private const TEAM_FOLDER_TYPE = 'group';

	/**
	 * The types of the mounts of external storages: with stored credentials, or with those
	 * of the login of each user.
	 */
	private const EXTERNAL_STORAGE_TYPES = ['external', 'external-session'];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The trusted mounts, each once, in the order of the setting.
	 *
	 * @return list<string> such as team:3 and external:7
	 */
	public function names(): array {
		return self::parse($this->setting())[0];
	}

	/**
	 * The entries of the setting that name no Team folder and no external storage, which
	 * the search ignores, as occ config:app:set may have stored them.
	 *
	 * @return list<string>
	 */
	public function unknown(): array {
		return self::parse($this->setting())[1];
	}

	/**
	 * Stores the trusted mounts, none for an empty list.
	 *
	 * @param list<string> $names as parse() finds them
	 */
	public function save(array $names): void {
		if ($names === []) {
			$this->config->deleteKey(AppConstants::APP_ID, self::KEY);

			return;
		}

		$this->config->setValueString(AppConstants::APP_ID, self::KEY, implode(' ', $names));
	}

	/**
	 * The names of the mounts in a text, each once in its first place, and the entries that
	 * name none. A name doesn't depend on its case: Team:3 is team:3.
	 *
	 * @return array{list<string>, list<string>} the names, and the other entries
	 */
	public static function parse(string $text): array {
		$names = [];
		$unknown = [];
		foreach (preg_split(self::SEPARATORS, $text) ?: [] as $entry) {
			// Two separators in a row, or one at an end, leave an empty entry between them.
			if ($entry === '') {
				continue;
			}
			$name = strtolower($entry);
			if (preg_match(self::NAME, $name) === 1) {
				$names[$name] = $name;
			} else {
				$unknown[$entry] = $entry;
			}
		}

		return [array_values($names), array_values($unknown)];
	}

	/**
	 * The name of a mount by which an administrator can trust it, such as team:3 for a Team
	 * folder or external:7 for an external storage, and null for any other mount: a share,
	 * the folders of a user, or an external storage that a user added.
	 */
	public static function nameOf(?IMountPoint $mount): ?string {
		if (!$mount instanceof ISystemMountPoint) {
			return null;
		}

		$type = $mount->getMountType();
		if ($type === self::TEAM_FOLDER_TYPE) {
			// The app groupfolders numbers its Team folders, and its mounts tell the number,
			// in a method of their own that the public API of Nextcloud doesn't know.
			/** @psalm-suppress MixedAssignment A method of another app, checked before use. */
			$folderId = method_exists($mount, 'getFolderId') ? $mount->getFolderId() : null;

			return is_int($folderId) && $folderId > 0 ? 'team:' . $folderId : null;
		}

		$mountId = $mount->getMountId();

		return in_array($type, self::EXTERNAL_STORAGE_TYPES, true) && is_int($mountId) && $mountId > 0 ? 'external:' . $mountId : null;
	}

	private function setting(): string {
		return $this->config->getValueString(AppConstants::APP_ID, self::KEY, '');
	}
}
