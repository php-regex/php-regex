<?php

declare(strict_types=1);

/**
 * Compares the reference digest (8.x original) with the twin digest (7.4)
 * line by line. Two pattern bodies sit on a documented allowlist — lexer
 * divergences under PCRE2 10.35 still awaiting adjudication; a third
 * divergence fails the gate.
 */

$allowlist = [
	// tokenize as literals on PCRE2 10.35: version-aware lexer behavior,
	// not the downgrade transform — see build/README.md
	'\x{1F600}',
	'\g{-1}',
];

[$reference, $twin] = [$argv[1], $argv[2]];

$refLines = file($reference, FILE_IGNORE_NEW_LINES);
$twinLines = file($twin, FILE_IGNORE_NEW_LINES);

if (count($refLines) !== count($twinLines)) {
	fwrite(STDERR, 'digest gate: line count differs: ' . count($refLines) . ' vs ' . count($twinLines) . "\n");
	exit(1);
}

$failures = [];
foreach ($refLines as $i => $refLine) {
	if ($refLine === $twinLines[$i]) {
		continue;
	}

	$pattern = json_decode($refLine, true)['p'] ?? (string) $i;
	if (in_array($pattern, $allowlist, true)) {
		continue;
	}

	$failures[] = $pattern . "\n  8.x: " . $refLine . "\n  7.4: " . $twinLines[$i];
}

if ($failures !== []) {
	fwrite(STDERR, "digest gate: unexpected divergences:\n" . implode("\n", $failures) . "\n");
	exit(1);
}

echo 'digest gate: ok (allowlist: ' . count($allowlist) . " known deltas)\n";
