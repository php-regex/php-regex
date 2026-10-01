<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\TestUtils;

/**
 * Renders the generated sections of the public PCRE2 conformance page from
 * the committed suite and baseline fixtures, and re-measures that baseline.
 *
 * The rendered output is a pure function of the two fixtures: no dates, no
 * wall-clock, no measurement ordering, so the same fixtures always produce
 * the same bytes and the page cannot drift from the numbers it publishes.
 *
 * @phpstan-type BaselineEntry = array{category: string, libraryVerdict: string, libraryOffset: int|null}
 */
final class Pcre2ConformanceTable
{
    /**
     * Everything from this marker to the end of the page is generated; the
     * hand-written header lives above it.
     */
    public const GENERATION_MARKER = '<!-- pcre2-conformance: generated below - do not edit -->';

    public const SUITE_PATH = __DIR__.'/../Fixtures/Pcre2/suite-cases.json';

    public const BASELINE_PATH = __DIR__.'/../Fixtures/Pcre2/conformance-baseline.json';

    private const FILES = ['testinput1', 'testinput2', 'testinput4', 'testinput5'];

    /**
     * One-line approach per defect class of the fix plan, false-accepts
     * excluded (they get their own section).
     */
    private const FIX_APPROACHES = [
        'crash' => 'validate() failed through something other than the library\'s own lexer or parser exceptions; reproduce each and fix the crash before comparing verdicts.',
        'false-reject' => 'PHP compiles what validate() rejects; teach the lexer or parser the construct.',
        'offset-defect' => 'both reject, at a body offset neither supported PCRE2 release reports. The library may be off by a few bytes, or it may have rejected the pattern for a different reason than PCRE2 did; the PCRE2 error below says which check PCRE2 hit first.',
    ];

    private const CLASS_TITLES = [
        'crash' => 'Crashes',
        'false-reject' => 'False rejects',
        'offset-defect' => 'Offset defects',
    ];

    /**
     * Compile-context notes that change no verdict or offset in the suite,
     * listed beside the differences that do.
     */
    private const CONTEXT_NOTES = [
        'The `u` modifier sets both `PCRE2_UTF` and `PCRE2_UCP` in PHP; pcre2test\'s `utf` modifier sets only `PCRE2_UTF`. The suite\'s `utf` cases are measured with `u`, and no compile verdict or offset in the suite depends on the difference.',
        '`#forbid_utf` at the top of `testinput1` and `testinput2` locks pcre2test out of UTF and UCP for every pattern that follows; PHP has no such lock. The cases are measured without it, and the live cross-check found no verdict or offset that depends on it.',
        'pcre2test\'s `/a` modifier (`ascii_all`) is dropped: it has no PHP equivalent, and at PCRE2 '.Pcre2TestdataExtractor::PCRE2_PIN.' it only restricts what `\\d`, `\\s`, `\\w` and POSIX classes match, never whether a pattern compiles.',
    ];

    /**
     * Re-measures the conformance baseline: every assertable suite case
     * that Regex::validate() does not agree with, in suite order, with its
     * defect class and what the library returned. A shared rejection whose
     * offset differs between the PCRE2 floor release and the pin passes
     * when the library reports either one.
     *
     * @return array<string, BaselineEntry>
     */
    public static function measureBaseline(): array
    {
        $runner = new Pcre2CaseRunner();
        $baseline = [];

        foreach (self::readSuite(self::SUITE_PATH) as $cases) {
            foreach ($cases as $case) {
                if (null !== ($case['skipCategory'] ?? null)) {
                    continue;
                }

                $result = $runner->run($case);
                $outcome = \is_string($result['outcome'] ?? null) ? $result['outcome'] : 'crash';

                if ('pass' === $outcome || 'pass-either-offset' === $outcome) {
                    continue;
                }

                $baseline[self::caseId($case)] = [
                    'category' => $outcome,
                    'libraryVerdict' => \is_string($result['verdict'] ?? null) ? $result['verdict'] : 'reject',
                    'libraryOffset' => \is_int($result['offset'] ?? null) ? $result['offset'] : null,
                ];
            }
        }

        return $baseline;
    }

    /**
     * Renders the generated sections, marker first.
     *
     * @throws \RuntimeException when a fixture is missing or cannot be decoded
     */
    public static function generate(string $suitePath = self::SUITE_PATH, string $baselinePath = self::BASELINE_PATH): string
    {
        $suite = self::readSuite($suitePath);
        $baseline = self::readBaseline($baselinePath);

        $pin = Pcre2TestdataExtractor::PCRE2_PIN;
        $perFile = [];
        $totals = self::emptyCounts();
        $skipCounts = [];
        $adjusted = [];
        $falseAccepts = [];
        $offsetDefects = [];
        $classCounts = [];

        foreach (self::FILES as $file) {
            $counts = self::emptyCounts();

            foreach ($suite[$file] ?? [] as $case) {
                $counts['cases']++;
                $skipCategory = $case['skipCategory'] ?? null;

                if (\is_string($skipCategory)) {
                    $counts['skipped']++;
                    $skipCounts[$skipCategory] = ($skipCounts[$skipCategory] ?? 0) + 1;

                    continue;
                }

                $id = self::caseId($case);
                $expected = self::expectedVerdict($case);
                $category = $baseline[$id]['category'] ?? null;
                $counts['assertable']++;

                if (\is_array($case['phpOverride'] ?? null)) {
                    $adjusted[] = $id;
                }

                if (null !== $category) {
                    $classCounts[$category] = ($classCounts[$category] ?? 0) + 1;
                }

                // The verdict agrees unless validate() accepted a pattern
                // PHP rejects or rejected one PHP compiles.
                $verdictAgrees = !\in_array($category, ['false-accept', 'false-reject'], true)
                    && !('crash' === $category && 'accept' === $expected);

                if ($verdictAgrees) {
                    $counts['verdictAgrees']++;
                }

                // Every shared rejection is scored on its offset. Where the
                // PCRE2 floor release reports another offset than the pin,
                // an agreement may match either one; those are counted too.
                if ('reject' === $expected && $verdictAgrees) {
                    $counts['sharedRejections']++;

                    if (null === $category) {
                        $counts['offsetAgrees']++;

                        if (!self::offsetIsVersionStable($case)) {
                            $counts['versionDependent']++;
                        }
                    }
                }

                if ('false-accept' === $category) {
                    $counts['falseAccepts']++;
                    $falseAccepts[] = $case;
                }

                if ('offset-defect' === $category) {
                    $offsetDefects[] = $case;
                }
            }

            $perFile[$file] = $counts;

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
        }

        $lines = [
            self::GENERATION_MARKER,
            '',
            \sprintf(
                'Against PCRE2 %s\'s official test suite, under PHP\'s compile options: compile verdict agrees on **%d of %d** extractable cases, error offset agrees on **%d of %d** shared rejections (**%d** of them match one of two version-dependent offsets); **%d** patterns PHP rejects are accepted (%d suite verdict%s adjusted to PHP, %d cases skipped — see the breakdown below).',
                $pin,
                $totals['verdictAgrees'],
                $totals['assertable'],
                $totals['offsetAgrees'],
                $totals['sharedRejections'],
                $totals['versionDependent'],
                $totals['falseAccepts'],
                \count($adjusted),
                1 === \count($adjusted) ? '' : 's',
                $totals['skipped'],
            ),
            '',
            '## Source',
            '',
            \sprintf('- Official PCRE2 test suite, tag `pcre2-%s`, vendored under `tests/Fixtures/Pcre2/testdata`', $pin),
            '- Files: `testinput1`, `testinput2`, `testinput4`, `testinput5` and their pinned `testoutput*` records',
            '- License: BSD 3-Clause with the PCRE2 exception (`testdata/LICENCE.md`)',
            '- Scope: compilation verdict and error offset only; subject-level behaviour is not measured',
            \sprintf('- Every expected verdict and offset was checked against `preg_match` on PHP linked to PCRE2 %s when the case set was extracted', $pin),
            \sprintf('- Every extractable case was also compiled by a real PCRE2 %s `pcre2test` (the oldest PCRE2 a supported PHP ships), under PHP\'s compile options; cases whose verdict differs between the two releases are skipped, and where a shared rejection\'s offset differs between them, the library\'s offset agrees when it matches either one', Pcre2TestdataExtractor::PCRE2_FLOOR),
            '',
            '## Per-file counts',
            '',
            '| file | cases | skipped | extractable | verdict agrees | shared rejections | offset agrees | of which one of two version-dependent offsets | false accepts |',
            '|---|---:|---:|---:|---:|---:|---:|---:|---:|',
        ];

        foreach ($perFile as $file => $counts) {
            $lines[] = self::countsRow('`'.$file.'`', $counts, false);
        }

        $lines[] = self::countsRow('**total**', $totals, true);

        $lines[] = '';
        $lines[] = '## PHP compile context';
        $lines[] = '';
        $lines[] = 'The expected outcomes model PHP\'s compile context, not pcre2test\'s. Where the two differ in a way that changes a suite verdict, the case keeps the suite\'s record and is measured against what `preg_match` did instead.';
        $lines[] = '';
        $lines[] = '| difference | suite error it explains |';
        $lines[] = '|---|---:|';

        $notes = Pcre2LiveCrossCheck::contextDifferenceNotes();

        foreach (Pcre2LiveCrossCheck::contextDifferences() as $reason => $code) {
            $lines[] = \sprintf('| `%s`: %s | %d |', $reason, $notes[$reason] ?? '', $code);
        }

        $lines[] = '';

        foreach (self::CONTEXT_NOTES as $note) {
            $lines[] = '- '.$note;
        }

        $lines[] = '';
        $lines[] = \sprintf('Adjusted cases (%d): %s.', \count($adjusted), [] === $adjusted ? 'none' : '`'.implode('`, `', $adjusted).'`');

        $lines[] = '';
        $lines[] = '## Skipped cases by category';
        $lines[] = '';

        if ([] === $skipCounts) {
            $lines[] = '_None._';
        } else {
            ksort($skipCounts, \SORT_STRING);
            $lines[] = '| category | meaning | cases |';
            $lines[] = '|---|---|---:|';

            foreach ($skipCounts as $category => $count) {
                $lines[] = \sprintf('| `%s` | %s | %d |', $category, self::skipDescriptions()[$category] ?? '', $count);
            }
        }

        $lines[] = '';
        $lines[] = '## Gap breakdown by defect class';
        $lines[] = '';

        if ([] === $classCounts) {
            $lines[] = '_No known gaps: every extractable case agrees with the expected outcome._';
        } else {
            $lines[] = '| defect class | cases |';
            $lines[] = '|---|---:|';

            foreach (self::sortedByCount($classCounts) as $class => $count) {
                $lines[] = \sprintf('| `%s` | %d |', $class, $count);
            }
        }

        $lines[] = '';
        $lines[] = '## Fix plan';
        $lines[] = '';
        $lines[] = \sprintf(
            'False accepts come first: a static analyser that blesses a pattern PHP refuses to compile is the worst outcome for its users. The other classes follow by case count, largest first. Cases whose verdict differs between PCRE2 %s (the oldest a supported PHP ships) and %s are skipped as `newer-than-floor` or `stricter-than-floor` and excluded from this plan by design: no single verdict is right on both engines, so fixing them one way would make `validate()` wrong on the other.',
            Pcre2TestdataExtractor::PCRE2_FLOOR,
            $pin,
        );
        $lines[] = '';
        $lines[] = '### False accepts';
        $lines[] = '';

        if ([] === $falseAccepts) {
            $lines[] = '_None._';
        } else {
            $lines[] = \sprintf(
                '%d pattern%s PHP rejects %s accepted by `validate()`. Add the missing compile-time check for each PCRE2 error below.',
                \count($falseAccepts),
                1 === \count($falseAccepts) ? '' : 's',
                1 === \count($falseAccepts) ? 'is' : 'are',
            );
            $lines[] = '';
            $lines = array_merge($lines, self::errorGroupTable($falseAccepts));
        }

        $otherClasses = $classCounts;
        unset($otherClasses['false-accept']);

        foreach (self::sortedByCount($otherClasses) as $class => $count) {
            $lines[] = '';
            $lines[] = '### '.(self::CLASS_TITLES[$class] ?? $class);
            $lines[] = '';
            $lines[] = \sprintf(
                '%d case%s: %s',
                $count,
                1 === $count ? '' : 's',
                self::FIX_APPROACHES[$class] ?? 'triage this class into a concrete plan.',
            );

            if ('offset-defect' === $class) {
                $lines[] = '';
                $lines = array_merge($lines, self::errorGroupTable($offsetDefects));
            }
        }

        $lines[] = '';
        $lines[] = '## Regenerating this table';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = \sprintf('php tests/Tools/extract_pcre2_testdata.php --floor-pcre2test=/tmp/pcre2-%s/pcre2test', Pcre2TestdataExtractor::PCRE2_FLOOR);
        $lines[] = 'php tests/Tools/generate_pcre2_conformance_table.php --baseline';
        $lines[] = \sprintf('php tests/Tools/verify_pcre2_fixture.php --floor-pcre2test=/tmp/pcre2-%s/pcre2test --pin-pcre2test=/tmp/pcre2-%s/pcre2test', Pcre2TestdataExtractor::PCRE2_FLOOR, $pin);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = \sprintf(
            'The extraction runs every case through `preg_match` and refuses to write unless the PHP running it is linked to PCRE2 %s and every expected verdict and offset matches what that engine does. It also compiles every extractable case with the given `pcre2test`, which must be exactly PCRE2 %s (build it from the release tarball, see `tests/Fixtures/Pcre2/README.md`). The second command re-measures the baseline of known gaps, then rewrites the sections below the generation marker of this page; it prints its wall-clock time and peak memory. The third re-observes every recorded engine result on both releases, built from their tarballs, and fails on any difference.',
            $pin,
            Pcre2TestdataExtractor::PCRE2_FLOOR,
        );
        $lines[] = '';
        $lines[] = 'Per-case detail lives in `tests/Fixtures/Pcre2/conformance-baseline.json`, an internal, free-to-change format: only the counts on this page are published.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Writes a measured baseline in its canonical serialization.
     *
     * @param array<string, BaselineEntry> $baseline
     */
    public static function writeBaseline(array $baseline, string $path = self::BASELINE_PATH): void
    {
        $json = json_encode($baseline, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        if (false === file_put_contents($path, $json."\n")) {
            throw new \RuntimeException(\sprintf('Unable to write %s.', $path));
        }
    }

    /**
     * One-line meaning of each skip category, for the skip table.
     *
     * @return array<string, string>
     */
    private static function skipDescriptions(): array
    {
        $floor = Pcre2TestdataExtractor::PCRE2_FLOOR;
        $pin = Pcre2TestdataExtractor::PCRE2_PIN;

        return [
            'ambiguous' => 'the testoutput record carries no readable compile verdict, or the body cannot be stored in the fixture',
            'engine-skipped' => 'pcre2test itself skipped the case',
            'length-limit' => 'the pattern is longer than the library\'s default maximum pattern length',
            'modifier-inexpressible' => 'a compile-visible pcre2test modifier with no PHP pattern-modifier equivalent',
            'newer-than-floor' => \sprintf('PCRE2 %s (the oldest a supported PHP ships) rejects a pattern PHP on %s compiles, or the case uses a pcre2test modifier added after %s', $floor, $pin, $floor),
            'newline-command' => 'the case sets a newline convention PHP pattern strings cannot express',
            'pcre2test-api' => 'the case drives pcre2test machinery PHP does not expose, or rewrites the pattern text',
            'php-inexpressible' => 'no PHP pattern string can carry the body',
            'stricter-than-floor' => \sprintf('PCRE2 %s compiles a pattern PHP on %s rejects', $floor, $pin),
        ];
    }

    /**
     * @return array{cases: int, skipped: int, assertable: int, verdictAgrees: int, sharedRejections: int, offsetAgrees: int, versionDependent: int, falseAccepts: int}
     */
    private static function emptyCounts(): array
    {
        return [
            'cases' => 0,
            'skipped' => 0,
            'assertable' => 0,
            'verdictAgrees' => 0,
            'sharedRejections' => 0,
            'offsetAgrees' => 0,
            'versionDependent' => 0,
            'falseAccepts' => 0,
        ];
    }

    /**
     * @param array{cases: int, skipped: int, assertable: int, verdictAgrees: int, sharedRejections: int, offsetAgrees: int, versionDependent: int, falseAccepts: int} $counts
     */
    private static function countsRow(string $label, array $counts, bool $bold): string
    {
        $cells = [$label];

        foreach ($counts as $value) {
            $cells[] = $bold ? '**'.$value.'**' : (string) $value;
        }

        return '| '.implode(' | ', $cells).' |';
    }

    /**
     * Groups cases by the PCRE2 error their expected rejection carries,
     * largest group first, with the error text of the group's first case.
     *
     * @param list<array<string, mixed>> $cases
     *
     * @return list<string>
     */
    private static function errorGroupTable(array $cases): array
    {
        $counts = [];
        $messages = [];

        foreach ($cases as $case) {
            [$code, $message] = self::expectedError($case);
            $key = null === $code ? 'none' : (string) $code;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $messages[$key] ??= $message;
        }

        $lines = ['| PCRE2 error | first recorded message | cases |', '|---:|---|---:|'];

        foreach (self::sortedByCount($counts) as $key => $count) {
            $lines[] = \sprintf(
                '| %s | %s | %d |',
                'none' === $key ? '—' : $key,
                self::tableCell($messages[$key] ?? ''),
                $count,
            );
        }

        return $lines;
    }

    /**
     * Sorts a count map by count descending, then key ascending, so ties
     * never depend on measurement order.
     *
     * @param array<array-key, int> $counts
     *
     * @return array<string, int>
     */
    private static function sortedByCount(array $counts): array
    {
        $keys = array_map(strval(...), array_keys($counts));
        $values = array_values($counts);
        $sorted = array_combine($keys, $values);

        uksort($sorted, static fn (string $a, string $b): int => [$sorted[$b], $a] <=> [$sorted[$a], $b]);

        return $sorted;
    }

    private static function tableCell(string $text): string
    {
        return str_replace(['\\', '|', "\n"], ['\\\\', '\\|', ' '], $text);
    }

    /**
     * The expected verdict of an assertable case, phpOverride first.
     *
     * @param array<string, mixed> $case
     */
    private static function expectedVerdict(array $case): ?string
    {
        $override = $case['phpOverride'] ?? null;
        $verdict = \is_array($override) ? ($override['verdict'] ?? null) : ($case['verdict'] ?? null);

        return \is_string($verdict) ? $verdict : null;
    }

    /**
     * The expected PCRE2 error number and message of a case: the suite's,
     * unless a phpOverride replaced the verdict (its live observation
     * carries no error number).
     *
     * @param array<string, mixed> $case
     *
     * @return array{0: int|null, 1: string}
     */
    private static function expectedError(array $case): array
    {
        $override = $case['phpOverride'] ?? null;

        if (\is_array($override)) {
            $code = $override['pcre2Code'] ?? null;

            return [\is_int($code) ? $code : null, ''];
        }

        $code = $case['pcre2Code'] ?? null;
        $message = $case['error'] ?? null;

        return [\is_int($code) ? $code : null, \is_string($message) ? $message : ''];
    }

    /**
     * Whether the PCRE2 floor release reported a shared rejection at the
     * same offset as the pin (true when there is no floor observation).
     *
     * @param array<string, mixed> $case
     */
    private static function offsetIsVersionStable(array $case): bool
    {
        $floor = $case['floor'] ?? null;

        if (!\is_array($floor)) {
            return true;
        }

        $override = $case['phpOverride'] ?? null;
        $expectedOffset = \is_array($override) ? ($override['offset'] ?? null) : ($case['offset'] ?? null);

        return ($floor['offset'] ?? null) === $expectedOffset;
    }

    /**
     * @param array<string, mixed> $case
     */
    private static function caseId(array $case): string
    {
        return \is_string($case['id'] ?? null) ? $case['id'] : '?';
    }

    /**
     * Reads the committed suite cases per file, without the provenance
     * record.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function readSuite(string $path): array
    {
        $decoded = self::readJson($path);
        unset($decoded['meta']);

        $suite = [];

        foreach ($decoded as $file => $cases) {
            if (!\is_array($cases)) {
                continue;
            }

            $rows = [];

            foreach ($cases as $case) {
                if (!\is_array($case)) {
                    continue;
                }

                $row = [];

                foreach ($case as $field => $value) {
                    if (\is_string($field)) {
                        $row[$field] = $value;
                    }
                }

                $rows[] = $row;
            }

            $suite[(string) $file] = $rows;
        }

        return $suite;
    }

    /**
     * Reads the committed conformance baseline of known gaps.
     *
     * @return array<string, BaselineEntry>
     */
    private static function readBaseline(string $path): array
    {
        $baseline = [];

        foreach (self::readJson($path) as $id => $entry) {
            if (\is_array($entry) && \is_string($entry['category'] ?? null)) {
                $baseline[(string) $id] = [
                    'category' => $entry['category'],
                    'libraryVerdict' => \is_string($entry['libraryVerdict'] ?? null) ? $entry['libraryVerdict'] : 'reject',
                    'libraryOffset' => \is_int($entry['libraryOffset'] ?? null) ? $entry['libraryOffset'] : null,
                ];
            }
        }

        return $baseline;
    }

    /**
     * Decodes a committed fixture. A missing, unreadable or undecodable file
     * throws: a page of zeros must never be published.
     *
     * @throws \RuntimeException
     *
     * @return array<array-key, mixed>
     */
    private static function readJson(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;

        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Missing fixture %s: regenerate it before rendering the conformance page.', $path));
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('Fixture %s is not valid JSON: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Fixture %s does not hold a JSON object.', $path));
        }

        return $decoded;
    }
}
