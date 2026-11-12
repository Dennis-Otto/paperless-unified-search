<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use Exception;
use OCP\Security\ICrypto;

/**
 * The encryption of Nextcloud as the app meets it: a text that only the same key opens,
 * and that fails to open once a character of it changed. Nextcloud encrypts with AES and
 * authenticates with an HMAC; this one only authenticates, so a test can't see whether
 * the text is unreadable, and the tests of the cursors read none.
 */
final class KeyedCrypto implements ICrypto {
	public function calculateHMAC(string $message, string $password = ''): string {
		return hash_hmac('sha256', $message, $password, true);
	}

	public function encrypt(string $plaintext, string $password = ''): string {
		return base64_encode($plaintext) . '|' . bin2hex($this->calculateHMAC($plaintext, $password));
	}

	public function decrypt(string $authenticatedCiphertext, string $password = ''): string {
		[$text, $hmac] = explode('|', $authenticatedCiphertext, 2) + ['', ''];
		$plaintext = base64_decode($text, true);
		if ($plaintext === false || !hash_equals(bin2hex($this->calculateHMAC($plaintext, $password)), $hmac)) {
			throw new Exception('HMAC does not match.');
		}

		return $plaintext;
	}
}
