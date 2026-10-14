<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The names of the correspondents and the types of documents, which a result shows
 * next to its date: Paperless sends only their IDs with the documents of a search.
 * Names come from the distributed cache of Nextcloud for ten minutes, and the others
 * with one request per kind. They only add to a result: a lookup that fails leaves
 * them out, without a problem in the history of the search, and a list that Paperless
 * refuses or doesn't answer rests for a while, so that every search doesn't wait for it.
 */
final class PaperlessNames {
	/** The fields of a document that name an object of Paperless, with the path of its list in the API. */
	public const FIELDS = [
		'correspondent' => 'correspondents',
		'document_type' => 'document_types',
	];

	/** The seconds that the cache keeps a name, and a list that Paperless refused. */
	private const TTL = 600;

	/**
	 * The seconds that a list rests after a request without answer, such as a list that
	 * takes longer than the timeout: a search, as every keystroke, would wait for it again.
	 */
	private const UNANSWERED_TTL = 60;

	private ?ICache $cache = null;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private PaperlessApiService $paperlessApi,
		private ConfigService $configService,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The names of the correspondents and the types of these documents, as far as
	 * Paperless names them.
	 *
	 * @param list<array<array-key, mixed>> $documents
	 * @return array<string, array<int, string>> the names by field of FIELDS and by ID; an
	 *                                           ID without a name is missing
	 */
	public function of(array $documents): array {
		try {
			return $this->find($documents);
		} catch (Throwable $error) {
			// Such as a cache that fails: the results show without names.
			$this->logger->warning('The names of the correspondents and types of search results failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);

			return [];
		}
	}

	/**
	 * @param list<array<array-key, mixed>> $documents
	 * @return array<string, array<int, string>>
	 */
	private function find(array $documents): array {
		$names = [];
		$server = hash('sha256', $this->configService->getUrl());
		$answers = true;
		foreach (self::FIELDS as $field => $list) {
			$names[$field] = [];
			$missing = [];
			foreach (self::idsOf($documents, $field) as $id) {
				/** @psalm-suppress MixedAssignment The cache holds what set() left, checked below. */
				$name = $this->getCache()->get(self::nameKey($server, $field, $id));
				if (is_string($name)) {
					$names[$field][$id] = $name;
				} else {
					$missing[] = $id;
				}
			}

			// After a request without answer, Paperless isn't asked for the other kind: its
			// names aren't worth a second wait. A list that rests isn't asked for either.
			if ($missing !== [] && $answers && $this->getCache()->get(self::restingKey($server, $field)) !== true) {
				[$found, $answers] = $this->lookUp($server, $field, $list, $missing);
				$names[$field] += $found;
			}
			$names[$field] = array_filter($names[$field], static fn (string $name): bool => $name !== '');
		}

		return $names;
	}

	/**
	 * The ID of the object that a field of a document names, or null for none.
	 *
	 * @param array<array-key, mixed> $document
	 */
	public static function idOf(array $document, string $field): ?int {
		return isset($document[$field]) && is_int($document[$field]) && $document[$field] > 0 ? $document[$field] : null;
	}

	/**
	 * The names of these IDs in a list of Paperless, which the cache keeps: an ID without
	 * a name as well, as an empty name, so that it costs no request either. A list that
	 * Paperless refuses to the token, which may not view it, rests for ten minutes, and a
	 * list without answer for a minute: no search asks for it while it rests.
	 *
	 * @param list<int> $ids
	 * @return array{array<int, string>, bool} the names by ID, and whether Paperless answered
	 */
	private function lookUp(string $server, string $field, string $list, array $ids): array {
		try {
			$found = $this->paperlessApi->findNames($list, $ids);
		} catch (PaperlessRequestException $failure) {
			if ($failure->reason === RequestFailure::Unauthorized) {
				$this->getCache()->set(self::restingKey($server, $field), true, self::TTL);
			}
			$this->logger->info('Paperless answered the request for the names of the {list} of search results without them ({error})', [
				'app' => AppConstants::APP_ID,
				'list' => $list,
				'error' => $failure->getMessage(),
			]);

			return [[], true];
		} catch (Throwable $error) {
			$this->getCache()->set(self::restingKey($server, $field), true, self::UNANSWERED_TTL);
			$this->logger->info('Paperless gave no names of the {list} of search results ({errorType})', [
				'app' => AppConstants::APP_ID,
				'list' => $list,
				'errorType' => $error::class,
			]);

			return [[], false];
		}

		$names = [];
		foreach ($ids as $id) {
			$names[$id] = $found[$id] ?? '';
			$this->getCache()->set(self::nameKey($server, $field, $id), $names[$id], self::TTL);
		}

		return [$names, true];
	}

	/**
	 * The IDs that a field names in these documents, each once, in their order.
	 *
	 * @param list<array<array-key, mixed>> $documents
	 * @return list<int>
	 */
	private static function idsOf(array $documents, string $field): array {
		$ids = [];
		foreach ($documents as $document) {
			$id = self::idOf($document, $field);
			if ($id !== null) {
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	private function getCache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed(AppConstants::APP_ID);
	}

	private static function nameKey(string $server, string $field, int $id): string {
		return 'name-' . $field . '-' . $server . '-' . $id;
	}

	private static function restingKey(string $server, string $field): string {
		return 'names-resting-' . $field . '-' . $server;
	}
}
