<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use OCA\PaperlessUnifiedSearch\Controller\SettingsController;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Nextcloud loads routes from appinfo/routes.php only for apps that are already loaded,
 * so a settings page rendered before the app was loaded got empty save and reset URLs
 * (#25). Attribute routes are loaded for every enabled app.
 */
final class SettingsControllerRoutesTest extends TestCase {
	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function routes(): array {
		return [
			'save' => ['save', 'POST', '/settings'],
			'reset' => ['reset', 'DELETE', '/settings'],
		];
	}

	#[DataProvider('routes')]
	public function testEverySettingsActionDeclaresItsRoute(string $method, string $verb, string $url): void {
		$attributes = (new ReflectionMethod(SettingsController::class, $method))->getAttributes(FrontpageRoute::class);

		self::assertCount(1, $attributes);
		$route = $attributes[0]->newInstance();
		self::assertSame($verb, $route->getVerb());
		self::assertSame($url, $route->getUrl());
	}

	public function testNoRoutesComeFromTheLegacyRoutesFile(): void {
		self::assertFileDoesNotExist(__DIR__ . '/../../../appinfo/routes.php');
	}
}
