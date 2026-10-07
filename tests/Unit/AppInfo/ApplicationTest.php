<?php

declare(strict_types=1);

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\AppInfo;

use OCA\PaperlessUnifiedSearch\AppInfo\Application;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class ApplicationTest extends TestCase {
	private static function appInfo(): SimpleXMLElement {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertInstanceOf(SimpleXMLElement::class, $info);

		return $info;
	}

	public function testTheAppIdIsTheOneOfTheAppInfo(): void {
		self::assertSame((string)self::appInfo()->id, Application::APP_ID);
	}

	public function testTheNamespaceIsTheOneOfTheAppInfo(): void {
		self::assertSame(
			'OCA\\' . (string)self::appInfo()->namespace . '\\AppInfo\\Application',
			Application::class,
		);
	}
}
