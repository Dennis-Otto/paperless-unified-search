<?php

declare(strict_types=1);

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The listing of the app in the Nextcloud App Store, which the App Store reads from
 * appinfo/info.xml with every release: where people find the app, the pictures they
 * see of it and where they ask, report and sponsor.
 */
final class AppStoreListingTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	private static function appInfo(): SimpleXMLElement {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		self::assertInstanceOf(SimpleXMLElement::class, $info);

		return $info;
	}

	/**
	 * The address of the repository on GitHub, without .git.
	 */
	private static function repository(): string {
		$repository = (string)self::appInfo()->repository;
		self::assertMatchesRegularExpression('#^https://github\.com/[^/]+/[^/]+\.git$#', $repository);

		return substr($repository, 0, -strlen('.git'));
	}

	public function testTheAppIsListedWhereItsUsersLookForIt(): void {
		$categories = array_map('strval', iterator_to_array(self::appInfo()->category, false));

		self::assertSame(['integration', 'files', 'search'], $categories);
	}

	public function testQuestionsGoToTheDiscussionsOfTheRepository(): void {
		self::assertSame(self::repository() . '/discussions', (string)self::appInfo()->discussion);
	}

	public function testTheDonationIsTheSponsorButtonOfTheRepository(): void {
		$funding = (string)file_get_contents(self::ROOT . '/.github/FUNDING.yml');
		self::assertSame(1, preg_match('/^github: \[([^\],]+)\]$/m', $funding, $sponsor));

		$donations = iterator_to_array(self::appInfo()->donation, false);

		self::assertCount(1, $donations);
		self::assertSame('https://github.com/sponsors/' . $sponsor[1], (string)$donations[0]);
		self::assertSame('other', (string)$donations[0]['type']);
		self::assertSame('GitHub Sponsors', (string)$donations[0]['title']);
	}

	public function testEveryScreenshotIsInTheRepositoryAtTheTagOfTheVersion(): void {
		$info = self::appInfo();
		$prefix = str_replace('https://github.com/', 'https://raw.githubusercontent.com/', self::repository())
			. '/v' . (string)$info->version . '/';

		$pictures = [];
		foreach ($info->screenshot as $screenshot) {
			$url = (string)$screenshot;
			self::assertStringStartsWith($prefix, $url, 'The release bot keeps the version of every screenshot current.');
			$picture = substr($url, strlen($prefix));
			self::assertFileExists(self::ROOT . '/' . $picture);
			$pictures[] = $picture;
		}

		self::assertSame([
			'screenshots/01-unified-search.png',
			'screenshots/02-nextcloud-pdf-viewer.png',
			'screenshots/03-admin-settings.png',
			'screenshots/04-mobile-search.png',
			'screenshots/06-search-problems.png',
		], $pictures);
	}

	public function testTheReleaseBotRaisesTheVersionOfEveryScreenshot(): void {
		$lines = file(self::ROOT . '/appinfo/info.xml', FILE_IGNORE_NEW_LINES);
		self::assertIsArray($lines);

		$screenshots = array_filter($lines, static fn (string $line): bool => str_contains($line, '<screenshot'));

		self::assertNotEmpty($screenshots);
		foreach ($screenshots as $line) {
			self::assertStringEndsWith('<!-- x-release-please-version -->', $line);
		}
	}

	public function testEveryDescriptionLinksTheDocumentation(): void {
		$info = self::appInfo();
		$website = (string)$info->website;

		self::assertCount(2, $info->description);
		foreach ($info->description as $description) {
			self::assertStringContainsString('<' . $website . '>', (string)$description);
		}
	}
}
