<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Exception;

/**
 * Why a request to Paperless failed. The test of the connection names each reason with a
 * message of its own, which says what to correct, and answers it as its code.
 */
enum RequestFailure: string {
	/** Paperless refused the token, with HTTP 401 or 403. */
	case Unauthorized = 'unauthorized';
	/** The URL answers 404: its path, or its host, isn't the one of Paperless. */
	case NotFound = 'not_found';
	/** The URL answers with a redirect that the request doesn't follow. */
	case Redirect = 'redirect';
	/** Paperless doesn't speak version 9 of its API, as before 2.16, with HTTP 406. */
	case UnsupportedVersion = 'unsupported_version';
	/** Any other status outside 2xx, such as 500 or 502. */
	case Http = 'http';
	/** An answer of 2xx that isn't a list of documents of Paperless, such as a page of HTML. */
	case NotPaperless = 'not_paperless';
	/** Nextcloud refused the address as one of the local network. */
	case LocalAddress = 'local_address';
	/** Nextcloud doesn't trust the certificate of the server. */
	case Certificate = 'tls';
	/** Nextcloud couldn't set up an encrypted connection, as when the port answers plain HTTP. */
	case Handshake = 'tls_handshake';
	/** The name of the host doesn't resolve. */
	case UnknownHost = 'unknown_host';
	/** The connection or the answer took too long. */
	case Timeout = 'timeout';
	/** Any other failure of the connection, such as a refused one. */
	case Unreachable = 'unreachable';
}
