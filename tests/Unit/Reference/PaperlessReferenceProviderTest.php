<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Reference;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Reference\PaperlessReferenceProvider;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class PaperlessReferenceProviderTest extends TestCase {
	private const FILE_LINK = 'https://cloud.example.com/index.php/f/4711';

	public function testThePickerListsTheDocumentsUnderTheNameOfTheSearch(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects(self::once())
			->method('t')
			->with('Paperless documents')
			->willReturn('Paperless-Dokumente');

		$provider = $this->provider($l10n);

		self::assertSame('paperless_unified_search-documents', $provider->getId());
		self::assertSame(AppConstants::APP_ID . '-documents', PaperlessReferenceProvider::ID);
		self::assertSame('Paperless-Dokumente', $provider->getTitle());
		self::assertSame(40, $provider->getOrder());
	}

	public function testTheIconIsTheAbsoluteUrlOfTheDarkIconOfThePicker(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::once())
			->method('imagePath')
			->with(AppConstants::APP_ID, 'picker-dark.svg')
			->willReturn('/custom_apps/paperless_unified_search/img/picker-dark.svg');
		$urlGenerator->expects(self::once())
			->method('getAbsoluteURL')
			->with('/custom_apps/paperless_unified_search/img/picker-dark.svg')
			->willReturn('https://cloud.example.com/custom_apps/paperless_unified_search/img/picker-dark.svg');

		$provider = $this->provider(urlGenerator: $urlGenerator);

		self::assertSame(
			'https://cloud.example.com/custom_apps/paperless_unified_search/img/picker-dark.svg',
			$provider->getIconUrl(),
		);
	}

	public function testThePickerSearchesThroughTheSearchProviderOfTheApp(): void {
		$provider = $this->provider();

		self::assertInstanceOf(ISearchableReferenceProvider::class, $provider);
		self::assertSame(['paperless_unified_search_documents'], $provider->getSupportedSearchProviderIds());
		self::assertSame([PaperlessSearchProvider::ID], $provider->getSupportedSearchProviderIds());
	}

	public function testDescribesItselfToThePickerAsNextcloudSendsIt(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/paperless_unified_search/img/picker-dark.svg');
		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'https://cloud.example.com' . $path);

		// The answer of /ocs/v2.php/references/providers, from which the picker builds its menu.
		self::assertSame([
			'id' => 'paperless_unified_search-documents',
			'title' => 'Paperless documents',
			'icon_url' => 'https://cloud.example.com/apps/paperless_unified_search/img/picker-dark.svg',
			'order' => 40,
			'search_providers_ids' => ['paperless_unified_search_documents'],
		], $this->provider($l10n, $urlGenerator)->jsonSerialize());
	}

	public function testLeavesTheLinkOfAResultToTheProviderOfFileLinks(): void {
		$provider = $this->provider();

		// The inserted link is the one of the synchronized file, which Nextcloud renders
		// with the permissions of whoever reads it.
		self::assertFalse($provider->matchReference(self::FILE_LINK));
		self::assertNull($provider->resolveReference(self::FILE_LINK));
		self::assertSame('', $provider->getCachePrefix(self::FILE_LINK));
		self::assertNull($provider->getCacheKey(self::FILE_LINK));
	}

	private function provider(?IL10N $l10n = null, ?IURLGenerator $urlGenerator = null): PaperlessReferenceProvider {
		return new PaperlessReferenceProvider(
			$l10n ?? $this->createStub(IL10N::class),
			$urlGenerator ?? $this->createStub(IURLGenerator::class),
		);
	}
}
