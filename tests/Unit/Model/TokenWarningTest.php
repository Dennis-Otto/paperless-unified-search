<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Model;

use OCA\PaperlessUnifiedSearch\Model\TokenWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The warnings about the account of the API token, from the answer of /api/ui_settings/ of
 * Paperless, whose user names is_superuser and is_staff, and whose permissions are the
 * codenames of Django without the label of their app, those of the groups of the account
 * among them.
 */
final class TokenWarningTest extends TestCase {
	/** What the search needs: to view documents, correspondents and document types. */
	private const VIEWS = ['view_document', 'view_correspondent', 'view_documenttype'];

	/**
	 * @return array<string, array{array<string, mixed>, list<TokenWarning>}>
	 */
	public static function answers(): array {
		$user = ['id' => 3, 'username' => 'nextcloud', 'is_staff' => false, 'is_superuser' => false, 'groups' => []];

		return [
			'an account that may view what the search needs' => [['user' => $user, 'permissions' => self::VIEWS], []],
			'one that may also view tags and its UI settings' => [['user' => $user, 'permissions' => [...self::VIEWS, 'view_tag', 'view_uisettings']], []],
			// A superuser may do everything, so the other warnings would only repeat it.
			'a superuser' => [['user' => ['is_staff' => true, 'is_superuser' => true] + $user, 'permissions' => []], [TokenWarning::Superuser]],
			'a staff account' => [['user' => ['is_staff' => true] + $user, 'permissions' => self::VIEWS], [TokenWarning::Staff]],
			'one that may add documents' => [['user' => $user, 'permissions' => [...self::VIEWS, 'add_document']], [TokenWarning::ChangesDocuments]],
			'one that may change documents' => [['user' => $user, 'permissions' => ['change_document', ...self::VIEWS]], [TokenWarning::ChangesDocuments]],
			'one that may delete documents' => [['user' => $user, 'permissions' => [...self::VIEWS, 'delete_document']], [TokenWarning::ChangesDocuments]],
			// Changing a tag or a note changes no document of the search.
			'one that may change tags and notes' => [['user' => $user, 'permissions' => [...self::VIEWS, 'change_tag', 'add_note']], []],
			'one that may not view correspondents' => [['user' => $user, 'permissions' => ['view_document', 'view_documenttype']], [TokenWarning::HidesCorrespondents]],
			'one that may not view document types' => [['user' => $user, 'permissions' => ['view_document', 'view_correspondent']], [TokenWarning::HidesDocumentTypes]],
			'one that may view documents alone' => [['user' => $user, 'permissions' => ['view_document']], [TokenWarning::HidesCorrespondents, TokenWarning::HidesDocumentTypes]],
			'a staff account that may change documents and view nothing else' => [
				['user' => ['is_staff' => true] + $user, 'permissions' => ['change_document']],
				[TokenWarning::Staff, TokenWarning::ChangesDocuments, TokenWarning::HidesCorrespondents, TokenWarning::HidesDocumentTypes],
			],
			// Only true makes a superuser or staff, and only a text names a permission.
			'a user without the flags' => [['user' => [], 'permissions' => self::VIEWS], []],
			'flags that are no booleans' => [['user' => ['is_superuser' => 'true', 'is_staff' => 1], 'permissions' => self::VIEWS], []],
			'permissions that are no texts' => [['user' => $user, 'permissions' => [1, ['add_document'], ...self::VIEWS]], []],
		];
	}

	/**
	 * @param array<string, mixed> $answer
	 * @param list<TokenWarning> $warnings
	 */
	#[DataProvider('answers')]
	public function testTheAnswerOfPaperlessNamesTheWarnings(array $answer, array $warnings): void {
		self::assertSame($warnings, TokenWarning::of($answer));
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function answersThatDoNotSay(): array {
		return [
			'no JSON object' => ['Forbidden'],
			'no user' => [['permissions' => self::VIEWS]],
			'a user that is no object' => [['user' => 'nextcloud', 'permissions' => self::VIEWS]],
			'no permissions' => [['user' => ['is_superuser' => true]]],
			'permissions that are no list' => [['user' => ['is_superuser' => true], 'permissions' => 'view_document']],
			'the answer to a refusal' => [['detail' => 'You do not have permission to perform this action.']],
		];
	}

	/**
	 * An answer without the user or its permissions says nothing, not even of a superuser.
	 */
	#[DataProvider('answersThatDoNotSay')]
	public function testAnAnswerWithoutTheUserAndItsPermissionsSaysNothing(mixed $answer): void {
		self::assertNull(TokenWarning::of($answer));
	}

	public function testTheWarningsAreStoredAsTheirValues(): void {
		self::assertSame('staff,hides_document_types', TokenWarning::toStored([TokenWarning::Staff, TokenWarning::HidesDocumentTypes]));
		self::assertSame('', TokenWarning::toStored([]));
	}

	/**
	 * A value that a later version of the app no longer knows drops out.
	 */
	public function testTheStoredWarningsComeBackWithoutAnUnknownValue(): void {
		self::assertSame(
			[TokenWarning::ChangesDocuments, TokenWarning::HidesCorrespondents],
			TokenWarning::fromStored('retired,changes_documents,hides_correspondents'),
		);
		self::assertSame([], TokenWarning::fromStored(''));
	}
}
