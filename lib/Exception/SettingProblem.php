<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Exception;

/**
 * What is wrong with a setting that an administrator entered. The settings name each
 * problem with a message of its own and answer it as its code.
 */
enum SettingProblem: string {
	/** The URL is none, or its host or its port is invalid. */
	case InvalidUrl = 'invalid_url';
	/** The URL uses neither HTTP nor HTTPS. */
	case UnsupportedScheme = 'unsupported_scheme';
	/** The URL carries credentials, a query or a fragment. */
	case UrlWithExtras = 'url_extras';
	/** The internationalized name of the host has no ASCII form here. */
	case HostNotAscii = 'host_not_ascii';
	/** No token was entered, and none is stored. */
	case TokenRequired = 'token_required';
	/** The token was left blank for another server than the stored one. */
	case TokenForOtherServer = 'token_for_other_server';
	/** No account has the ID entered as the archive account. */
	case UnknownArchiveAccount = 'unknown_archive_account';
}
