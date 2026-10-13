<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use PHPUnit\Framework\TestCase;

/**
 * Properties of the messages of the history of the problems of the search, checked on
 * random messages from a fixed seed, so that a failure repeats: no token and no term in
 * any case of any script, the blanks of the message tidied, and no more than 300
 * characters (#128).
 */
final class SearchDiagnosticsPropertiesTest extends TestCase {
	private const SEED = 20261012;
	private const RUNS = 500;
	private const TOKEN = 'TEST_VALUE';
	/**
	 * The letters of the terms: letters whose case differs only outside of ASCII, ß, whose
	 * upper case has two letters, and the dotted and the dotless i of Turkish, whose cases
	 * cross with the i of ASCII.
	 */
	private const ALPHABET = 'abcäöüßéİıxyz0123';
	/** Blanks between the parts of a message, the no-break space among them. */
	private const BLANKS = [' ', '  ', "\t", "\n", "\u{a0}"];

	public function testNoTokenAndNoTermSurviveInAnyCase(): void {
		mt_srand(self::SEED);
		$letters = mb_str_split(self::ALPHABET);

		for ($run = 1; $run <= self::RUNS; $run++) {
			$term = self::randomText($letters, mt_rand(3, 12));
			$message = self::randomMessage($letters, $term);

			$described = SearchDiagnostics::describe($message, [self::TOKEN, $term]);

			$case = sprintf('Run %d of seed %d, term %s, message %s, described as %s', $run, self::SEED,
				json_encode($term, JSON_UNESCAPED_UNICODE), json_encode($message, JSON_UNESCAPED_UNICODE), json_encode($described, JSON_UNESCAPED_UNICODE));
			self::assertFalse(mb_stripos($described, $term, 0, 'UTF-8'), $case);
			self::assertFalse(mb_stripos($described, self::TOKEN, 0, 'UTF-8'), $case);
			self::assertDoesNotMatchRegularExpression('/^\s|\s$/u', $described, $case);
			self::assertDoesNotMatchRegularExpression('/\s\s/u', $described, $case);
			self::assertLessThanOrEqual(300, mb_strlen($described, 'UTF-8'), $case);
		}
	}

	/**
	 * A message of an error that repeats the token and the term in random cases: alone,
	 * between other letters, in the query of a URL, and among random text.
	 *
	 * @param list<string> $letters
	 */
	private static function randomMessage(array $letters, string $term): string {
		$message = 'Token ' . self::inRandomCase(self::TOKEN) . ':';
		$parts = mt_rand(1, 6);
		for ($part = 1; $part <= $parts; $part++) {
			$message .= self::BLANKS[mt_rand(0, count(self::BLANKS) - 1)] . match (mt_rand(1, 5)) {
				1, 2 => self::inRandomCase($term),
				3 => self::randomText($letters, mt_rand(0, 8)) . self::inRandomCase($term) . self::randomText($letters, mt_rand(0, 8)),
				4 => 'https://paperless.example.com/api/documents/?query=' . self::inRandomCase($term) . '&page=' . mt_rand(1, 9),
				default => self::randomText($letters, mt_rand(1, 120)),
			};
		}

		return $message . self::BLANKS[mt_rand(0, count(self::BLANKS) - 1)];
	}

	private static function inRandomCase(string $text): string {
		return match (mt_rand(1, 5)) {
			1 => $text,
			2 => mb_strtoupper($text, 'UTF-8'),
			3 => mb_strtolower($text, 'UTF-8'),
			4 => mb_convert_case($text, MB_CASE_TITLE, 'UTF-8'),
			default => implode('', array_map(
				static fn (string $letter): string => mt_rand(0, 1) === 1 ? mb_strtoupper($letter, 'UTF-8') : mb_strtolower($letter, 'UTF-8'),
				mb_str_split($text),
			)),
		};
	}

	/**
	 * @param list<string> $letters
	 */
	private static function randomText(array $letters, int $length): string {
		$text = '';
		for ($letter = 0; $letter < $length; $letter++) {
			$text .= $letters[mt_rand(0, count($letters) - 1)];
		}

		return $text;
	}
}
