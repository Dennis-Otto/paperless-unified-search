<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
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
	/** The ID in the routes of Nextcloud's search, through which the smart picker searches too. */
	public const ID = AppConstants::APP_ID . '_documents';

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
	) {
	}

	public function getId(): string {
		return self::ID;
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

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		// Without an archive account no file can stand for a document, so Paperless
		// isn't even asked.
		$archiveOwner = $this->configService->getArchiveOwner();
		if ($term === '' || $archiveOwner === '' || !$this->paperlessApi->isConfigured()) {
			return SearchResult::complete($this->getName(), []);
		}

		$page = $this->getPage($query->getCursor());
		$limit = max(1, min(50, $query->getLimit()));
		$started = microtime(true);
		$step = SearchEvent::STEP_PAPERLESS;

		try {
			$response = $this->paperlessApi->searchDocuments($term, $page, $limit);
			$step = SearchEvent::STEP_FILES;
			$documents = $this->getDocuments($response['results']);
			// The files of all documents of the page, in one search of the file cache.
			$found = $this->fileLocator->findAllForUser($user, array_column($documents, 0), $archiveOwner);
		} catch (Throwable $exception) {
			$this->logger->warning('Paperless unified search failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $exception::class,
			]);
			$this->recordFailure($step, $exception, $started, $term);

			return SearchResult::complete($this->getName(), []);
		}

		[$entries, $entryError] = $this->createEntries($user, $documents, $found->files);
		$error = $found->error ?? $entryError;
		if ($entries === [] && $error !== null) {
			// Files failed to answer and the search shows nothing, maybe for that reason: to
			// the user, the search failed, and an earlier failure has not recovered.
			$this->recordFailure(SearchEvent::STEP_FILES, $error, $started, $term);
		} else {
			$this->diagnostics->recordSuccess();
		}

		if ($response['next'] !== null) {
			return SearchResult::paginated($this->getName(), $entries, $page + 1);
		}

		return SearchResult::complete($this->getName(), $entries);
	}

	private function recordFailure(string $step, Throwable $error, float $started, string $term): void {
		$this->diagnostics->recordFailure(
			$step,
			$error,
			(int)round((microtime(true) - $started) * 1000.0),
			[$this->configService->getToken(), $term],
		);
	}

	private function getPage(mixed $cursor): int {
		if (is_int($cursor)) {
			return max(1, $cursor);
		}

		if (is_string($cursor) && ctype_digit($cursor)) {
			return max(1, (int)$cursor);
		}

		return 1;
	}

	/**
	 * The documents of an answer of Paperless, each with its ID; a document without a
	 * numeric ID is left out.
	 *
	 * @param list<array<array-key, mixed>> $results
	 * @return list<array{int, array<array-key, mixed>}>
	 */
	private function getDocuments(array $results): array {
		$documents = [];
		foreach ($results as $document) {
			if (isset($document['id']) && is_numeric($document['id'])) {
				$documents[] = [(int)$document['id'], $document];
			}
		}

		return $documents;
	}

	/**
	 * An entry for every document with a file, in the order of Paperless. A file that fails
	 * to answer costs only its own entry, not the search.
	 *
	 * @param list<array{int, array<array-key, mixed>}> $documents
	 * @param array<int, File> $files by document ID
	 * @return array{list<SearchResultEntry>, ?Throwable} the entries, and the error of the
	 *                                                    first file that failed to answer
	 */
	private function createEntries(IUser $user, array $documents, array $files): array {
		$entries = [];
		$skipped = 0;
		$firstError = null;
		foreach ($documents as [$documentId, $document]) {
			if (!isset($files[$documentId])) {
				continue;
			}

			try {
				$entries[] = $this->createEntry($user, $document, $files[$documentId]);
			} catch (Throwable $error) {
				$skipped++;
				$firstError ??= $error;
			}
		}

		if ($firstError !== null) {
			$this->logger->warning('Paperless unified search left out {count} result(s) it could not show ({errorType})', [
				'app' => AppConstants::APP_ID,
				'count' => $skipped,
				'errorType' => $firstError::class,
			]);
		}

		return [$entries, $firstError];
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
