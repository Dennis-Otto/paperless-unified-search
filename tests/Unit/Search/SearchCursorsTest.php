<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Search\SearchCursors;
use OCA\PaperlessUnifiedSearch\Tests\Unit\KeyedCrypto;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchCursorsTest extends TestCase {
	private const SECRET = 'secret of the instance';

	private const FILTERS = ['created__date__gte' => '2026-08-01'];

	/**
	 * @return array<string, array{int}>
	 */
	public static function offsets(): array {
		return [
			'the first hit' => [0],
			'within the first page of Paperless' => [6],
			'the last hit that a search pages to' => [9999],
		];
	}

	#[DataProvider('offsets')]
	public function testACursorNamesItsOffsetForTheSameUserAndQuestion(int $offset): void {
		$cursors = $this->cursors();

		$cursor = $cursors->seal($offset, 'dennis', 'invoice', self::FILTERS);

		self::assertIsString($cursor);
		self::assertSame($offset, $cursors->open($cursor, 'dennis', 'invoice', self::FILTERS));
	}

	/**
	 * @return array<string, array{int|string|null}>
	 */
	public static function firstPages(): array {
		return [
			'no cursor' => [null],
			'empty text' => [''],
			'zero' => [0],
			'zero as text' => ['0'],
		];
	}

	/**
	 * The first page has no cursor, and Nextcloud's apps and the API ask for it with 0 too.
	 */
	#[DataProvider('firstPages')]
	public function testTheFirstPageNeedsNoCursor(int|string|null $cursor): void {
		self::assertSame(0, $this->cursors()->open($cursor, 'dennis', 'invoice', []));
		self::assertSame(0, $this->cursors('')->open($cursor, 'dennis', 'invoice', []));
	}

	/**
	 * @return array<string, array{int|string}>
	 */
	public static function strangeCursors(): array {
		return [
			'an offset' => [30],
			'an offset as text' => ['30'],
			'a negative offset' => [-2],
			'a negative offset as text' => ['-3'],
			'past the end' => ['10000'],
			'text that is no cursor' => ['next'],
			'a cursor without its seal' => [base64_encode('1' . str_repeat('x', 32) . '30')],
		];
	}

	/**
	 * A user can't name a page by its offset, which would count the documents that the
	 * user can't open in front of it.
	 */
	#[DataProvider('strangeCursors')]
	public function testACursorThatTheAppDidNotHandOutNamesNoPage(int|string $cursor): void {
		self::assertNull($this->cursors()->open($cursor, 'dennis', 'invoice', []));
	}

	/**
	 * @return array<string, array{string, string, array<string, string>}>
	 */
	public static function otherQuestions(): array {
		return [
			'another user' => ['sam', 'invoice', self::FILTERS],
			'another term' => ['dennis', 'invoices', self::FILTERS],
			'another span of time' => ['dennis', 'invoice', ['created__date__gte' => '2026-07-01']],
			'without the span of time' => ['dennis', 'invoice', []],
		];
	}

	/**
	 * A cursor belongs to the user and the question of its search: neither another user nor
	 * another question pages with it.
	 *
	 * @param array<string, string> $filters
	 */
	#[DataProvider('otherQuestions')]
	public function testACursorNamesNoPageOfAnotherUserOrQuestion(string $userId, string $term, array $filters): void {
		$cursors = $this->cursors();
		$cursor = $cursors->seal(30, 'dennis', 'invoice', self::FILTERS);
		self::assertIsString($cursor);

		self::assertNull($cursors->open($cursor, $userId, $term, $filters));
	}

	public function testACursorOfAnotherInstanceNamesNoPage(): void {
		$cursor = $this->cursors('secret of another instance')->seal(30, 'dennis', 'invoice', []);
		self::assertIsString($cursor);

		self::assertNull($this->cursors()->open($cursor, 'dennis', 'invoice', []));
	}

	public function testAChangedCursorNamesNoPage(): void {
		$cursors = $this->cursors();
		$cursor = $cursors->seal(30, 'dennis', 'invoice', []);
		self::assertIsString($cursor);

		self::assertNull($cursors->open(substr($cursor, 0, -1) . (str_ends_with($cursor, '0') ? '1' : '0'), 'dennis', 'invoice', []));
	}

	/**
	 * Nextcloud opens a text that its secret doesn't open with the empty key as well, so the
	 * key of the cursors is neither the empty one nor the secret itself, but one of its own.
	 */
	public function testNeitherTheEmptyKeyNorTheSecretSealsACursor(): void {
		$cursors = $this->cursors();
		$sealed = '1' . self::question('dennis', 'invoice', []) . '30';
		$crypto = new KeyedCrypto();

		self::assertNull($cursors->open($crypto->encrypt($sealed, ''), 'dennis', 'invoice', []));
		self::assertNull($cursors->open($crypto->encrypt($sealed, self::SECRET), 'dennis', 'invoice', []));
		self::assertSame(30, $cursors->open($crypto->encrypt($sealed, self::key()), 'dennis', 'invoice', []));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function sealedTexts(): array {
		$question = self::question('dennis', 'invoice', []);

		return [
			'another format' => ['2' . $question . '30'],
			'no format' => [$question . '30'],
			'a shorter question' => ['1' . substr($question, 1) . '30'],
			'no offset' => ['1' . $question],
			'a negative offset' => ['1' . $question . '-30'],
			'an offset with a fraction' => ['1' . $question . '3.5'],
			'an offset after something else' => ['1' . $question . 'x30'],
		];
	}

	/**
	 * Only the text that the app seals for a page names one, whatever else a key of the
	 * cursors sealed.
	 */
	#[DataProvider('sealedTexts')]
	public function testOnlyTheTextOfACursorNamesAPage(string $sealed): void {
		$cursor = (new KeyedCrypto())->encrypt($sealed, self::key());

		self::assertNull($this->cursors()->open($cursor, 'dennis', 'invoice', []));
	}

	/**
	 * Without a secret, as no installation of Nextcloud is, there is no key: the search has
	 * its first page alone.
	 */
	public function testWithoutASecretThereAreNoCursors(): void {
		$cursor = $this->cursors()->seal(30, 'dennis', 'invoice', []);
		self::assertIsString($cursor);

		self::assertNull($this->cursors('')->seal(30, 'dennis', 'invoice', []));
		self::assertNull($this->cursors('')->open($cursor, 'dennis', 'invoice', []));
	}

	private function cursors(string $secret = self::SECRET): SearchCursors {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === 'secret' ? $secret : $default,
		);

		return new SearchCursors(new KeyedCrypto(), $config);
	}

	private static function key(): string {
		return hash_hmac('sha256', AppConstants::APP_ID . ' search cursor', self::SECRET);
	}

	/**
	 * @param array<string, string> $filters
	 */
	private static function question(string $userId, string $term, array $filters): string {
		return hash('sha256', serialize([$userId, $term, $filters]), true);
	}
}
