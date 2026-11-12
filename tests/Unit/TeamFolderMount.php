<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCP\Files\Mount\ISystemMountPoint;

/**
 * The mount of a Team folder, as the app groupfolders makes it: a system mount of the type
 * group, which tells the ID of its folder in a method of its own.
 */
abstract class TeamFolderMount implements ISystemMountPoint {
	abstract public function getFolderId(): int;
}
