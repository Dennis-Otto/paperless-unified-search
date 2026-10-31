<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Controller;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\InvalidSettingException;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\Explanation;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessNames;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SearchExplainer;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The routes of the settings page. Every one of them passes Nextcloud's checks of the
 * login, the administrator and the CSRF token, as it opts out of none of them;
 * SettingsControllerRoutesTest makes sure it stays so.
 */
final class SettingsController extends Controller {
	/**
	 * The code of a refusal when something failed that the settings don't know of, such as
	 * the database.
	 */
	private const CODE_INTERNAL = 'internal';

	/**
	 * How many documents of the ranking of Paperless Try a search goes through: those
	 * that an administrator reads in one table.
	 */
	public const TRY_LIMIT = 20;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private SearchDiagnostics $diagnostics,
		private SettingsMessages $messages,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private SearchExplainer $explainer,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IEventDispatcher $events,
		private PaperlessNames $names,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Saves the settings once Paperless answers at the URL with the token, with what the
	 * account of the token may do beyond what the search needs, or may not do of what the
	 * results show, which the answer names as warnings and saving doesn't refuse. Like
	 * Disconnect, it asks for the password of the administrator when it wasn't confirmed
	 * in the last 30 minutes. $allowLocal lets Nextcloud reach this Paperless, and only
	 * this one, at an address of the local network, and $personalAlwaysSearch lets users
	 * include Paperless in all their searches, in their personal settings; a save without
	 * it keeps the stored choice, so that no script turns the opt-in of users back on.
	 *
	 * A refusal answers the message to show, in the language of the administrator, and a
	 * code that says what failed, such as unauthorized or invalid_url. A failed test of the
	 * connection also goes into the log, with the error of the request but without the
	 * token and the query of a URL.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function save(string $url, #[SensitiveParameter] string $token = '', bool $alwaysSearch = false, string $archiveOwner = '', bool $allowLocal = false, ?bool $personalAlwaysSearch = null): JSONResponse {
		$effectiveToken = '';
		try {
			$normalizedUrl = $this->configService->normalizeUrl($url);
			$effectiveToken = $this->configService->resolveToken($token, $normalizedUrl);
			$owner = $this->configService->resolveArchiveOwner($archiveOwner);
			$status = $this->paperlessApi->probe($normalizedUrl, $effectiveToken, $allowLocal);

			return new JSONResponse($this->configService->save($normalizedUrl, $effectiveToken, $alwaysSearch, $owner, $allowLocal, $status->version, $personalAlwaysSearch ?? $this->configService->isPersonalAlwaysSearchAllowed(), $status->tokenWarnings));
		} catch (InvalidSettingException $exception) {
			return $this->refusal($this->messages->forSetting($exception->problem, trim($archiveOwner)), $exception->problem->value);
		} catch (PaperlessRequestException $exception) {
			$cause = $exception->getPrevious() ?? $exception;
			$this->logger->warning('The test of the connection to Paperless failed: {reason} ({errorType}: {detail})', [
				'app' => AppConstants::APP_ID,
				'reason' => $exception->reason->value,
				'errorType' => $cause::class,
				'detail' => SearchDiagnostics::describe($cause->getMessage(), [$effectiveToken]),
			]);

			return $this->refusal($this->messages->forFailure($exception), $exception->reason->value);
		} catch (Throwable $exception) {
			$this->logger->error('The settings of Paperless Unified Search could not be saved ({errorType}: {detail})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $exception::class,
				'detail' => SearchDiagnostics::describe($exception->getMessage(), [$effectiveToken]),
			]);

			return $this->refusal(
				$this->l10n->t('The settings could not be saved. The log of Nextcloud has the details.'),
				self::CODE_INTERNAL,
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}

	#[FrontpageRoute(verb: 'DELETE', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function reset(): JSONResponse {
		$config = $this->configService->reset();
		$this->diagnostics->clear();

		return new JSONResponse($config);
	}

	#[FrontpageRoute(verb: 'DELETE', url: '/settings/diagnostics')]
	public function clearDiagnostics(): JSONResponse {
		$this->diagnostics->clear();

		return new JSONResponse([]);
	}

	/**
	 * Try a search: the documents that Paperless ranks first for the term, each with
	 * whether it shows to the user and, if not, why, as occ paperless_unified_search:search
	 * --explain finds it, for administrators without a shell. The answer names each
	 * document by its ID, its title and its tags alone, without its content, an excerpt or
	 * its file, and the log and the audit log record which administrator tried a search as
	 * whom, without the term. The names of the tags only add to the answer, as they do to a
	 * result: without them, the answer holds no tags.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/try')]
	public function trySearch(string $term = '', string $user = ''): JSONResponse {
		$term = trim($term);
		$uid = trim($user);
		if ($term === '') {
			return $this->refusal($this->l10n->t('Enter a term to search for.'), 'no_term');
		}
		$account = $uid !== '' ? $this->userManager->get($uid) : null;
		if ($account === null) {
			return $this->refusal(
				$uid === '' ? $this->l10n->t('Enter the user ID of the account to search as.') : $this->l10n->t('There is no account %s.', [$uid]),
				'unknown_user',
			);
		}
		if (!$this->configService->isConfigured()) {
			return $this->refusal($this->l10n->t('Paperless is not connected yet. Save its URL and API token above first.'), 'not_configured');
		}
		$archiveOwner = $this->configService->getArchiveOwner();
		if ($archiveOwner === '') {
			return $this->refusal($this->l10n->t('No archive account exists, so the search shows no Paperless documents. Name the account that owns the synchronized files above.'), 'no_archive_account');
		}

		$admin = $this->userSession->getUser()?->getUID() ?? '';
		$this->logger->info('{admin} tried a search of Paperless as {user} in the settings', [
			'app' => AppConstants::APP_ID,
			'admin' => $admin,
			'user' => $account->getUID(),
		]);
		// The audit log of the app admin_audit records it whatever the log level.
		$this->events->dispatchTyped(new CriticalActionPerformedEvent(
			'Paperless Unified Search: %s tried a search as %s',
			['admin' => $admin, 'user' => $account->getUID()],
		));
		try {
			$response = $this->paperlessApi->searchDocuments($term, 1, self::TRY_LIMIT);
		} catch (PaperlessQueryRejected) {
			return $this->refusal($this->l10n->t('Paperless can\'t read this term in its advanced syntax, such as a range of dates that isn\'t complete like created:[2026 to. Correct the term and try again.'), 'query_rejected');
		} catch (PaperlessRequestException $exception) {
			return $this->refusal($this->messages->forFailure($exception), $exception->reason->value);
		} catch (Throwable $error) {
			$exception = PaperlessRequestException::unanswered($error);

			return $this->refusal($this->messages->forFailure($exception), $exception->reason->value);
		}

		$hits = self::hitsOf($response['results']);
		[$explanations, $fileError] = $this->explainer->explain($account, array_keys($hits), $archiveOwner);
		$names = $this->names->of($hits, PaperlessNames::TAGS);

		return new JSONResponse([
			'user' => $account->getUID(),
			'archiveAccount' => $archiveOwner,
			'count' => $response['count'],
			'documents' => array_map(
				static fn (Explanation $explanation): array => [
					'id' => $explanation->documentId,
					'title' => self::titleOf($hits[$explanation->documentId] ?? []),
					'tags' => PaperlessNames::namesOf($hits[$explanation->documentId] ?? [], 'tags', $names),
					'verdict' => $explanation->verdict->value,
				],
				$explanations,
			),
			'fileError' => $fileError !== null,
		]);
	}

	/**
	 * The documents that Paperless found, by their IDs, in its order: the first ones of its
	 * ranking, each as it first sent it. The explainer leaves out an ID below 1, which no
	 * document has.
	 *
	 * @param list<array<array-key, mixed>> $results
	 * @return array<int, array<array-key, mixed>>
	 */
	private static function hitsOf(array $results): array {
		$hits = [];
		foreach (array_slice($results, 0, self::TRY_LIMIT) as $hit) {
			$id = filter_var($hit['id'] ?? null, FILTER_VALIDATE_INT);
			if ($id !== false) {
				$hits[$id] ??= $hit;
			}
		}

		return $hits;
	}

	/**
	 * @param array<array-key, mixed> $hit
	 */
	private static function titleOf(array $hit): string {
		return isset($hit['title']) && is_string($hit['title']) ? trim($hit['title']) : '';
	}

	/**
	 * @param Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR $status
	 */
	private function refusal(string $message, string $code, int $status = Http::STATUS_BAD_REQUEST): JSONResponse {
		return new JSONResponse(['message' => $message, 'code' => $code], $status);
	}
}
