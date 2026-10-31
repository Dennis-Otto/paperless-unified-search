<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Search;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\FilePictures;
use OCA\PaperlessUnifiedSearch\Service\NextcloudFileLocator;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessNames;
use OCA\PaperlessUnifiedSearch\Service\PlainText;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\FilterDefinition;
use OCP\Search\IExternalProvider;
use OCP\Search\IFilter;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaperlessSearchProvider implements IExternalProvider, IFilteringProvider {
	/** The ID in the routes of Nextcloud's search, through which the smart picker searches too. */
	public const ID = AppConstants::APP_ID . '_documents';

	/** The pages of Paperless that a search asks for at most to fill its results. */
	private const PAGE_BUDGET = 4;

	/** After this many milliseconds, a search asks Paperless for no further page. */
	private const TIME_BUDGET_MS = 3000;

	/**
	 * The filters of Nextcloud's search for a span of time, each with the filter of
	 * Paperless for the date of a document that it becomes.
	 */
	private const DATE_FILTERS = [
		IFilter::BUILTIN_SINCE => PaperlessApiService::CREATED_FROM,
		IFilter::BUILTIN_UNTIL => PaperlessApiService::CREATED_UNTIL,
	];

	/** The most characters of the excerpt of a document. */
	private const EXCERPT_LENGTH = 180;

	/**
	 * A date as Paperless sends it: the day, and a time with it from API versions before
	 * 9 and from Paperless-ngx 1, such as 2026-08-12T00:00:00+02:00.
	 */
	private const CREATED_PATTERN = '/^(\d{4}-\d{2}-\d{2})(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

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
		private PaperlessNames $names,
		private FilePictures $pictures,
		private IDateTimeFormatter $dateTimeFormatter,
		private IDateTimeZone $dateTimeZone,
		private AlwaysSearch $alwaysSearch,
		private IUserSession $userSession,
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
	 * The filters of Nextcloud's search that the provider takes: the term, and the span of
	 * time of the date filter. Nextcloud's web search sends the date filter only to the
	 * providers that name since and until, and lists any other one under "Partial
	 * matches", searched without it.
	 *
	 * @return list<string>
	 */
	public function getSupportedFilters(): array {
		return [IFilter::BUILTIN_TERM, ...array_keys(self::DATE_FILTERS)];
	}

	/**
	 * @return list<string>
	 */
	public function getAlternateIds(): array {
		return [];
	}

	/**
	 * @return list<FilterDefinition>
	 */
	public function getCustomFilters(): array {
		return [];
	}

	/**
	 * Whether Nextcloud searches Paperless only with its switch Search connected services,
	 * which it turns off on every page that loads. Not for every user while the
	 * administrators trust Paperless, and not for a user who chose to include it in every
	 * search of their own: both are explicit opt-ins. Nextcloud asks it for the user of the
	 * request, as it lists the providers of the search. The terms still go server-to-server
	 * to the configured Paperless instance.
	 */
	public function isExternalProvider(): bool {
		return !$this->alwaysSearch->isOnFor($this->userSession->getUser());
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
		if ($term === '' || $archiveOwner === '' || $pager->isPastTheEnd() || !$this->configService->isConfigured()) {
			return SearchResult::complete($this->getName(), []);
		}

		$filters = $this->getDateFilters($user, $query);
		$started = $this->timeFactory->now();
		$entries = [];
		$pages = 0;
		$leftOut = 0;
		$entryError = null;
		$fileError = null;
		$more = false;
		$rejected = false;
		$step = SearchEvent::STEP_PAPERLESS;
		try {
			do {
				$step = SearchEvent::STEP_PAPERLESS;
				$response = $this->paperlessApi->searchDocuments($term, $pager->getPage(), PaperlessPager::PAGE_SIZE, $filters);
				$hits = $pager->getHitsFromOffset($response['results']);
				$step = SearchEvent::STEP_FILES;
				// The files of all documents of the page, in one search of the file cache.
				$found = $this->fileLocator->findAllForUser($user, $this->getDocumentIds($hits), $archiveOwner);

				// The names of the correspondents, the types and the tags of the documents with
				// a file, with one request per kind at most, which never fails the search.
				$names = $this->names->of(self::withFiles($hits, $found->files));

				$pages++;
				[$pageEntries, $used, $pageLeftOut, $pageError] = $this->createEntries($user, $hits, $found->files, $names, $pager->limit - count($entries));
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
		} catch (PaperlessQueryRejected $rejection) {
			// A query that the user is still typing, or a page past the last one: Paperless
			// works, and there is nothing more to find.
			$this->logger->debug('Paperless rejected the query of a search with HTTP {status}', [
				'app' => AppConstants::APP_ID,
				'status' => $rejection->status,
			]);
			$rejected = true;
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

		// A search whose query Paperless refused is no search for the diagnostics: what
		// users type can neither fill the history nor end a failure in it.
		if ($rejected) {
			return SearchResult::complete($this->getName(), $entries);
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

	/**
	 * The span of time of the date filter as filters of Paperless for the date of a
	 * document, each bound that the search has. Nextcloud's web search sends a bound as
	 * a moment in UTC, such as midnight in the timezone of the browser for the first day
	 * of the span; its day in the timezone of the user is the day that the user picked.
	 *
	 * @return array<string, string>
	 */
	private function getDateFilters(IUser $user, ISearchQuery $query): array {
		$filters = [];
		foreach (self::DATE_FILTERS as $name => $parameter) {
			/** @psalm-suppress MixedAssignment The value of a filter is checked before use. */
			$moment = $query->getFilter($name)?->get();
			if ($moment instanceof DateTimeInterface) {
				$timeZone = $this->dateTimeZone->getTimeZone($moment->getTimestamp(), $user->getUID());
				$filters[$parameter] = DateTimeImmutable::createFromInterface($moment)->setTimezone($timeZone)->format('Y-m-d');
			}
		}

		return $filters;
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
	 * The hits of documents that a file stands for, of which a page may show entries.
	 *
	 * @param list<array<array-key, mixed>> $hits
	 * @param array<int, File> $files by document ID
	 * @return list<array<array-key, mixed>>
	 */
	private static function withFiles(array $hits, array $files): array {
		$withFiles = [];
		foreach ($hits as $hit) {
			$documentId = self::getDocumentId($hit);
			if ($documentId !== null && isset($files[$documentId])) {
				$withFiles[] = $hit;
			}
		}

		return $withFiles;
	}

	/**
	 * Entries for the hits in their order, at most as many as wanted: one for every
	 * document with a file. A file that fails to answer costs only its own entry, not the
	 * search.
	 *
	 * @param list<array<array-key, mixed>> $hits
	 * @param array<int, File> $files by document ID
	 * @param array<string, array<int, string>> $names the names of correspondents, types and tags, as PaperlessNames finds them
	 * @return array{list<SearchResultEntry>, int, int, ?Throwable} the entries, how many
	 *                                                              hits they went through, how many entries their files cost, and the
	 *                                                              error of the first of these files
	 */
	private function createEntries(IUser $user, array $hits, array $files, array $names, int $wanted): array {
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
				$entries[] = $this->createEntry($user, $hit, $files[$documentId], $names);
			} catch (Throwable $error) {
				$leftOut++;
				$firstError ??= $error;
			}
		}

		return [$entries, $used, $leftOut, $firstError];
	}

	/**
	 * The entry of a document: the preview of its file, or else the icon of the type of
	 * the file, its title, and a line with its date, its correspondent, its type and the
	 * text that Paperless found.
	 *
	 * @param array<array-key, mixed> $document
	 * @param array<string, array<int, string>> $names
	 */
	private function createEntry(IUser $user, array $document, File $file, array $names): SearchResultEntry {
		$title = isset($document['title']) && is_string($document['title']) ? trim($document['title']) : '';
		if ($title === '') {
			$title = $file->getName();
		}

		// The picture goes into the thumbnail, which every client shows, and none into the
		// icon: the smart picker shows an icon in place of the thumbnail, as the name of a
		// class of CSS, which a URL isn't.
		$entry = new SearchResultEntry(
			$this->pictures->urlOf($file),
			$title,
			$this->getSubline($document, $file, $names),
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
	 * The line below the title: what Paperless knows of the document, its date, its
	 * correspondent, its type and its tags, then the text that Paperless found, or else the
	 * name of the file, such as "Aug 12, 2026 · ACME Energy · Invoice · unpaid — Electricity
	 * invoice …". It is text without any tag of HTML, also where Paperless or the document
	 * has one.
	 *
	 * @param array<array-key, mixed> $document
	 * @param array<string, array<int, string>> $names
	 */
	private function getSubline(array $document, File $file, array $names): string {
		$facts = [];
		if (isset($document['created']) && is_string($document['created']) && $document['created'] !== '') {
			$facts[] = $this->formatDate($document['created']);
		}
		array_push($facts, ...PaperlessNames::shownOf($document, $names));

		$text = $this->getExcerpt($document);
		if ($text === '') {
			$text = $file->getName();
		}

		$subline = $facts === [] ? $text : implode(' · ', $facts) . ' — ' . $text;

		return PlainText::withoutMarkup($subline);
	}

	/**
	 * The date of a document in the language of the user, such as Aug 12, 2026 in
	 * English and 12.08.2026 in German. It is the day that Paperless names, in no other
	 * timezone: with a time, Paperless names the day in its own timezone, which the time
	 * would shift into the one of the user. Any other text stays as it is.
	 */
	private function formatDate(string $created): string {
		if (preg_match(self::CREATED_PATTERN, $created, $match) !== 1) {
			return $created;
		}

		$utc = new DateTimeZone('UTC');
		$day = DateTime::createFromFormat('!Y-m-d', $match[1], $utc);
		// No such day, as 2026-02-30, which PHP would turn into March 2.
		if ($day === false || $day->format('Y-m-d') !== $match[1]) {
			return $created;
		}

		return $this->dateTimeFormatter->formatDate($day, 'medium', $utc);
	}

	/**
	 * The text that Paperless found: its excerpt of the content, or else, for a search
	 * that matched a note only, its excerpt of the notes.
	 *
	 * @param array<array-key, mixed> $document
	 */
	private function getExcerpt(array $document): string {
		/** @psalm-suppress MixedAssignment JSON value is validated before use. */
		$searchHit = $document['__search_hit__'] ?? [];
		$searchHit = is_array($searchHit) ? $searchHit : [];
		$excerpt = self::plainText($searchHit['highlights'] ?? null);
		if ($excerpt !== '') {
			return $excerpt;
		}

		$note = self::plainText($searchHit['note_highlights'] ?? null);

		return $note === '' ? '' : $this->l10n->t('Note: %s', [$note]);
	}

	/**
	 * Highlights of Paperless as text on one line, cut to EXCERPT_LENGTH characters.
	 * Paperless marks the words of the term with tags, <b> in Paperless-ngx 3 and
	 * <span class="match"> in 2, and escapes the text of the document as HTML around them:
	 * the tags go, then the entities become characters again. What looks like a tag
	 * after that, such as <GmbH>, is text of the document, which getSubline() keeps.
	 */
	private static function plainText(mixed $highlights): string {
		if (is_array($highlights)) {
			$highlights = implode(' ', array_filter($highlights, 'is_string'));
		}
		if (!is_string($highlights)) {
			return '';
		}

		$text = PlainText::oneLine(html_entity_decode(strip_tags($highlights), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		return mb_strimwidth($text, 0, self::EXCERPT_LENGTH, '…', 'UTF-8');
	}
}
