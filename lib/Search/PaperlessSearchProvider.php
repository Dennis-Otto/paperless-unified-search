<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IExternalProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaperlessSearchProvider implements IExternalProvider {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PaperlessApiService $paperlessApi,
		private NextcloudFileLocator $fileLocator,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private ConfigService $configService,
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

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if ($term === '' || !$this->paperlessApi->isConfigured()) {
			return SearchResult::complete($this->getName(), []);
		}

		$page = $this->getPage($query->getCursor());
		$limit = max(1, min(50, $query->getLimit()));

		try {
			$response = $this->paperlessApi->searchDocuments($term, $page, $limit);
			$entries = [];

			foreach ($response['results'] as $document) {
				$entry = $this->createEntry($user, $document);
				if ($entry !== null) {
					$entries[] = $entry;
				}
			}

			if ($response['next'] !== null) {
				return SearchResult::paginated($this->getName(), $entries, $page + 1);
			}

			return SearchResult::complete($this->getName(), $entries);
		} catch (Throwable $exception) {
			$this->logger->warning('Paperless unified search failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $exception::class,
			]);

			return SearchResult::complete($this->getName(), []);
		}
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
	 * @param array<array-key, mixed> $document
	 */
	private function createEntry(IUser $user, array $document): ?SearchResultEntry {
		if (!isset($document['id']) || !is_numeric($document['id'])) {
			return null;
		}

		$documentId = (int)$document['id'];
		$file = $this->fileLocator->findForUser($user, $documentId);
		if ($file === null) {
			return null;
		}

		$title = isset($document['title']) && is_string($document['title']) ? trim($document['title']) : '';
		if ($title === '') {
			$title = $file->getName();
		}

		return new SearchResultEntry(
			$this->urlGenerator->imagePath(AppConstants::APP_ID, 'app.svg'),
			$title,
			$this->getSubline($document, $file),
			$this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $file->getId()]),
			'',
			false,
		);
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
