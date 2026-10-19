<?php

declare(strict_types=1);

/**
 * Compares the findings of the taint analysis of the canary, from the JSON report of
 * Psalm, with what the canary expects: a finding at each line of canary/ that ends in
 * "// expect: <issue>", and none at any other.
 *
 *   php .github/psalm/check-canary.php build/psalm-canary.json
 *
 * The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
 */

$report = $argv[1] ?? '';
$issues = is_file($report) ? json_decode((string)file_get_contents($report), true) : null;
if (!is_array($issues)) {
	fwrite(STDERR, "Psalm wrote no report of the canary to $report; its output above says why.\n");
	exit(1);
}

$expected = [];
foreach (glob(__DIR__ . '/canary/*.php') ?: [] as $file) {
	foreach (file($file) ?: [] as $offset => $line) {
		if (preg_match('#// expect: (\w+)\s*$#', $line, $match) === 1) {
			$expected[] = sprintf('%s:%d %s', basename($file), $offset + 1, $match[1]);
		}
	}
}
$found = [];
foreach ($issues as $issue) {
	$found[] = sprintf('%s:%d %s', basename((string)$issue['file_path']), (int)$issue['line_from'], (string)$issue['type']);
}

$missed = array_values(array_diff($expected, $found));
$unexpected = array_values(array_diff($found, $expected));
foreach ($missed as $finding) {
	echo "The taint analysis missed the path that the canary expects at $finding.\n";
}
foreach ($unexpected as $finding) {
	echo "The taint analysis found a path at $finding, which the canary doesn't expect.\n";
}
if ($expected === [] || $missed !== [] || $unexpected !== []) {
	exit(1);
}
printf("The taint analysis finds each of the %d paths of the canary, and no other.\n", count($expected));
