<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Model;

use OCA\PaperlessUnifiedSearch\Model\AlertProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The progress of the job of the notifications, as the settings keep it between two runs.
 */
final class AlertProgressTest extends TestCase {
	public function testItComesBackAsItWasStored(): void {
		$progress = new AlertProgress('server', 412, 1791500000, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600);

		// Through JSON, as the settings keep it.
		$stored = json_decode((string)json_encode($progress->toStored()), true);
		self::assertSame([
			'server' => 'server',
			'lastSeen' => 412,
			'checkedAt' => 1791500000,
			'waiting' => [['id' => 413, 'title' => 'New', 'since' => 1791500100]],
			'failures' => 2,
			'restingUntil' => 1791500600,
		], $stored);

		$read = AlertProgress::fromStored($stored, 'server');
		self::assertNotNull($read);
		self::assertSame('server', $read->server);
		self::assertSame(412, $read->lastSeen);
		self::assertSame(1791500000, $read->checkedAt);
		self::assertSame([413 => ['title' => 'New', 'since' => 1791500100]], $read->waiting);
		self::assertSame(2, $read->failures);
		self::assertSame(1791500600, $read->restingUntil);
	}

	public function testItBelongsToItsServer(): void {
		self::assertNull(AlertProgress::fromStored(['server' => 'other', 'lastSeen' => 412], 'server'));
		self::assertNull(AlertProgress::fromStored([], 'server'));
	}

	/**
	 * @return array<string, array{AlertProgress, bool}>
	 */
	public static function nextProgress(): array {
		return [
			'the same' => [new AlertProgress('server', 412, 1791500000, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600), false],
			'an answer less than an hour later' => [new AlertProgress('server', 412, 1791503599, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600), false],
			'an answer an hour later' => [new AlertProgress('server', 412, 1791503600, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600), true],
			'a clock less than an hour behind' => [new AlertProgress('server', 412, 1791496401, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600), false],
			'a clock an hour behind' => [new AlertProgress('server', 412, 1791496400, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600), true],
			'a new document' => [new AlertProgress('server', 414, 1791500000, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600), true],
			'a document that got its file' => [new AlertProgress('server', 412, 1791500000, [], 2, 1791500600), true],
			'the owner of a waiting document' => [new AlertProgress('server', 412, 1791500000, [413 => ['title' => 'New', 'since' => 1791500100, 'owner' => 3]], 2, 1791500600), true],
			'another failure' => [new AlertProgress('server', 412, 1791500000, [413 => ['title' => 'New', 'since' => 1791500100]], 3, 1791500600), true],
			'another rest' => [new AlertProgress('server', 412, 1791500000, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791501200), true],
		];
	}

	/**
	 * A run stores its progress only when it differs from the stored one in more than the
	 * moment of the last answer, or when that moment moved by an hour either way.
	 */
	#[DataProvider('nextProgress')]
	public function testOnlyAProgressThatTellsSomethingNewIsWorthStoring(AlertProgress $next, bool $differs): void {
		$stored = new AlertProgress('server', 412, 1791500000, [413 => ['title' => 'New', 'since' => 1791500100]], 2, 1791500600);

		self::assertSame($differs, $next->differsFrom($stored, 3600));
	}

	public function testWithoutAStoredProgressEveryProgressIsWorthStoring(): void {
		self::assertTrue((new AlertProgress('server', null, 1791500000))->differsFrom(null, 3600));
	}

	public function testFreshProgressHasNoFailuresAndNoRest(): void {
		$progress = new AlertProgress('server', null, 1);

		self::assertSame([], $progress->waiting);
		self::assertSame(0, $progress->failures);
		self::assertSame(0, $progress->restingUntil);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function wrongValues(): array {
		return [
			'nothing else' => [[]],
			'text instead of numbers' => [['lastSeen' => '412', 'checkedAt' => '1', 'failures' => '2', 'restingUntil' => '3', 'waiting' => 'none']],
			'documents held wrongly' => [['waiting' => [
				'none',
				['title' => 'No ID', 'since' => 1],
				['id' => '1', 'title' => 'An ID as text', 'since' => 1],
				['id' => 2, 'since' => 1],
				['id' => 3, 'title' => 7, 'since' => 1],
				['id' => 4, 'title' => 'No moment'],
				['id' => 5, 'title' => 'A moment as text', 'since' => '1'],
			]]],
		];
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	#[DataProvider('wrongValues')]
	public function testWhatTheSettingsHoldWronglyIsLeftOut(array $stored): void {
		$read = AlertProgress::fromStored(['server' => 'server'] + $stored, 'server');

		self::assertNotNull($read);
		self::assertNull($read->lastSeen);
		self::assertSame(0, $read->checkedAt);
		self::assertSame([], $read->waiting);
		self::assertSame(0, $read->failures);
		self::assertSame(0, $read->restingUntil);
	}
}
