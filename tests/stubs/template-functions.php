<?php

declare(strict_types=1);

/**
 * The functions that Nextcloud gives its templates (lib/private/legacy/template/functions.php),
 * as far as the templates of the app use them, for the tests that render a template.
 * p() escapes as Nextcloud's does, with \OCP\Util::sanitizeHTML(); script() and style()
 * note what the template loads.
 *
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class TemplateAssets {
	/** @var list<string> the scripts and the styles that the template loaded, such as "script app/settings" */
	public static array $loaded = [];
}

function p(mixed $string): void {
	print(htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8'));
}

function print_unescaped(mixed $string): void {
	print((string)$string);
}

function script(string $app, string $file): void {
	TemplateAssets::$loaded[] = "script {$app}/{$file}";
}

function style(string $app, string $file): void {
	TemplateAssets::$loaded[] = "style {$app}/{$file}";
}
