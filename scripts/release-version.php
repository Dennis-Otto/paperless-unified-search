<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use PaperlessUnifiedSearch\ReleaseTools\ReleaseVersionManager;

require_once __DIR__ . '/ReleaseVersionManager.php';

$arguments = array_slice($argv, 1);
$root = dirname(__DIR__);
$date = gmdate('Y-m-d');

foreach ($arguments as $index => $argument) {
	if (str_starts_with($argument, '--root=')) {
		$root = substr($argument, strlen('--root='));
		unset($arguments[$index]);
	} elseif (str_starts_with($argument, '--date=')) {
		$date = substr($argument, strlen('--date='));
		unset($arguments[$index]);
	}
}

$arguments = array_values($arguments);
$command = $arguments[0] ?? '';
$manager = new ReleaseVersionManager(rtrim($root, '/'));

try {
	switch ($command) {
		case 'current':
			echo $manager->currentVersion() . "\n";
			break;
		case 'check':
			echo $manager->check() . "\n";
			break;
		case 'next':
			echo $manager->nextVersion($manager->check(), $arguments[1] ?? '') . "\n";
			break;
		case 'bump':
			echo $manager->bump($arguments[1] ?? '', $date) . "\n";
			break;
		case 'notes':
			echo $manager->releaseNotes($arguments[1] ?? $manager->check());
			break;
		default:
			throw new RuntimeException(
				'Usage: release-version.php current|check|next <patch|minor|major>|bump <patch|minor|major>|notes [version]',
			);
	}
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
}
