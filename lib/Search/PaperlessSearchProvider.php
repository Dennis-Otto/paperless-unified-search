<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search;

use DateTimeImmutable;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IExternalProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaperlessSearchProvider implements IExternalProvider {
	/** The pages of Paperless that a search asks for at most to fill its results. */
	private const PAGE_BUDGET = 4;

	/** After this many milliseconds, a search asks Paperless for no further page. */
	private const TIME_BUDGET_MS = 3000;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PaperlessApiService $paperlessApi,
		private NextcloudFileLocator $fileLocator,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IRequest $request,
		private LoggerInterface $logger,
		private ConfigService $configService,
		private SearchDiagnostics $diagnostics,
		private ITimeFactory $timeFactory,
	) {
	}

	public function getId(): string {
		return AppConstants::APP_ID . '_documents';
	}

	public function getName(): string {
		return $this->l10n->t('Paperless documents');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		return 40;
	}

	/**
	 * Trusted-service mode is an explicit administrator opt-in. Returning false
	 * tells Nextcloud not to gate this provider behind its connected-services switch.
	 * Search terms are still sent server-to-server to the configured Paperless instance.
	 */
	public function isExternalProvider(): bool {
		return !$this->configService->isAlwaysSearchEnabled();
	}

	/**
	 * A page of results, filled from as many pages of Paperless as it takes, within a
	 * budget: Paperless ranks all documents that match, also those that the user can't
	 * open, and Nextcloud's web search drops a provider whose first answer is empty and
	 * offers "Load more results" only for a page with as many results as it asked for.
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		$pager = PaperlessPager::fromQuery($query->getCursor(), $query->getLimit());
		// Without an archive account no file can stand for a document, so Paperless
		// isn't even asked, and neither is it for a cursor past the end.
		$archiveOwner = $this->configService->getArchiveOwner();
		if ($term === '' || $archiveOwner === '' || $pager->isPastTheEnd() || !$this->paperlessApi->isConfigured()) {
			return SearchResult::complete($this->getName(), []);
		}

		$started = $this->timeFactory->now();
		$entries = [];
		$pages = 0;
		$leftOut = 0;
		$entryError = null;
		$fileError = null;
		$more = false;
		$step = SearchEvent::STEP_PAPERLESS;
		try {
			do {
				$step = SearchEvent::STEP_PAPERLESS;
				$response = $this->paperlessApi->searchDocuments($term, $pager->getPage(), PaperlessPager::PAGE_SIZE);
				$hits = $pager->getHitsFromOffset($response['results']);
				$step = SearchEvent::STEP_FILES;
				// The files of all documents of the page, in one search of the file cache.
				$found = $this->fileLocator->findAllForUser($user, $this->getDocumentIds($hits), $archiveOwner);

				$pages++;
				[$pageEntries, $used, $pageLeftOut, $pageError] = $this->createEntries($user, $hits, $found->files, $pager->limit - count($entries));
				$entries = [...$entries, ...$pageEntries];
				$leftOut += $pageLeftOut;
				$entryError ??= $pageError;
				$fileError ??= $found->error ?? $pageError;
				if ($used < count($hits)) {
					// The results are complete before the page is: the next page of the
					// search goes on with the first hit that this one didn't go through.
					$pager = $pager->advance($used);
					$more = true;
				} else {
					$pager = $pager->nextPage();
					$more = $response['next'] !== null && !$pager->isPastTheEnd();
				}
			} while ($more && count($entries) < $pager->limit && $pages < self::PAGE_BUDGET
				&& $this->getElapsedMs($started) < self::TIME_BUDGET_MS);
		} catch (Throwable $exception) {
			$this->logger->warning('Paperless unified search failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $exception::class,
			]);
			if ($entries === []) {
				$this->recordFailure($step, $exception, $started, $term);

				return SearchResult::complete($this->getName(), []);
			}

			// The results of the pages before stay, and the next page of the search asks
			// for this page of Paperless again.
			$more = true;
		}

		if ($entryError !== null) {
			$this->logger->warning('Paperless unified search left out {count} result(s) it could not show ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => $leftOut,
				'errorType' => $entryError::class,
			]);
		}

		// However many pages of Paperless it took, this is one search for the diagnostics.
		if ($entries === [] && $fileError !== null) {
			// Files failed to answer and the search shows nothing, maybe for that reason: to
			// the user, the search failed, and an earlier failure has not recovered.
			$this->recordFailure(SearchEvent::STEP_FILES, $fileError, $started, $term);
		} else {
			$this->diagnostics->recordSuccess();
		}

		if ($more) {
			return SearchResult::paginated($this->getName(), $entries, $pager->offset);
		}

		return SearchResult::complete($this->getName(), $entries);
	}

	private function recordFailure(string $step, Throwable $error, DateTimeImmutable $started, string $term): void {
		$this->diagnostics->recordFailure(
			$step,
			$error,
			$this->getElapsedMs($started),
			[$this->configService->getToken(), $term],
		);
	}

	private function getElapsedMs(DateTimeImmutable $since): int {
		$seconds = (float)$this->timeFactory->now()->format('U.u') - (float)$since->format('U.u');

		return (int)round($seconds * 1000.0);
	}

	/**
	 * The IDs of the documents of these hits of Paperless.
	 *
	 * @param list<array<array-key, mixed>> $hits
	 * @return list<int>
	 */
	private function getDocumentIds(array $hits): array {
		$documentIds = [];
		foreach ($hits as $hit) {
			$documentId = self::getDocumentId($hit);
			if ($documentId !== null) {
				$documentIds[] = $documentId;
			}
		}

		return $documentIds;
	}

	/**
	 * The ID of the document of a hit of Paperless; a hit without a numeric ID has none.
	 *
	 * @param array<array-key, mixed> $hit
	 */
	private static function getDocumentId(array $hit): ?int {
		return isset($hit['id']) && is_numeric($hit['id']) ? (int)$hit['id'] : null;
	}

	/**
	 * Entries for the hits in their order, at most as many as wanted: one for every
	 * document with a file. A file that fails to answer costs only its own entry, not the
	 * search.
	 *
	 * @param list<array<array-key, mixed>> $hits
	 * @param array<int, File> $files by document ID
	 * @return array{list<SearchResultEntry>, int, int, ?Throwable} the entries, how many
	 *                                                              hits they went through, how many entries their files cost, and the
	 *                                                              error of the first of these files
	 */
	private function createEntries(IUser $user, array $hits, array $files, int $wanted): array {
		$entries = [];
		$used = 0;
		$leftOut = 0;
		$firstError = null;
		foreach ($hits as $hit) {
			if (count($entries) >= $wanted) {
				break;
			}

			$used++;
			$documentId = self::getDocumentId($hit);
			if ($documentId === null || !isset($files[$documentId])) {
				continue;
			}

			try {
				$entries[] = $this->createEntry($user, $hit, $files[$documentId]);
			} catch (Throwable $error) {
				$leftOut++;
				$firstError ??= $error;
			}
		}

		return [$entries, $used, $leftOut, $firstError];
	}

	/**
	 * @param array<array-key, mixed> $document
	 */
	private function createEntry(IUser $user, array $document, File $file): SearchResultEntry {
		$title = isset($document['title']) && is_string($document['title']) ? trim($document['title']) : '';
		if ($title === '') {
			$title = $file->getName();
		}

		$entry = new SearchResultEntry(
			$this->urlGenerator->imagePath(AppConstants::APP_ID, 'app.svg'),
			$title,
			$this->getSubline($document, $file),
			$this->getResourceUrl($user, $file),
			'',
			false,
		);
		$entry->addAttribute('fileId', (string)$file->getId());
		$entry->addAttribute('path', $this->fileLocator->getPathForUser($user, $file));

		return $entry;
	}

	private function getResourceUrl(IUser $user, File $file): string {
		$webUrl = $this->urlGenerator->linkToRouteAbsolute(
			'files.view.showFile',
			['fileid' => $file->getId()],
		);
		$userAgent = $this->request->getHeader('User-Agent');

		if (preg_match(IRequest::USER_AGENT_CLIENT_IOS, $userAgent) !== 1
			|| !str_contains($userAgent, 'Nextcloud-iOS/')) {
			return $webUrl;
		}

		return 'nextcloud://open-file?' . http_build_query([
			'user' => $user->getUID(),
			'link' => $webUrl,
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * @param array<array-key, mixed> $document
	 */
	private function getSubline(array $document, File $file): string {
		$parts = [];
		if (isset($document['created']) && is_string($document['created']) && $document['created'] !== '') {
			$parts[] = $document['created'];
		}

		$snippet = $this->getHighlight($document);
		if ($snippet !== '') {
			$parts[] = $snippet;
		} else {
			$parts[] = $file->getName();
		}

		return implode(' · ', $parts);
	}

	/**
	 * @param array<array-key, mixed> $document
	 */
	private function getHighlight(array $document): string {
		/** @psalm-suppress MixedAssignment JSON value is validated before use. */
		$searchHit = $document['__search_hit__'] ?? null;
		if (!is_array($searchHit) || !isset($searchHit['highlights'])) {
			return '';
		}

		$rawHighlights = $searchHit['highlights'];
		if (is_array($rawHighlights)) {
			$rawHighlights = implode(' ', array_filter($rawHighlights, 'is_string'));
		}

		if (!is_string($rawHighlights)) {
			return '';
		}

		$snippet = html_entity_decode(strip_tags($rawHighlights), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$snippet = preg_replace('/\s+/u', ' ', $snippet) ?? '';

		return mb_strimwidth(trim($snippet), 0, 180, '…', 'UTF-8');
	}
}
