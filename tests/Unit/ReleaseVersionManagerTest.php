<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use PaperlessUnifiedSearch\ReleaseTools\ReleaseVersionManager;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

require_once dirname(__DIR__, 2) . '/scripts/ReleaseVersionManager.php';

final class ReleaseVersionManagerTest extends TestCase {
	/** @var list<string> */
	private array $temporaryRoots = [];

	protected function tearDown(): void {
		foreach ($this->temporaryRoots as $root) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST,
			);
			/** @var SplFileInfo $item */
			foreach ($iterator as $item) {
				$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
			}
			rmdir($root);
		}

		parent::tearDown();
	}

	public function testCurrentRepositoryIsConsistent(): void {
		$manager = new ReleaseVersionManager(dirname(__DIR__, 2));

		self::assertSame($manager->currentVersion(), $manager->check());
	}

	public function testCalculatesSemanticVersionIncrements(): void {
		$manager = new ReleaseVersionManager(dirname(__DIR__, 2));

		self::assertSame('2.0.0', $manager->nextVersion('1.2.3', 'major'));
		self::assertSame('1.3.0', $manager->nextVersion('1.2.3', 'minor'));
		self::assertSame('1.2.4', $manager->nextVersion('1.2.3', 'patch'));
	}

	public function testBumpSupportsEverySemanticIncrement(): void {
		foreach (['major' => '2.0.0', 'minor' => '1.3.0', 'patch' => '1.2.4'] as $increment => $expected) {
			$manager = new ReleaseVersionManager($this->createFixture("- Prepare a {$increment} release."));

			self::assertSame($expected, $manager->bump($increment, '2026-08-26'));
			self::assertSame($expected, $manager->check());
		}
	}

	public function testBumpUpdatesMetadataScreenshotsAndChangelog(): void {
		$root = $this->createFixture('- Automate release versioning.');
		$manager = new ReleaseVersionManager($root);

		self::assertSame('1.3.0', $manager->bump('minor', '2026-08-26'));
		self::assertSame('1.3.0', $manager->check());
		self::assertStringContainsString('<version>1.3.0</version>', $this->read($root, 'appinfo/info.xml'));
		self::assertStringContainsString('/v1.3.0/screenshots/', $this->read($root, 'appinfo/info.xml'));
		self::assertStringContainsString('## 1.3.0 - 2026-08-26', $this->read($root, 'CHANGELOG.md'));
		self::assertSame("- Automate release versioning.\n", $manager->releaseNotes('1.3.0'));
	}

	public function testBumpRejectsEmptyUnreleasedSection(): void {
		$manager = new ReleaseVersionManager($this->createFixture(''));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('must contain at least one bullet');
		$manager->bump('patch', '2026-08-26');
	}

	private function createFixture(string $unreleasedNotes): string {
		$root = sys_get_temp_dir() . '/paperless-release-' . bin2hex(random_bytes(8));
		$this->temporaryRoots[] = $root;
		mkdir($root . '/appinfo', 0700, true);
		mkdir($root . '/lib/AppInfo', 0700, true);

		file_put_contents(
			$root . '/appinfo/info.xml',
			"<info>\n\t<version>1.2.3</version>\n\t<screenshot>https://example.test/v1.2.3/screenshots/app.png</screenshot>\n</info>\n",
		);
		file_put_contents(
			$root . '/CHANGELOG.md',
			"# Changelog\n\n## Unreleased\n\n{$unreleasedNotes}\n\n## 1.2.3 - 2026-08-25\n\n- Previous release.\n",
		);
		file_put_contents(
			$root . '/lib/AppInfo/AppConstants.php',
			"<?php\n\nfinal class AppConstants {\n}\n",
		);

		return $root;
	}

	private function read(string $root, string $relativePath): string {
		$content = file_get_contents($root . '/' . $relativePath);
		self::assertNotFalse($content);

		return $content;
	}
}
