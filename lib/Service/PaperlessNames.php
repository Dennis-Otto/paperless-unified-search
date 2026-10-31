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
 * The names of the correspondents, the types and the tags of documents, which a result
 * shows next to its date: Paperless sends only their IDs with the documents of a search.
 * Names come from the distributed cache of Nextcloud for ten minutes, and the others
 * with one request per kind, for all documents of a page. They only add to a result: a
 * lookup that fails leaves them out, without a problem in the history of the search, and
 * a list that Paperless refuses or doesn't answer rests for a while, so that every search
 * doesn't wait for it.
 */
final class PaperlessNames {
	/** The field of a document that names its tags, with the path of their list in the API. */
	public const TAGS = ['tags' => 'tags'];

	/**
	 * The fields of a document that name objects of Paperless, with the path of their list
	 * in the API, in the order in which a result shows their names.
	 */
	public const FIELDS = [
		'correspondent' => 'correspondents',
		'document_type' => 'document_types',
		...self::TAGS,
	];

	/**
	 * The fields that name a list of objects, rather than one, with the most of their names
	 * that a result shows: a document may have a dozen tags, and the line below its title
	 * has room for two, then +3 for three more.
	 */
	private const MOST_SHOWN = [
		'tags' => 2,
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
	 * The names of the correspondents, the types and the tags of these documents, as far
	 * as Paperless names them.
	 *
	 * @param array<array-key, array<array-key, mixed>> $documents
	 * @param array<string, string> $fields the fields to name, all of FIELDS or fewer, such as TAGS
	 * @return array<string, array<int, string>> the names by field and by ID; an ID without
	 *                                           a name is missing
	 */
	public function of(array $documents, array $fields = self::FIELDS): array {
		try {
			return $this->find($documents, $fields);
		} catch (Throwable $error) {
			// Such as a cache that fails: the results show without names.
			$this->logger->warning('The names of the correspondents, types and tags of search results failed ({errorType})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $error::class,
			]);

			return [];
		}
	}

	/**
	 * @param array<array-key, array<array-key, mixed>> $documents
	 * @param array<string, string> $fields
	 * @return array<string, array<int, string>>
	 */
	private function find(array $documents, array $fields): array {
		$names = [];
		$server = hash('sha256', $this->configService->getUrl());
		$answers = true;
		foreach ($fields as $field => $list) {
			$names[$field] = [];
			$missing = [];
			foreach (self::idsIn($documents, $field) as $id) {
				/** @psalm-suppress MixedAssignment The cache holds what set() left, checked below. */
				$name = $this->getCache()->get(self::nameKey($server, $field, $id));
				if (is_string($name)) {
					$names[$field][$id] = $name;
				} else {
					$missing[] = $id;
				}
			}

			// After a request without answer, Paperless isn't asked for the other kinds: their
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
	 * The IDs of the objects that a field of a document names, each once, in the order of
	 * Paperless: the one of a correspondent or a type, or those of the tags, and none for a
	 * field that names none, such as null. Only a positive number is an ID.
	 *
	 * @param array<array-key, mixed> $document
	 * @return array<int, int> the IDs, each by itself
	 */
	private static function idsOf(array $document, string $field): array {
		/** @psalm-suppress MixedAssignment Every ID is checked below. */
		$named = $document[$field] ?? null;
		if (!isset(self::MOST_SHOWN[$field])) {
			$named = [$named];
		} elseif (!is_array($named)) {
			return [];
		}

		$ids = [];
		/** @psalm-suppress MixedAssignment The same. */
		foreach ($named as $id) {
			if (is_int($id) && $id > 0) {
				$ids[$id] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The names that a result shows of a document, on one line each: those of its
	 * correspondent, its type and its tags, in this order, without those that Paperless
	 * doesn't name, such as the tag of the inbox. Of a list, such as the tags, a result
	 * shows the first names and then how many more it has, such as ['ACME Energy',
	 * 'Invoice', 'unpaid', 'tax 2026', '+1'] for a document with three tags.
	 *
	 * @param array<array-key, mixed> $document
	 * @param array<string, array<int, string>> $names the names by field and by ID, as of() finds them
	 * @return list<string>
	 */
	public static function shownOf(array $document, array $names): array {
		$shown = [];
		foreach (array_keys(self::FIELDS) as $field) {
			$named = self::namesOf($document, $field, $names);
			$most = self::MOST_SHOWN[$field] ?? null;
			if ($most !== null && count($named) > $most) {
				$named = [...array_slice($named, 0, $most), '+' . (count($named) - $most)];
			}
			array_push($shown, ...$named);
		}

		return $shown;
	}

	/**
	 * The names of the objects that a field of a document names, each on one line, in the
	 * order of Paperless, without those that Paperless doesn't name.
	 *
	 * @param array<array-key, mixed> $document
	 * @param array<string, array<int, string>> $names the names by field and by ID, as of() finds them
	 * @return list<string>
	 */
	public static function namesOf(array $document, string $field, array $names): array {
		$named = [];
		foreach (self::idsOf($document, $field) as $id) {
			$name = PlainText::oneLine($names[$field][$id] ?? '');
			if ($name !== '') {
				$named[] = $name;
			}
		}

		return $named;
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
	 * @param array<array-key, array<array-key, mixed>> $documents
	 * @return array<int, int> the IDs, each by itself
	 */
	private static function idsIn(array $documents, string $field): array {
		$ids = [];
		foreach ($documents as $document) {
			foreach (self::idsOf($document, $field) as $id) {
				$ids[$id] = $id;
			}
		}

		return $ids;
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
