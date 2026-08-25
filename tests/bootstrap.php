<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/stubs/Emitter.php';
require_once __DIR__ . '/../vendor/autoload.php';

$ocpRoot = __DIR__ . '/../vendor/nextcloud/ocp/';
spl_autoload_register(static function (string $class) use ($ocpRoot): void {
	foreach (['OCP\\' => 'OCP/', 'NCU\\' => 'NCU/'] as $prefix => $directory) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}

		$relativeClass = substr($class, strlen($prefix));
		$file = $ocpRoot . $directory . str_replace('\\', '/', $relativeClass) . '.php';
		if (is_file($file)) {
			require_once $file;
		}

		return;
	}
});
