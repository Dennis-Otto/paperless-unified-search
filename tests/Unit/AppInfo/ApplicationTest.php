<?php

declare(strict_types=1);

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\AppInfo;

use OC;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\AppInfo\Application;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class ApplicationTest extends TestCase {
	protected function tearDown(): void {
		OC::$server = null;
	}

	private static function appInfo(): SimpleXMLElement {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertInstanceOf(SimpleXMLElement::class, $info);

		return $info;
	}

	public function testTheAppIdIsTheOneOfTheAppInfo(): void {
		self::assertSame((string)self::appInfo()->id, Application::APP_ID);
		self::assertSame(Application::APP_ID, AppConstants::APP_ID);
	}

	public function testTheNamespaceIsTheOneOfTheAppInfo(): void {
		self::assertSame(
			'OCA\\' . (string)self::appInfo()->namespace . '\\AppInfo\\Application',
			Application::class,
		);
	}

	public function testTheAppUsesTheContainerOfItsAppId(): void {
		$container = $this->createStub(IAppContainer::class);
		$server = $this->server($container);

		$application = new Application();

		self::assertSame($container, $application->getContainer());
		self::assertSame([Application::APP_ID], $server->requestedApps);
	}

	public function testRegistersTheSearchProvider(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())
			->method('registerSearchProvider')
			->with(PaperlessSearchProvider::class);

		$this->application()->register($context);
	}

	public function testBootingNeedsNothingFromTheServer(): void {
		$context = $this->createMock(IBootContext::class);
		$context->expects(self::never())->method(self::anything());

		$this->application()->boot($context);
	}

	private function application(): Application {
		$this->server($this->createStub(IAppContainer::class));

		return new Application();
	}

	/**
	 * Puts a server into the stub of \OC that answers what OCP's App asks it for.
	 */
	private function server(IAppContainer $container): object {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);

		$server = new class($config, $container) {
			/** @var list<string> */
			public array $requestedApps = [];

			public function __construct(
				private IConfig $config,
				private IAppContainer $container,
			) {
			}

			public function get(string $serviceName): IConfig {
				TestCase::assertSame(IConfig::class, $serviceName);

				return $this->config;
			}

			public function getRegisteredAppContainer(string $appName): IAppContainer {
				$this->requestedApps[] = $appName;

				return $this->container;
			}
		};
		OC::$server = $server;

		return $server;
	}
}
