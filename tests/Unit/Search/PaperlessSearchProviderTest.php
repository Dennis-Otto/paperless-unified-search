<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Search;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PaperlessSearchProviderTest extends TestCase {
	#[DataProvider('resourceUrlCases')]
	public function testReturnsOnlyAccessibleFilesWithPlatformCompatibleResourceUrl(
		string $userAgent,
		string $expectedResourceUrl,
	): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('https://paperless.example.com');

		$credentials = $this->createMock(ICredentialsManager::class);
		$credentials->method('retrieve')->willReturn('TEST_VALUE');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode([
			'count' => 2,
			'next' => null,
			'results' => [
				[
					'id' => 123,
					'title' => 'Salary July 2026',
					'created' => '2026-07-31',
					'__search_hit__' => ['highlights' => '<span>Gross 5,000 EUR</span>'],
				],
				[
					'id' => 999,
					'title' => 'Not shared with this user',
				],
			],
		], JSON_THROW_ON_ERROR));

		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://paperless.example.com/api/documents/',
				self::callback(static function (array $options): bool {
					return $options['headers']['Authorization'] === 'Token TEST_VALUE'
						&& $options['headers']['User-Agent'] === 'Nextcloud-Paperless-Unified-Search/0.1'
						&& $options['query']['query'] === 'gross salary';
				}),
			)
			->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('2026-07-31 - Salary [P123].pdf');
		$file->method('getPath')->willReturn('/dennis/files/Paperless/Salary [P123].pdf');
		$file->method('getId')->willReturn(4711);

		$folder = $this->createMock(Folder::class);
		$folder->method('search')->willReturnCallback(
			static fn (string $marker): array => $marker === '[P123]' ? [$file] : [],
		);
		$folder->method('getRelativePath')
			->with('/dennis/files/Paperless/Salary [P123].pdf')
			->willReturn('/Paperless/Salary [P123].pdf');

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('dennis')->willReturn($folder);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dennis');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')
			->with(AppConstants::APP_ID, 'app.svg')
			->willReturn('/apps/paperless_unified_search/img/app.svg');
		$urlGenerator->expects(self::once())
			->method('linkToRouteAbsolute')
			->with('files.view.showFile', ['fileid' => 4711])
			->willReturn('https://cloud.example.com/f/4711');

		$request = $this->createMock(IRequest::class);
		$request->expects(self::once())
			->method('getHeader')
			->with('User-Agent')
			->willReturn($userAgent);

		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('gross salary');
		$query->method('getLimit')->willReturn(10);
		$query->method('getCursor')->willReturn(null);

		$configService = new ConfigService($config, $credentials);
		$provider = new PaperlessSearchProvider(
			new PaperlessApiService($configService, $clientService),
			new NextcloudFileLocator($root),
			$l10n,
			$urlGenerator,
			$request,
			$this->createMock(LoggerInterface::class),
			$configService,
		);

		self::assertTrue($provider->isExternalProvider());
		$result = $provider->search($user, $query)->jsonSerialize();

		self::assertFalse($result['isPaginated']);
		self::assertCount(1, $result['entries']);
		self::assertSame('Salary July 2026', $result['entries'][0]->jsonSerialize()['title']);
		self::assertSame($expectedResourceUrl, $result['entries'][0]->jsonSerialize()['resourceUrl']);
		self::assertSame([
			'fileId' => '4711',
			'path' => '/Paperless/Salary [P123].pdf',
		], $result['entries'][0]->jsonSerialize()['attributes']);
		self::assertSame('2026-07-31 · Gross 5,000 EUR', $result['entries'][0]->jsonSerialize()['subline']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function resourceUrlCases(): array {
		return [
			'browser' => [
				'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15',
				'https://cloud.example.com/f/4711',
			],
			'nextcloud iOS' => [
				'Mozilla/5.0 (iOS) Nextcloud-iOS/7.1.0',
				'nextcloud://open-file?user=dennis&link=https%3A%2F%2Fcloud.example.com%2Ff%2F4711',
			],
			'nextcloud Android' => [
				'Mozilla/5.0 (Android) Nextcloud-android/20260390',
				'https://cloud.example.com/f/4711',
			],
		];
	}

	public function testTrustedPaperlessIsNotGatedByConnectedServicesSwitch(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::once())
			->method('getValueBool')
			->with(AppConstants::APP_ID, 'always_search', false)
			->willReturn(true);

		$configService = new ConfigService($config, $this->createStub(ICredentialsManager::class));
		$provider = new PaperlessSearchProvider(
			new PaperlessApiService($configService, $this->createStub(IClientService::class)),
			new NextcloudFileLocator($this->createStub(IRootFolder::class)),
			$this->createStub(IL10N::class),
			$this->createStub(IURLGenerator::class),
			$this->createStub(IRequest::class),
			$this->createStub(LoggerInterface::class),
			$configService,
		);

		self::assertFalse($provider->isExternalProvider());
	}
}
