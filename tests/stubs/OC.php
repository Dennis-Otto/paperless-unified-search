<?php

declare(strict_types=1);

/**
 * Minimal runtime stub of the server class through which OCP's App and Server reach the
 * container of the server. Tests that build the app put their own container into it.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class OC {
	public static ?object $server = null;
}
