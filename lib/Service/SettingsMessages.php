<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Exception\SettingProblem;
use OCP\IL10N;

/**
 * What the settings page and occ say when the settings can't be saved or Paperless can't
 * be reached: what failed and what to correct, in the language of the administrator, or
 * of Nextcloud for occ. Each text stands in a call of t() of its own, where
 * scripts/check-l10n.mjs finds it.
 */
final class SettingsMessages {
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
		};
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
}
