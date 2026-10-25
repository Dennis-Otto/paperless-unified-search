<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use Throwable;

/**
 * The shares of the files and folders of the archive account, which decide who finds
 * which document, and why the list may lack some.
 */
final class ShareOverview {
	/** The kinds of shares in the order in which a summary names them. */
	public const KINDS = [
		ArchiveShare::KIND_USER,
		ArchiveShare::KIND_GROUP,
		ArchiveShare::KIND_TEAM,
		ArchiveShare::KIND_OTHER,
		ArchiveShare::KIND_LINK,
	];

	/**
	 * @param list<ArchiveShare> $shares by path, kind and recipient
	 * @param ?Throwable $error the error of the first kind of shares that failed to answer,
	 *                          whose shares the list lacks
	 */
	public function __construct(
		public readonly array $shares,
		public readonly ?Throwable $error = null,
	) {
	}

	/**
	 * With how many recipients of each kind the archive account shares files, each once
	 * however many of its files it shares with them, and how many public links it has, in
	 * the order of KINDS, without the kinds that have none.
	 *
	 * @return array<ArchiveShare::KIND_*, int>
	 */
	public function counts(): array {
		/** @var array<string, array<array-key, true>> $recipients by kind */
		$recipients = [];
		foreach ($this->shares as $index => $share) {
			// A public link has no recipient: each link counts on its own.
			$recipients[$share->kind][$share->kind === ArchiveShare::KIND_LINK ? $index : $share->recipient] = true;
		}

		$counts = [];
		foreach (self::KINDS as $kind) {
			if (isset($recipients[$kind])) {
				$counts[$kind] = count($recipients[$kind]);
			}
		}

		return $counts;
	}

	/**
	 * The shares that allow changes, in their order.
	 *
	 * @return list<ArchiveShare>
	 */
	public function writable(): array {
		return array_values(array_filter($this->shares, static fn (ArchiveShare $share): bool => $share->allowsChanges()));
	}
}
