<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Exception\SearchAccountUnfinished;
use OCA\PaperlessUnifiedSearch\Exception\SettingProblem;
use OCA\PaperlessUnifiedSearch\Model\TokenWarning;
use OCP\IL10N;

/**
 * What the settings page and occ say when the settings can't be saved or Paperless can't
 * be reached, when the account of the API token may do more or less than the search
 * needs, or when Paperless Sync gives each user an archive of their own: what failed and
 * what to correct, in the language of the administrator, or of Nextcloud for occ. Each
 * text stands in a call of t() of its own, where scripts/check-l10n.mjs finds it.
 */
final class SettingsMessages {
	/**
	 * The entry of the troubleshooting guide about the archives of Paperless Sync for each
	 * user, on the English and on the German website.
	 */
	private const PER_USER_ARCHIVES_HELP = 'https://dennis-otto.github.io/paperless-unified-search/troubleshooting.html#paperless-sync-gives-each-user-their-own-archive';
	private const PER_USER_ARCHIVES_HELP_DE = 'https://dennis-otto.github.io/paperless-unified-search/de/troubleshooting.html#paperless-sync-gibt-jedem-benutzer-ein-eigenes-archiv';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * The message for a setting that can't be saved.
	 */
	public function forSetting(SettingProblem $problem, string $archiveOwner): string {
		return match ($problem) {
			SettingProblem::InvalidUrl => $this->l10n->t('Enter a valid Paperless URL.'),
			SettingProblem::UnsupportedScheme => $this->l10n->t('The Paperless URL must use HTTP or HTTPS.'),
			SettingProblem::UrlWithExtras => $this->l10n->t('The Paperless URL must not contain credentials, a query, or a fragment.'),
			SettingProblem::HostNotAscii => $this->l10n->t('Enter the host of the Paperless URL in its ASCII form (punycode), such as xn--bcher-kva.example for bücher.example.'),
			SettingProblem::TokenRequired => $this->l10n->t('A Paperless API token is required.'),
			SettingProblem::TokenForOtherServer => $this->l10n->t('Enter the API token again: a stored token is only sent to the Paperless server it was saved for.'),
			SettingProblem::UnknownArchiveAccount => $this->l10n->t('The archive account %s does not exist.', [$archiveOwner]),
			SettingProblem::SuperuserTokenRequired => $this->l10n->t('Enter the API token of a superuser of Paperless. It sets up the search account and is not stored.'),
			SettingProblem::NotSuperuser => $this->l10n->t('This API token belongs to no superuser of Paperless, so it can\'t set up the search account. Enter the token of a superuser; it is not stored.'),
			SettingProblem::SearchAccountExists => $this->l10n->t('The user %s exists in Paperless already, and stays as it is. Save the settings with its API token, or delete the user in Paperless and try again.', [SearchAccountSetup::USERNAME]),
			SettingProblem::SearchWorkflowExists => $this->l10n->t('The workflow “%s” exists in Paperless already, and stays as it is. Delete it in Paperless and try again, or turn off “Let it view each new document”.', [SearchAccountSetup::WORKFLOW]),
		};
	}

	/**
	 * The message for a set-up of the search account that failed after Paperless had added
	 * its user, and that couldn't remove the user, the workflow or both again: it names
	 * what remains.
	 */
	public function forUnfinishedAccount(SearchAccountUnfinished $unfinished): string {
		if ($unfinished->workflow === null) {
			return $this->l10n->t('Paperless did not finish setting up the search account, and the user %s remains in it. Delete the user in Paperless, then try again.', [$unfinished->username]);
		}
		if (!$unfinished->userRemains) {
			return $this->l10n->t('Paperless did not finish setting up the search account, and the workflow “%s” remains in it. Delete the workflow in Paperless, then try again.', [$unfinished->workflow]);
		}

		return $this->l10n->t('Paperless did not finish setting up the search account, and the user %1$s and the workflow “%2$s” remain in it. Delete both in Paperless, then try again.', [$unfinished->username, $unfinished->workflow]);
	}

	/**
	 * The message for a test of the connection that failed, which says what to correct.
	 */
	public function forFailure(PaperlessRequestException $exception): string {
		$status = (string)$exception->status;

		return match ($exception->reason) {
			RequestFailure::Unauthorized => $this->l10n->t('Paperless rejected the API token (HTTP %s). Check the token, and that its account may view documents.', [$status]),
			RequestFailure::NotFound => $this->l10n->t('This URL answers, but not with the API of Paperless (HTTP 404). Check the address and its path.'),
			RequestFailure::Redirect => $this->l10n->t('This URL forwards to another address (HTTP %s). Enter the address that it forwards to.', [$status]),
			RequestFailure::UnsupportedVersion => $this->l10n->t('Paperless is older than 2.16 and does not speak version 9 of its API (HTTP 406). Update Paperless-ngx.'),
			RequestFailure::Http => $this->l10n->t('Paperless answered with an error (HTTP %s). Check its log, or try again later.', [$status]),
			RequestFailure::NotPaperless => $this->l10n->t('This URL answers, but not like Paperless-ngx. Check the address and its path.'),
			RequestFailure::LocalAddress => $this->l10n->t('Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.'),
			RequestFailure::Certificate => $this->l10n->t('Nextcloud does not trust the certificate of Paperless at this URL. If Paperless uses a certificate of its own, import it with occ security:certificates:import.'),
			RequestFailure::Handshake => $this->l10n->t('Nextcloud could not set up an encrypted connection to Paperless at this URL. If Paperless answers plain HTTP at this port, enter the URL with http://.'),
			RequestFailure::UnknownHost => $this->l10n->t('Nextcloud could not find the host of this URL. Check its spelling; the name of a Docker service only works when Nextcloud runs in the same Docker network.'),
			RequestFailure::Timeout => $this->l10n->t('Paperless did not answer in time. Check that it runs and is not overloaded, then try again.'),
			RequestFailure::Unreachable => $this->l10n->t('Nextcloud could not reach Paperless at this URL. Check the address and the port, and that Paperless runs.'),
		};
	}

	/**
	 * The warning about what the account of the API token may do, or may not do, with what
	 * to change; saving and the search work either way.
	 */
	public function forTokenWarning(TokenWarning $warning): string {
		return match ($warning) {
			TokenWarning::Superuser => $this->l10n->t('The API token belongs to a Superuser of Paperless: whoever gets hold of it can change and delete every document. Use the token of an account that may only view documents.'),
			TokenWarning::Staff => $this->l10n->t('The API token belongs to an Admin of Paperless, who may also read its logs: whoever gets hold of the token can too. Use the token of an account that is neither Admin nor Superuser.'),
			TokenWarning::ChangesDocuments => $this->l10n->t('The account of the API token may add, change or delete documents in Paperless: whoever gets hold of the token can too. Use the token of an account that may only view documents.'),
			TokenWarning::HidesCorrespondents => $this->l10n->t('The account of the API token may not view correspondents in Paperless, so results show without them. Give the account the permission View for Correspondents.'),
			TokenWarning::HidesDocumentTypes => $this->l10n->t('The account of the API token may not view document types in Paperless, so results show without them. Give the account the permission View for Document Types.'),
			TokenWarning::HidesTags => $this->l10n->t('The account of the API token may not view tags in Paperless, so results show without them. Give the account the permission View for Tags.'),
			TokenWarning::HidesNamesOfOthers => $this->l10n->t('The account of the API token may not view some correspondents, document types or tags in Paperless, which another user owns, so results show without them. Select them in their lists in Paperless and add the account under Permissions, View.'),
			TokenWarning::HidesUsers => $this->l10n->t('The account of the API token may not view users in Paperless, so the search shows only the files of the archive account, not the documents that Paperless Sync puts into the files of each user. Give the account the permission View for Users.'),
		};
	}

	/**
	 * The warning that Paperless Sync also gives each user the documents that their user of
	 * Paperless may view as files of their own, with what the search still shows.
	 */
	public function forPerUserArchives(): string {
		return $this->l10n->t('Paperless Sync also puts copies of the documents that each user may view in Paperless into their own files, which the search doesn\'t show: it shows the files of the archive account and of the owner of each document.');
	}

	/**
	 * The page that explains the archives of Paperless Sync for each user and what to do,
	 * in German for an administrator who reads German, and else in English, the languages
	 * of the website.
	 */
	public function perUserArchivesHelp(): string {
		return str_starts_with($this->l10n->getLanguageCode(), 'de') ? self::PER_USER_ARCHIVES_HELP_DE : self::PER_USER_ARCHIVES_HELP;
	}
}
