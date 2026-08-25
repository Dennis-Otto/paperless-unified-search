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
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PaperlessSearchProviderTest extends TestCase {
	public function testReturnsOnlyAccessibleFilesAndOpensNextcloudViewer(): void {
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
			$this->createMock(LoggerInterface::class),
		);

		$result = $provider->search($user, $query)->jsonSerialize();

		self::assertFalse($result['isPaginated']);
		self::assertCount(1, $result['entries']);
		self::assertSame('Salary July 2026', $result['entries'][0]->jsonSerialize()['title']);
		self::assertSame('https://cloud.example.com/f/4711', $result['entries'][0]->jsonSerialize()['resourceUrl']);
		self::assertSame('2026-07-31 · Gross 5,000 EUR', $result['entries'][0]->jsonSerialize()['subline']);
	}
}
