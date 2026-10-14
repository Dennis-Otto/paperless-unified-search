<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Exception;

use OCP\Http\Client\LocalServerException;
use RuntimeException;
use Throwable;

/**
 * A request to Paperless that failed, with the reason that the settings name. Its message
 * is the technical one, in English, for the log and the history of problems; the settings
 * show a translated message for its reason instead. A search whose query Paperless
 * refuses fails with the subclass PaperlessQueryRejected, which is no problem of the search.
 */
class PaperlessRequestException extends RuntimeException {
	/** The errors of cURL about the certificate of the server. */
	private const CERTIFICATE_ERRORS = [51, 60, 77, 83];
	/** The errors of cURL that fail the encryption before any certificate is checked. */
	private const HANDSHAKE_ERRORS = [35, 58, 59];
	private const UNKNOWN_HOST_ERROR = 6;
	private const TIMEOUT_ERROR = 28;
	/** How the DNS pinning of Nextcloud begins the message for a name that doesn't resolve. */
	private const NO_DNS_RECORD = 'No DNS record found for ';

	/**
	 * @param ?int $status the status of the answer of Paperless, null without an answer
	 */
	public function __construct(
		public readonly RequestFailure $reason,
		string $message,
		public readonly ?int $status = null,
		?Throwable $previous = null,
	) {
		parent::__construct($message, previous: $previous);
	}

	/**
	 * For an answer whose status isn't 2xx.
	 */
	public static function forStatus(int $status): self {
		$reason = match (true) {
			$status === 401, $status === 403 => RequestFailure::Unauthorized,
			$status === 404 => RequestFailure::NotFound,
			$status === 406 => RequestFailure::UnsupportedVersion,
			$status >= 300 && $status < 400 => RequestFailure::Redirect,
			default => RequestFailure::Http,
		};

		return new self($reason, 'Paperless returned HTTP ' . $status . '.', $status);
	}

	/**
	 * For an answer of 2xx that isn't a list of documents of Paperless.
	 */
	public static function notPaperless(string $message, ?Throwable $previous = null): self {
		return new self(RequestFailure::NotPaperless, $message, null, $previous);
	}

	/**
	 * For a request that got no answer, with the message of its error: Nextcloud refused
	 * the address, or cURL failed, which Guzzle reports as "cURL error 60: …".
	 */
	public static function unanswered(Throwable $error): self {
		return new self(self::reasonOf($error), $error->getMessage(), null, $error);
	}

	/**
	 * Whether Nextcloud refused the address of a request as one of the local network, maybe
	 * inside another error, which it would refuse again; not for a name that doesn't
	 * resolve, which Nextcloud reports with the same exception.
	 */
	public static function isRefusedAddress(Throwable $error): bool {
		for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof LocalServerException) {
				return !self::isUnknownName($cause);
			}
		}

		return false;
	}

	/**
	 * With dns_pinning on, as by default, Nextcloud resolves the name itself for a request
	 * without local addresses and reports a name without a record of DNS with the exception
	 * of a local address too: that one is a wrong name, or a lookup that failed for a
	 * moment, not a local address.
	 */
	private static function isUnknownName(LocalServerException $error): bool {
		return str_starts_with($error->getMessage(), self::NO_DNS_RECORD);
	}

	private static function reasonOf(Throwable $error): RequestFailure {
		for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof LocalServerException) {
				return self::isUnknownName($cause) ? RequestFailure::UnknownHost : RequestFailure::LocalAddress;
			}

			if (preg_match('/\bcURL error (\d+)\b/', $cause->getMessage(), $match) === 1) {
				return self::reasonOfCurlError((int)$match[1]);
			}
		}

		return RequestFailure::Unreachable;
	}

	private static function reasonOfCurlError(int $error): RequestFailure {
		return match (true) {
			$error === self::UNKNOWN_HOST_ERROR => RequestFailure::UnknownHost,
			$error === self::TIMEOUT_ERROR => RequestFailure::Timeout,
			in_array($error, self::CERTIFICATE_ERRORS, true) => RequestFailure::Certificate,
			in_array($error, self::HANDSHAKE_ERRORS, true) => RequestFailure::Handshake,
			default => RequestFailure::Unreachable,
		};
	}
}
