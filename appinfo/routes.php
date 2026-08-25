<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'settings#save', 'verb' => 'POST', 'url' => '/settings'],
		['name' => 'settings#reset', 'verb' => 'DELETE', 'url' => '/settings'],
	],
];
