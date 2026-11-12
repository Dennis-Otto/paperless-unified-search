<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

/**
 * Whether a document that Paperless found shows to a user in the search, and if not, why:
 * the search itself only knows that no file of the archive account stands for it among the
 * files of the user, and occ explains that further.
 */
enum Verdict: string {
	/** A file of the archive account that the user can open stands for the document. */
	case Shown = 'shown';
	/** The search leaves the document out, for a reason that occ names with --explain. */
	case Hidden = 'hidden';
	/**
	 * The user sees a file of the archive account with the marker of the document, but may
	 * change it or add files to its folder, through a share with write permission, and so
	 * could have given it the marker.
	 */
	case Writable = 'writable';
	/**
	 * The user sees a file with the marker of the document in a Team folder or an external
	 * storage that the administrator trusts with the archive (decision 0019), but may change
	 * it there or add files to its folder, and so could have given it the marker.
	 */
	case WritableMount = 'writable_mount';
	/**
	 * The archive account, or the user of the owner of the document, has a file of the
	 * document, but hasn't shared it with the user.
	 */
	case NotShared = 'not_shared';
	/** The user sees a file with the marker of the document, but another account owns it. */
	case NotOwned = 'not_owned';
	/**
	 * The user sees a file with the marker of the document of a user of Nextcloud, while
	 * Paperless names another owner of the document, whose user's files alone stand for it
	 * besides those of the archive account (decision 0013).
	 */
	case OtherOwner = 'other_owner';
	/** No file of the archive account carries the marker of the document. */
	case NoFile = 'no_file';
	/**
	 * Paperless Sync lets the user see only the documents with some tags, and the document
	 * has none of them in Paperless (decision 0015): no file stands for it, whoever has one.
	 */
	case NotTagged = 'not_tagged';
}
