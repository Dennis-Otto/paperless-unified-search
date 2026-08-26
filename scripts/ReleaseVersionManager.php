<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace PaperlessUnifiedSearch\ReleaseTools;

use RuntimeException;

final class ReleaseVersionManager {
	private const VERSION_PATTERN = '\\d+\\.\\d+\\.\\d+';

	public function __construct(
		private readonly string $root,
	) {
	}

	public function currentVersion(): string {
		$info = $this->read('appinfo/info.xml');
		$count = preg_match_all('/<version>(' . self::VERSION_PATTERN . ')<\\/version>/', $info, $matches);
		if ($count !== 1) {
			throw new RuntimeException('appinfo/info.xml must contain exactly one semantic app version.');
		}

		return $matches[1][0];
	}

	public function check(): string {
		$version = $this->currentVersion();
		$info = $this->read('appinfo/info.xml');

		$screenshotCount = preg_match_all(
			'~/v(' . self::VERSION_PATTERN . ')/screenshots/~',
			$info,
			$screenshotMatches,
		);
		if ($screenshotCount === false || $screenshotCount < 1) {
			throw new RuntimeException('appinfo/info.xml must contain at least one versioned screenshot URL.');
		}

		foreach ($screenshotMatches[1] as $screenshotVersion) {
			if ($screenshotVersion !== $version) {
				throw new RuntimeException(
					"Screenshot version {$screenshotVersion} does not match app version {$version}.",
				);
			}
		}

		$changelog = $this->read('CHANGELOG.md');
		if (substr_count($changelog, '## Unreleased') !== 1) {
			throw new RuntimeException('CHANGELOG.md must contain exactly one "## Unreleased" section.');
		}

		$releaseHeadingCount = preg_match_all(
			'/^## (' . self::VERSION_PATTERN . ') - \\d{4}-\\d{2}-\\d{2}$/m',
			$changelog,
			$releaseMatches,
		);
		if ($releaseHeadingCount === false || $releaseHeadingCount < 1) {
			throw new RuntimeException('CHANGELOG.md does not contain a released semantic version.');
		}
		if ($releaseMatches[1][0] !== $version) {
			throw new RuntimeException(
				"Latest changelog version {$releaseMatches[1][0]} does not match app version {$version}.",
			);
		}

		$appConstants = $this->read('lib/AppInfo/AppConstants.php');
		if (preg_match('/public const VERSION\\s*=/', $appConstants) === 1) {
			throw new RuntimeException('AppConstants must not duplicate the canonical version from appinfo/info.xml.');
		}

		return $version;
	}

	public function nextVersion(string $version, string $increment): string {
		if (preg_match('/^(\\d+)\\.(\\d+)\\.(\\d+)$/', $version, $matches) !== 1) {
			throw new RuntimeException("Invalid semantic version: {$version}");
		}

		$major = (int)$matches[1];
		$minor = (int)$matches[2];
		$patch = (int)$matches[3];

		switch ($increment) {
			case 'major':
				++$major;
				$minor = 0;
				$patch = 0;
				break;
			case 'minor':
				++$minor;
				$patch = 0;
				break;
			case 'patch':
				++$patch;
				break;
			default:
				throw new RuntimeException('Version increment must be patch, minor, or major.');
		}

		return "{$major}.{$minor}.{$patch}";
	}

	public function bump(string $increment, string $date): string {
		$currentVersion = $this->check();
		$newVersion = $this->nextVersion($currentVersion, $increment);
		$changelog = $this->read('CHANGELOG.md');
		$unreleased = $this->unreleasedNotes($changelog);
		if (preg_match('/^- \\S.+$/m', $unreleased) !== 1) {
			throw new RuntimeException('The Unreleased changelog section must contain at least one bullet.');
		}

		$info = $this->read('appinfo/info.xml');
		$info = str_replace(
			"<version>{$currentVersion}</version>",
			"<version>{$newVersion}</version>",
			$info,
			$versionCount,
		);
		if ($versionCount !== 1) {
			throw new RuntimeException('Could not update the canonical app version exactly once.');
		}

		$info = str_replace(
			"/v{$currentVersion}/screenshots/",
			"/v{$newVersion}/screenshots/",
			$info,
			$screenshotCount,
		);
		if ($screenshotCount < 1) {
			throw new RuntimeException('Could not update any versioned screenshot URL.');
		}

		$changelog = preg_replace(
			'/^## Unreleased$/m',
			"## Unreleased\n\n## {$newVersion} - {$date}",
			$changelog,
			1,
			$headingCount,
		);
		if ($changelog === null || $headingCount !== 1) {
			throw new RuntimeException('Could not create the changelog release heading.');
		}

		$this->write('appinfo/info.xml', $info);
		$this->write('CHANGELOG.md', $changelog);
		$this->check();

		return $newVersion;
	}

	public function releaseNotes(string $version): string {
		$changelog = $this->read('CHANGELOG.md');
		$pattern = '/^## ' . preg_quote($version, '/') . ' - \\d{4}-\\d{2}-\\d{2}\\R\\R(.*?)(?=\\R## |\\z)/ms';
		if (preg_match($pattern, $changelog, $matches) !== 1) {
			throw new RuntimeException("Could not find changelog notes for version {$version}.");
		}

		$notes = trim($matches[1]);
		if ($notes === '') {
			throw new RuntimeException("Changelog notes for version {$version} are empty.");
		}

		return $notes . "\n";
	}

	private function unreleasedNotes(string $changelog): string {
		if (preg_match('/^## Unreleased\\R\\R(.*?)(?=\\R## ' . self::VERSION_PATTERN . ' - )/ms', $changelog, $matches) !== 1) {
			throw new RuntimeException('Could not parse the Unreleased changelog section.');
		}

		return trim($matches[1]);
	}

	private function read(string $relativePath): string {
		$path = $this->root . '/' . $relativePath;
		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException("Could not read {$relativePath}.");
		}

		return $content;
	}

	private function write(string $relativePath, string $content): void {
		$path = $this->root . '/' . $relativePath;
		if (file_put_contents($path, $content, LOCK_EX) === false) {
			throw new RuntimeException("Could not write {$relativePath}.");
		}
	}
}
