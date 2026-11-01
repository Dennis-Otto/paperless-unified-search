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
