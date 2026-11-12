<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Controller;

use OCA\PaperlessUnifiedSearch\Exception\PaperlessQueryRejected;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Model\AlertChoice;
use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\PlainText;
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The routes of the personal settings, which every signed-in user reaches: they opt out of
 * Nextcloud's check of the administrator, and of no other check, such as those of the
 * login and the CSRF token; PersonalSettingsControllerRoutesTest makes sure it stays so.
 */
final class PersonalSettingsController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private AlwaysSearch $alwaysSearch,
		private SavedSearches $savedSearches,
		private PaperlessApiService $paperlessApi,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Stores whether every search of the signed-in user includes Paperless, without the
	 * switch Search connected services. It is refused while the administrators don't let
	 * users choose it, and a refusal answers the message to show, in the language of the
	 * user, and a code that says why.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/personal')]
	public function save(bool $alwaysSearch = false): JSONResponse {
		$user = $this->userSession->getUser();
		// Nextcloud lets no request without a login reach the route.
		if ($user === null) {
			return $this->refusal($this->l10n->t('Your session has expired. Reload the page and try again.'), 'not_signed_in', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->configService->isPersonalAlwaysSearchAllowed()) {
			return $this->refusal($this->l10n->t('Your administrator does not let users include Paperless in all their searches.'), 'not_allowed', Http::STATUS_FORBIDDEN);
		}

		$this->alwaysSearch->choose($user->getUID(), $alwaysSearch);

		return new JSONResponse(['alwaysSearch' => $alwaysSearch]);
	}

	/**
	 * Stores what the signed-in user wants to be notified about, every new document that
	 * they can open or those that their saved searches find, and answers it as stored. It is
	 * refused while the administrators don't let users choose it, for more searches, or a
	 * longer one, than SavedSearches keeps, and for a search that Paperless can't read: each
	 * one that is new among those in use goes to Paperless once, so that a mistyped one
	 * doesn't wait for documents that it never finds, with the number of its line in the
	 * refusal. When Paperless doesn't answer, the searches are stored all the same, and the
	 * answer says with checked that they could not be checked. The job of the notifications
	 * sends the searches to Paperless, which the section says above the field.
	 *
	 * @param array<array-key, mixed> $terms the searches, each as one line
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/personal/alerts')]
	public function saveAlerts(bool $everyDocument = false, array $terms = []): JSONResponse {
		$user = $this->userSession->getUser();
		// Nextcloud lets no request without a login reach the route.
		if ($user === null) {
			return $this->refusal($this->l10n->t('Your session has expired. Reload the page and try again.'), 'not_signed_in', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->configService->isNewDocumentAlertsAllowed()) {
			return $this->refusal($this->l10n->t('Your administrator does not let users ask for notifications about new documents.'), 'not_allowed', Http::STATUS_FORBIDDEN);
		}

		$saved = SavedSearches::termsOf($terms);
		if (count($saved) > SavedSearches::MOST_TERMS) {
			return $this->refusal($this->l10n->t('Save at most %s searches.', [SavedSearches::MOST_TERMS]), 'too_many_searches', Http::STATUS_BAD_REQUEST);
		}
		foreach ($saved as $term) {
			if (mb_strlen($term) > SavedSearches::LONGEST_TERM) {
				return $this->refusal($this->l10n->t('A search can be at most %s characters long.', [SavedSearches::LONGEST_TERM]), 'search_too_long', Http::STATUS_BAD_REQUEST);
			}
		}

		$stored = $this->savedSearches->of($user->getUID());
		$checked = true;
		// Every document leaves the searches unused, and the field disabled, so they are
		// checked once they count, when the user takes it back.
		foreach ($everyDocument ? [] : array_diff($saved, $stored->everyDocument ? [] : $stored->terms) as $term) {
			try {
				$this->paperlessApi->checkSavedSearch($term);
			} catch (PaperlessQueryRejected $rejection) {
				// Whether Paperless has the document of like:412 that its account may view
				// is no matter of the syntax, and the user may not be allowed to know.
				if ($rejection->refusesTheDocument()) {
					continue;
				}

				return $this->refusal(
					$this->l10n->t('Paperless can\'t read this term in its advanced syntax, such as a range of dates that isn\'t complete like created:[2026 to. Correct the term and try again.'),
					'query_rejected',
					Http::STATUS_BAD_REQUEST,
					['term' => $term, 'line' => self::lineOf($terms, $term)],
				);
			} catch (Throwable $failure) {
				$checked = false;
				// Paperless answered this term with an error, such as HTTP 500: the next
				// term may still be checked, and its answer comes as quickly.
				if ($failure instanceof PaperlessRequestException && $failure->status !== null) {
					continue;
				}

				// Paperless doesn't answer, or isn't connected: the searches are kept, and
				// each further one would only wait as long again.
				break;
			}
		}

		$choice = new AlertChoice($everyDocument, $saved);
		$this->savedSearches->choose($user->getUID(), $choice);

		return new JSONResponse($choice->jsonSerialize() + ['checked' => $checked]);
	}

	/**
	 * The number of the line, from 1, that holds a search, as the field sent its lines.
	 *
	 * @param array<array-key, mixed> $lines
	 */
	private static function lineOf(array $lines, string $term): int {
		$line = 0;
		/** @psalm-suppress MixedAssignment Every line is checked before use. */
		foreach ($lines as $entered) {
			$line++;
			if (is_string($entered) && PlainText::oneLine($entered) === $term) {
				break;
			}
		}

		return $line;
	}

	/**
	 * @param Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_BAD_REQUEST $status
	 * @param array<string, int|string> $details what else the page needs to show the refusal
	 */
	private function refusal(string $message, string $code, int $status, array $details = []): JSONResponse {
		return new JSONResponse(['message' => $message, 'code' => $code] + $details, $status);
	}
}
