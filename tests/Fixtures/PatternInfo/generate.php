<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Regenerates pcre2test.out: what pcre2test "/I" prints for every pattern of
 * patterns.txt, compiled as PHP 8.4 compiles it.
 *
 * Usage: php tests/Fixtures/PatternInfo/generate.php [<pcre2test>]
 *
 * <pcre2test> defaults to "pcre2test" on the PATH. The committed output was
 * written by PCRE2 10.49:
 *
 *     php tests/Fixtures/PatternInfo/generate.php /opt/homebrew/bin/pcre2test
 *
 * Each PHP modifier becomes the pcre2test modifier PHP passes to PCRE2 ("u"
 * is UTF and UCP; "S" and "X" pass nothing), and every pattern carries
 * allow_lookaround_bsk, as PHP 8.2 to 8.4 compile with
 * PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK. The output keeps pcre2test's own text:
 * PatternInfoPcre2ParityTest reads it block by block, in pattern order.
 *
 * Exit code 0: written. 1: a pattern cannot be converted, or pcre2test failed.
 */

const MODIFIERS = [
    'i' => 'caseless',
    'm' => 'multiline',
    's' => 'dotall',
    'x' => 'extended',
    'A' => 'anchored',
    'D' => 'dollar_endonly',
    'U' => 'ungreedy',
    'J' => 'dupnames',
    'u' => 'utf,ucp',
    'n' => 'no_auto_capture',
    'r' => 'caseless_restrict',
    'S' => null,
    'X' => null,
];

$argv = $_SERVER['argv'] ?? [];
$binary = is_array($argv) && isset($argv[1]) && is_string($argv[1]) ? $argv[1] : 'pcre2test';

$input = '';
foreach (file(__DIR__.'/patterns.txt', \FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
    if ('' === trim($line) || str_starts_with($line, '#')) {
        continue;
    }

    $end = strrpos($line, '/');
    if (!str_starts_with($line, '/') || false === $end || 0 === $end) {
        fwrite(\STDERR, sprintf("patterns.txt:%d is not delimited by \"/\": %s\n", $number + 1, $line));

        exit(1);
    }

    $modifiers = ['allow_lookaround_bsk'];
    foreach (str_split(substr($line, $end + 1)) as $modifier) {
        if ('' === $modifier) {
            continue;
        }
        if (!array_key_exists($modifier, MODIFIERS)) {
            fwrite(\STDERR, sprintf("patterns.txt:%d has a modifier pcre2test has no name for: %s\n", $number + 1, $modifier));

            exit(1);
        }
        if (null !== MODIFIERS[$modifier]) {
            $modifiers[] = MODIFIERS[$modifier];
        }
    }

    $input .= substr($line, 0, $end + 1).'I,'.implode(',', $modifiers)."\n\n";
}

$file = tempnam(sys_get_temp_dir(), 'pattern-info-');
if (false === $file) {
    fwrite(\STDERR, "Cannot create a temporary file.\n");

    exit(1);
}
file_put_contents($file, $input);

exec(escapeshellarg($binary).' '.escapeshellarg($file).' 2>&1', $output, $status);
unlink($file);

if (0 !== $status) {
    fwrite(\STDERR, implode("\n", $output)."\n");

    exit(1);
}

file_put_contents(__DIR__.'/pcre2test.out', implode("\n", $output)."\n");
fwrite(\STDOUT, sprintf("Wrote %s (%s).\n", __DIR__.'/pcre2test.out', $output[0] ?? 'no version line'));
