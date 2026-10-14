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
	/** The archive account has a file of the document, but hasn't shared it with the user. */
	case NotShared = 'not_shared';
	/** The user sees a file with the marker of the document, but another account owns it. */
	case NotOwned = 'not_owned';
	/** No file of the archive account carries the marker of the document. */
	case NoFile = 'no_file';
}
