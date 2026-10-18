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
 * Puts the engine claims fixture in front of the running engine: every claim
 * it makes for the running PCRE2 release, and for the running PHP where the
 * claim depends on it, is checked with preg_match().
 *
 *   php tests/Tools/verify_engine_claims.php [<fixture.json>]
 *
 * A claim the engine contradicts is printed with its pattern, its subject,
 * what the library claims and what the engine does, and the script exits 1;
 * so it does when the fixture holds no claim for the running release.
 *
 * Written for PHP 7.3 and nothing but its bundled extensions: it runs in the
 * official php:7.3-cli image and every later one, where the library itself,
 * which needs PHP 8.1, cannot run. Keep it that way: no arrow function, no
 * typed property, no match, no str_contains().
 */

const ENGINE_CLAIMS_FIXTURE = __DIR__.'/../Fixtures/EngineClaims/engine-claims.json';

/**
 * A string of the fixture: written as is, or as {"hex": "..."} when it is no
 * valid UTF-8.
 *
 * @param mixed $value
 */
function engine_claims_string($value): string
{
    if (is_array($value) && isset($value['hex']) && is_string($value['hex'])) {
        $bytes = hex2bin($value['hex']);

        return false === $bytes ? '' : $bytes;
    }

    return is_string($value) ? $value : '';
}

/**
 * A string as the report shows it: as is when it is printable ASCII, else
 * its bytes in hexadecimal.
 */
function engine_claims_show(string $value): string
{
    if (1 === preg_match('/^[\x20-\x7E]*$/', $value)) {
        return '"'.$value.'"';
    }

    return 'hex:'.bin2hex($value);
}

/**
 * The claim for the running PHP: a claim that depends on the PHP version
 * holds a map from the PHP_VERSION_ID it holds from to the claim.
 *
 * @param mixed $claim
 *
 * @return array{0: bool, 1: mixed} whether a claim applies, and the claim
 */
function engine_claims_for_php($claim, int $phpVersionId): array
{
    if (!is_array($claim) || !isset($claim['php']) || !is_array($claim['php'])) {
        return [true, $claim];
    }

    $found = false;
    $value = null;
    foreach ($claim['php'] as $from => $candidate) {
        if ((int) $from <= $phpVersionId) {
            $found = true;
            $value = $candidate;
        }
    }

    return [$found, $value];
}

/**
 * Runs the pattern and catches the warning PHP raises when PCRE2 refuses to
 * compile it.
 *
 * @return array{result: int|false, warning: string|null, groups: array<mixed>}
 */
function engine_claims_run(string $pattern, string $subject, bool $all = false): array
{
    $groups = [];
    $warning = null;
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = $message;

        return true;
    });

    try {
        if ($all) {
            $result = preg_match_all($pattern, $subject, $groups, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        } else {
            $result = preg_match($pattern, $subject, $groups);
        }
    } finally {
        restore_error_handler();
    }

    return ['result' => false === $result ? false : (int) $result, 'warning' => $warning, 'groups' => $groups];
}

/**
 * Whether the engine compiles the pattern, and the offset it reports when it
 * does not.
 *
 * @return array{compiles: bool, offset: int|null, message: string}
 */
function engine_claims_compile(string $pattern): array
{
    $run = engine_claims_run($pattern, '');
    if (null === $run['warning']) {
        return ['compiles' => true, 'offset' => null, 'message' => ''];
    }

    $offset = 1 === preg_match('/ at offset (\d+)/', $run['warning'], $found) ? (int) $found[1] : null;

    return ['compiles' => false, 'offset' => $offset, 'message' => $run['warning']];
}

/**
 * The verdict as the report spells it.
 *
 * @param mixed $verdict
 */
function engine_claims_verdict($verdict): string
{
    if (true === $verdict) {
        return 'compiles';
    }
    if (is_int($verdict)) {
        return 'refused at offset '.$verdict;
    }

    return 'refused';
}

/**
 * @param array<mixed> $claim
 * @param mixed        $expected the verdict claimed
 *
 * @return string|null what contradicts the claim, null when nothing does
 */
function engine_claims_check_compile(array $claim, $expected): ?string
{
    $pattern = engine_claims_string($claim['pattern']);
    $actual = engine_claims_compile($pattern);
    $expectsCompiling = true === $expected;

    if ($expectsCompiling && $actual['compiles']) {
        return null;
    }
    if (!$expectsCompiling && !$actual['compiles']
        && (!is_int($expected) || null === $actual['offset'] || $actual['offset'] === $expected)) {
        return null;
    }

    return sprintf(
        "compile %s\n    expected: %s\n    actual:   %s",
        engine_claims_show($pattern),
        engine_claims_verdict($expected),
        $actual['compiles'] ? 'compiles' : (null === $actual['offset'] ? 'refused' : 'refused at offset '.$actual['offset']).' ('.$actual['message'].')',
    );
}

/**
 * @param array<mixed> $claim
 * @param mixed        $expected the text the group holds, null for no match,
 *                               false for a pattern the engine refuses
 */
function engine_claims_check_match(array $claim, $expected): ?string
{
    $pattern = engine_claims_string($claim['pattern']);
    $subject = engine_claims_string($claim['subject']);
    $group = isset($claim['group']) && is_int($claim['group']) ? $claim['group'] : 0;
    $refused = false === $expected;
    $expected = null === $expected || $refused ? null : engine_claims_string($expected);

    $run = engine_claims_run($pattern, $subject);
    $groups = $run['groups'];
    if (null !== $run['warning'] || false === $run['result']) {
        if ($refused && null !== $run['warning']) {
            return null;
        }
        $actual = 'an error ('.($run['warning'] ?? 'preg_last_error() '.preg_last_error()).')';
    } elseif ($refused) {
        $actual = 1 === $run['result'] ? 'a match' : 'no match';
    } elseif (0 === $run['result']) {
        if (null === $expected) {
            return null;
        }
        $actual = 'no match';
    } else {
        $captured = isset($groups[$group]) && is_string($groups[$group]) ? $groups[$group] : null;
        if ($captured === $expected) {
            return null;
        }
        $actual = null === $captured ? 'a match without group '.$group : engine_claims_show($captured);
    }

    return sprintf(
        "match %s on %s%s\n    expected: %s\n    actual:   %s",
        engine_claims_show($pattern),
        engine_claims_show($subject),
        0 === $group ? '' : ', group '.$group,
        $refused ? 'an error' : (null === $expected ? 'no match' : engine_claims_show($expected)),
        $actual,
    );
}

/**
 * Both patterns find the same matches, at the same offsets, with the same
 * groups, in every subject; a pattern the engine refuses makes no claim.
 *
 * @param array<mixed> $claim
 *
 * @return string|false|null what contradicts the claim, null when nothing
 *                           does, false when the claim does not apply
 */
function engine_claims_check_same(array $claim)
{
    $pattern = engine_claims_string($claim['pattern']);
    $rewrite = engine_claims_string($claim['same_as']);
    if (!engine_claims_compile($pattern)['compiles']) {
        return false;
    }

    $subjects = isset($claim['subjects']) && is_array($claim['subjects']) ? $claim['subjects'] : [];
    foreach ($subjects as $encoded) {
        $subject = engine_claims_string($encoded);
        $first = engine_claims_run($pattern, $subject, true);
        $second = engine_claims_run($rewrite, $subject, true);
        $before = $first['groups'];
        $after = $second['groups'];
        if ($first['result'] === $second['result'] && $before === $after && (null === $first['warning']) === (null === $second['warning'])) {
            continue;
        }

        return sprintf(
            "same %s and %s on %s\n    expected: the same matches\n    actual:   %s and %s",
            engine_claims_show($pattern),
            engine_claims_show($rewrite),
            engine_claims_show($subject),
            null !== $first['warning'] ? 'an error' : json_encode($before),
            null !== $second['warning'] ? 'an error' : json_encode($after),
        );
    }

    return null;
}

/**
 * Checks every claim for the running engine and prints the report.
 *
 * @return int the exit code
 */
function engine_claims_verify(string $file, string $pcreVersion, int $phpVersionId, string $phpVersion): int
{
    $json = @file_get_contents($file);
    $fixture = false === $json ? null : json_decode($json, true);
    if (!is_array($fixture) || !isset($fixture['claims']) || !is_array($fixture['claims'])) {
        fwrite(\STDERR, sprintf("Cannot read the engine claims in %s.\n", $file));

        return 2;
    }

    $release = 1 === preg_match('/^(\d+)\.(\d+)/', $pcreVersion, $parts) ? ((int) $parts[1]).'.'.$parts[2] : $pcreVersion;
    $releases = [];
    foreach (isset($fixture['releases']) && is_array($fixture['releases']) ? $fixture['releases'] : [] as $listed) {
        if (is_string($listed)) {
            $releases[] = $listed;
        }
    }

    echo sprintf("PHP %s, PCRE2 %s\n", $phpVersion, $pcreVersion);
    if (!in_array($release, $releases, true)) {
        echo sprintf("The fixture holds no claim for PCRE2 %s, only for %s: add the release to the fixture.\n", $release, implode(', ', $releases));

        return 1;
    }

    $checked = 0;
    $skipped = 0;
    $contradicted = [];
    foreach ($fixture['claims'] as $claim) {
        if (!is_array($claim) || !isset($claim['pattern'])) {
            continue;
        }

        if (isset($claim['compile']) || isset($claim['match'])) {
            $kind = isset($claim['compile']) ? 'compile' : 'match';
            if (!is_array($claim[$kind]) || !array_key_exists($release, $claim[$kind])) {
                continue;
            }
            [$applies, $expected] = engine_claims_for_php($claim[$kind][$release], $phpVersionId);
            if (!$applies) {
                ++$skipped;
                continue;
            }
            $failure = 'compile' === $kind ? engine_claims_check_compile($claim, $expected) : engine_claims_check_match($claim, $expected);
        } elseif (isset($claim['same_as'])) {
            if (!isset($claim['releases']) || !is_array($claim['releases']) || !in_array($release, $claim['releases'], true)) {
                continue;
            }
            $failure = engine_claims_check_same($claim);
            if (false === $failure) {
                ++$skipped;
                continue;
            }
        } else {
            continue;
        }

        ++$checked;
        if (null !== $failure) {
            $contradicted[] = $failure.(isset($claim['source']) && is_string($claim['source']) ? "\n    source:   ".$claim['source'] : '');
        }
    }

    foreach ($contradicted as $failure) {
        echo "\nCONTRADICTED ", $failure, "\n";
    }

    echo sprintf("\n%d claims checked for PCRE2 %s, %d contradicted, %d not applicable here.\n", $checked, $release, count($contradicted), $skipped);

    return 0 === $checked || [] !== $contradicted ? 1 : 0;
}

if (isset($argv) && realpath($argv[0]) === __FILE__) {
    exit(engine_claims_verify(isset($argv[1]) ? $argv[1] : ENGINE_CLAIMS_FIXTURE, PCRE_VERSION, PHP_VERSION_ID, PHP_VERSION));
}
