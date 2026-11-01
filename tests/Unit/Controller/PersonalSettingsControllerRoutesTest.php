<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Controller;

use OCA\PaperlessUnifiedSearch\Controller\PersonalSettingsController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AppApiAdminAccessWithoutUser;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\ExAppRequired;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * The route of the personal settings: every signed-in user reaches it, so it opts out of
 * the check of the administrator, but of none of the others of Nextcloud, such as the
 * login and the CSRF token, and it changes nothing but the choice of that user.
 */
final class PersonalSettingsControllerRoutesTest extends TestCase {
	/** What a route must not declare, as SettingsControllerRoutesTest lists it, but NoAdminRequired. */
	private const OPT_OUTS = [
		SubAdminRequired::class,
		AuthorizedAdminSetting::class,
		PublicPage::class,
		NoCSRFRequired::class,
		AnonRateLimit::class,
		ExAppRequired::class,
		AppApiAdminAccessWithoutUser::class,
		CORS::class,
	];

	public function testTheRouteStoresTheChoiceOfTheSignedInUser(): void {
		$method = new ReflectionMethod(PersonalSettingsController::class, 'save');
		$attributes = $method->getAttributes(FrontpageRoute::class);

		self::assertCount(1, $attributes);
		$route = $attributes[0]->newInstance();
		self::assertSame('POST', $route->getVerb());
		self::assertSame('/personal', $route->getUrl());
		self::assertCount(1, $method->getAttributes(NoAdminRequired::class));
	}

	public function testTheRouteOfTheNotificationsStoresTheChoiceOfTheSignedInUser(): void {
		$method = new ReflectionMethod(PersonalSettingsController::class, 'saveAlerts');
		$attributes = $method->getAttributes(FrontpageRoute::class);

		self::assertCount(1, $attributes);
		$route = $attributes[0]->newInstance();
		self::assertSame('POST', $route->getVerb());
		self::assertSame('/personal/alerts', $route->getUrl());
		self::assertCount(1, $method->getAttributes(NoAdminRequired::class));
	}

	public function testTheControllerHasNoOtherAction(): void {
		self::assertSame(['save', 'saveAlerts'], array_map(
			static fn (ReflectionMethod $method): string => $method->getName(),
			$this->actions(),
		));
	}

	public function testTheRouteOptsOutOfNoOtherCheckOfNextcloud(): void {
		$class = new ReflectionClass(PersonalSettingsController::class);
		$annotation = '/@(' . implode('|', array_map(
			static fn (string $attribute): string => substr($attribute, strrpos($attribute, '\\') + 1),
			[NoAdminRequired::class, ...self::OPT_OUTS],
		)) . ')\b/i';

		self::assertSame([], $class->getAttributes(NoAdminRequired::class), 'The class opts every route out of the check of the administrator.');
		foreach ([$class, ...$this->actions()] as $element) {
			$found = array_intersect(
				array_map(static fn (ReflectionAttribute $attribute): string => $attribute->getName(), $element->getAttributes()),
				self::OPT_OUTS,
			);
			self::assertSame([], array_values($found), $element->getName() . ' opts out of a check of Nextcloud.');
			self::assertDoesNotMatchRegularExpression($annotation, (string)$element->getDocComment(), $element->getName() . ' opts out of a check of Nextcloud in a comment.');
		}
	}

	/**
	 * @return list<ReflectionMethod>
	 */
	private function actions(): array {
		return array_values(array_filter(
			(new ReflectionClass(PersonalSettingsController::class))->getMethods(ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PersonalSettingsController::class
				&& !$method->isConstructor(),
		));
	}
}
