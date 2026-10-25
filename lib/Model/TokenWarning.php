<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

/**
 * What the account of the API token may do in Paperless beyond what the search needs, or
 * may not do of what the results show, as Paperless names it in its answer to
 * /api/ui_settings/. The search only reads: it needs to view documents, and to view
 * correspondents and document types for their names in the results. A token that may do
 * more lets whoever gets hold of it do the same; one that may do less shows results
 * without the names. Each is a warning, never an error: the search works either way.
 */
enum TokenWarning: string {
	/** A superuser of Paperless, who may change and delete every document. */
	case Superuser = 'superuser';
	/** A staff account of Paperless, which may also read the logs of Paperless. */
	case Staff = 'staff';
	/**
	 * An account that may add, change or delete documents, or that may give itself or a
	 * new account of its own that permission.
	 */
	case ChangesDocuments = 'changes_documents';
	/** An account that may not view correspondents, so results show without them. */
	case HidesCorrespondents = 'hides_correspondents';
	/** An account that may not view document types, so results show without them. */
	case HidesDocumentTypes = 'hides_document_types';

	/**
	 * The permissions of Django on documents beyond viewing them, as Paperless names them
	 * without the label of their app, and the ones that lead to them: Paperless-ngx keeps
	 * an account that may add or change users or change groups only from making users
	 * superusers or staff, so it may grant its own account, its own group or a new account
	 * the permission to change documents.
	 */
	private const CHANGING_DOCUMENTS = ['add_document', 'change_document', 'delete_document', 'add_user', 'change_user', 'change_group'];

	/**
	 * The warnings of an answer of /api/ui_settings/: the user of the token, with whether it
	 * is a superuser or staff, and the permissions of the account and its groups, such as
	 * view_document, as Paperless-ngx 2 and 3 send them. A superuser may do everything,
	 * so it gets that warning alone.
	 *
	 * @return ?list<self> null when the answer doesn't say, as one without the user or the
	 *                     permissions of the account
	 */
	public static function of(mixed $uiSettings): ?array {
		// An answer that isn't an object says as little as an empty one.
		$uiSettings = is_array($uiSettings) ? $uiSettings : [];
		/** @psalm-suppress MixedAssignment The answer of Paperless, checked before use. */
		$user = $uiSettings['user'] ?? null;
		/** @psalm-suppress MixedAssignment The same. */
		$permissions = $uiSettings['permissions'] ?? null;
		if (!is_array($user) || !is_array($permissions)) {
			return null;
		}
		if (($user['is_superuser'] ?? false) === true) {
			return [self::Superuser];
		}

		// The permissions as the keys of a set, which only texts name.
		$granted = [];
		/** @psalm-suppress MixedAssignment Each permission is checked before use. */
		foreach ($permissions as $permission) {
			if (is_string($permission)) {
				$granted[$permission] = true;
			}
		}
		$warnings = [];
		if (($user['is_staff'] ?? false) === true) {
			$warnings[] = self::Staff;
		}
		if (array_intersect_key($granted, array_flip(self::CHANGING_DOCUMENTS)) !== []) {
			$warnings[] = self::ChangesDocuments;
		}
		if (!isset($granted['view_correspondent'])) {
			$warnings[] = self::HidesCorrespondents;
		}
		if (!isset($granted['view_documenttype'])) {
			$warnings[] = self::HidesDocumentTypes;
		}

		return $warnings;
	}

	/**
	 * The warnings that the app stored, as values separated by commas, without a value
	 * that no warning has any longer.
	 *
	 * @return list<self>
	 */
	public static function fromStored(string $stored): array {
		$warnings = [];
		foreach (explode(',', $stored) as $value) {
			$warning = self::tryFrom($value);
			if ($warning !== null) {
				$warnings[] = $warning;
			}
		}

		return $warnings;
	}

	/**
	 * The warnings as the app stores them.
	 *
	 * @param list<self> $warnings
	 */
	public static function toStored(array $warnings): string {
		return implode(',', array_map(static fn (self $warning): string => $warning->value, $warnings));
	}
}
