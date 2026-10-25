<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use OCP\Constants;

/**
 * A share of a file or folder of the archive account: with whom, of which path, and what
 * it allows. Whoever may write into the archive can give a file of the archive account
 * the marker of any document.
 */
final class ArchiveShare {
	/** A share with a user of Nextcloud. */
	public const KIND_USER = 'user';
	/** A share with a group, whose members all receive it. */
	public const KIND_GROUP = 'group';
	/** A share with a team of the app Teams, formerly Circles. */
	public const KIND_TEAM = 'team';
	/** A share with a conversation of Talk, a board of Deck or another server. */
	public const KIND_OTHER = 'other';
	/** A public link, also one sent by mail, which needs no account of Nextcloud. */
	public const KIND_LINK = 'link';

	/**
	 * @param self::KIND_* $kind
	 * @param string $recipient the ID of the user or the group, the name of the team or the
	 *                          recipient of another kind, and none of a public link
	 * @param string $path the path in the folders of the archive account, such as /Paperless
	 * @param int $permissions the permissions of Nextcloud, such as Constants::PERMISSION_READ
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $recipient,
		public readonly string $path,
		public readonly int $permissions,
	) {
	}

	/**
	 * Whether the recipients may write into the shared file or folder: create files in it
	 * or change them, and so give a file the marker of any document. Deleting a file names
	 * none, so a share that allows only that is no reason for a warning.
	 */
	public function allowsChanges(): bool {
		return ($this->permissions & (Constants::PERMISSION_CREATE | Constants::PERMISSION_UPDATE)) !== 0;
	}
}
