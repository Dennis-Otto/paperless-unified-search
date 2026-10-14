<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use OCA\PaperlessUnifiedSearch\Controller\SettingsController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AppApiAdminAccessWithoutUser;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\ExAppRequired;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Nextcloud loads routes from appinfo/routes.php only for apps that are already loaded,
 * so a settings page rendered before the app was loaded got empty save and reset URLs
 * (#25). Attribute routes are loaded for every enabled app.
 */
final class SettingsControllerRoutesTest extends TestCase {
	/**
	 * The attributes, and the annotations of the same names, with which a route opts out
	 * of a check of Nextcloud: the login, the administrator, or the CSRF token.
	 */
	private const OPT_OUTS = [
		NoAdminRequired::class,
		SubAdminRequired::class,
		AuthorizedAdminSetting::class,
		PublicPage::class,
		NoCSRFRequired::class,
		AnonRateLimit::class,
		ExAppRequired::class,
		AppApiAdminAccessWithoutUser::class,
		CORS::class,
	];

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function routes(): array {
		return [
			'save' => ['save', 'POST', '/settings'],
			'reset' => ['reset', 'DELETE', '/settings'],
			'clear the diagnostics' => ['clearDiagnostics', 'DELETE', '/settings/diagnostics'],
			'try a search' => ['trySearch', 'POST', '/settings/try'],
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

	public function testEveryActionOfTheControllerIsARouteOfThisTest(): void {
		self::assertSame(
			array_column(self::routes(), 0),
			array_map(static fn (ReflectionMethod $method): string => $method->getName(), $this->actions()),
		);
	}

	/**
	 * Only administrators may change the settings, and another site must not make the
	 * browser of one do it: every route passes Nextcloud's checks of the login, the
	 * administrator and the CSRF token. So does Try a search, which shows the titles of
	 * the documents that a user finds. The end-to-end tests try it as well.
	 */
	public function testNoSettingsActionOptsOutOfTheChecksOfNextcloud(): void {
		$class = new ReflectionClass(SettingsController::class);
		$annotation = '/@(' . implode('|', array_map(
			static fn (string $attribute): string => substr($attribute, strrpos($attribute, '\\') + 1),
			self::OPT_OUTS,
		)) . ')\b/i';

		foreach ([$class, ...$this->actions()] as $element) {
			$found = array_intersect(
				array_map(static fn (ReflectionAttribute $attribute): string => $attribute->getName(), $element->getAttributes()),
				self::OPT_OUTS,
			);
			self::assertSame([], array_values($found), $element->getName() . ' opts out of a check of Nextcloud.');
			self::assertDoesNotMatchRegularExpression($annotation, (string)$element->getDocComment(), $element->getName() . ' opts out of a check of Nextcloud.');
		}
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function passwordConfirmations(): array {
		return [
			'save' => ['save', true],
			'reset' => ['reset', true],
			'clear the diagnostics' => ['clearDiagnostics', false],
			'try a search' => ['trySearch', false],
		];
	}

	/**
	 * Saving and Disconnect ask for the password when it wasn't confirmed in the last 30
	 * minutes, like the settings of Nextcloud that change what it trusts.
	 */
	#[DataProvider('passwordConfirmations')]
	public function testChangingTheConnectionAsksForThePassword(string $method, bool $confirmed): void {
		$attributes = (new ReflectionMethod(SettingsController::class, $method))->getAttributes(PasswordConfirmationRequired::class);

		self::assertCount($confirmed ? 1 : 0, $attributes);
	}

	public function testNoRoutesComeFromTheLegacyRoutesFile(): void {
		self::assertFileDoesNotExist(__DIR__ . '/../../../appinfo/routes.php');
	}

	/**
	 * @return list<ReflectionMethod> the public methods of the controller itself, which Nextcloud can route to
	 */
	private function actions(): array {
		return array_values(array_filter(
			(new ReflectionClass(SettingsController::class))->getMethods(ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === SettingsController::class
				&& !$method->isConstructor(),
		));
	}
}
