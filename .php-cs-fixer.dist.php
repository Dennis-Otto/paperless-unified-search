<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
// Only what Git keeps: no file that a .gitignore of the app ignores, such as vendor/,
// build/ or the node_modules/ of a tool behind a .gitignore of its own.
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->in(__DIR__);

return $config;
