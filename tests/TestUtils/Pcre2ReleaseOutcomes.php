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

namespace PHPRegex\Tests\TestUtils;

/**
 * What each PCRE2 release does with the compilation cases of every release's
 * own test suite, where the releases do not all agree.
 *
 * The cases are those of the suite files that apply to PHP (testinput1 to 10,
 * 14 to 22, 26 and 27, where a release has them), read by the extractor of
 * the pinned suite, for every release from 10.32 to 10.49; a case several
 * suites hold is one case, named after the newest release that holds it.
 * Each case is compiled by a pcre2test of every release, under PHP's compile
 * context: every PHP flag as its pcre2test modifier ("u" setting UTF and
 * UCP), allow_lookaround_bsk from 10.38, as PHP 8.1 and later compile; and
 * for 10.32 to 10.35, once more as PHP 7.3 and 7.4 compile, with
 * bad_escape_is_literal unless the flags hold "X", and without it. A case
 * whose outcome (the verdict, the offset, the PCRE2 error number) moves
 * between two releases, or between PHP 7 and PHP 8 on one release, is kept,
 * with its outcome on each release as runs: the release from which an
 * outcome holds, then the next one that changes it.
 *
 * A case is not compiled as PHP 7 when PHP 7 cannot express it: a NUL in
 * the body (refused before PHP 8.2) or the "n" modifier (PHP 8.2). The "r"
 * modifier, caseless_restrict, is compiled from 10.43, where it arrived.
 *
 * @phpstan-type Outcome = array{0: int, 1: int}|null
 * @phpstan-type Observation = array{verdict: string, offset?: int, code?: int, message?: string}
 * @phpstan-type SuiteCase = array{id: string, pattern: string, delimiter: string, flags: string}
 * @phpstan-type ReleaseCase = array{id: string, pattern: string, delimiter: string, flags: string, php: array<string, Outcome>, php7?: array<string, Outcome>|false}
 * @phpstan-type Measure = array{fixture: array{meta: array<string, mixed>, cases: list<ReleaseCase>}, cases: list<SuiteCase>, binaries: array<string, string>, measured: array<string, int>, measuredAsPhp7: array<string, int>}
 */
final class Pcre2ReleaseOutcomes
{
    public const FIXTURE = __DIR__.'/../Fixtures/Pcre2/release-outcomes.json';

    /**
     * The releases measured, oldest first: 10.32, bundled by PHP 7.3, to the
     * newest release the library knows.
     */
    public const RELEASES = [
        '10.32', '10.33', '10.34', '10.35', '10.36', '10.37', '10.38', '10.39', '10.40',
        '10.41', '10.42', '10.43', '10.44', '10.45', '10.46', '10.47', '10.48', '10.49',
    ];

    /**
     * The releases PHP 7.3 and 7.4 link, with the PHP_VERSION_ID judged:
     * 7.3 bundles 10.32, 7.4 bundles 10.33 to 10.35.
     */
    public const PHP7 = ['10.32' => 70300, '10.33' => 70400, '10.34' => 70400, '10.35' => 70400];

    /**
     * The PHP_VERSION_ID every release is judged with otherwise: PHP 8.4,
     * which compiles with PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK and links any
     * PCRE2 release.
     */
    public const PHP = 80400;

    /**
     * The suite files that apply to PHP, which links the 8-bit library with
     * "\C" supported: not 11 to 13, for the 16 and 32-bit libraries, nor 23,
     * for a build without "\C", nor 24 and 25, which convert patterns.
     */
    public const FILES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 14, 15, 16, 17, 18, 19, 20, 21, 22, 26, 27];

    private const FLAG_MODIFIERS = [
        'i' => 'caseless',
        'm' => 'multiline',
        's' => 'dotall',
        'x' => 'extended',
        'u' => 'utf,ucp',
        'U' => 'ungreedy',
        'J' => 'dupnames',
        'D' => 'dollar_endonly',
        'A' => 'anchored',
        'n' => 'no_auto_capture',
        'X' => '',
        'r' => 'caseless_restrict',
    ];

    private const SEPARATOR = '/pcre2-release-outcomes-%d/';

    /**
     * The release number of "10.45": 10045.
     */
    public static function number(string $release): int
    {
        [$major, $minor] = explode('.', $release) + [1 => '0'];

        return (int) $major * 1000 + (int) $minor;
    }

    /**
     * The output file pcre2test's own test script compares a testinput file
     * with, for the 8-bit library and link size 2.
     */
    public static function outputFile(string $testdata, int $number): ?string
    {
        foreach (['testoutput'.$number, 'testoutput'.$number.'-8', 'testoutput'.$number.'-8-2'] as $name) {
            if (is_file($testdata.'/'.$name)) {
                return $testdata.'/'.$name;
            }
        }

        return null;
    }

    /**
     * The pcre2test pattern line compiling a case as PHP compiles it.
     *
     * @param bool $php7 compile as PHP 7.3 and 7.4 do
     */
    public static function line(string $body, string $delimiter, string $flags, string $release, bool $php7): string
    {
        $modifiers = [];
        foreach ('' === $flags ? [] : str_split($flags) as $flag) {
            $modifier = self::FLAG_MODIFIERS[$flag] ?? throw new \RuntimeException(\sprintf('PHP flag "%s" has no pcre2test equivalent.', $flag));
            if ('' !== $modifier && !\in_array($modifier, $modifiers, true)) {
                $modifiers[] = $modifier;
            }
        }

        if (!$php7 && self::number($release) >= 10038) {
            $modifiers[] = 'allow_lookaround_bsk';
        }

        if ($php7 && !str_contains($flags, 'X')) {
            $modifiers[] = 'bad_escape_is_literal';
        }

        return $delimiter.$body.$delimiter.implode(',', $modifiers);
    }

    /**
     * Whether a case is compiled on a release, and as PHP 7 there.
     *
     * @param array{pattern: string, flags: string, ...} $case
     */
    public static function applies(array $case, string $release, bool $php7): bool
    {
        if (str_contains($case['flags'], 'r') && ($php7 || self::number($release) < 10043)) {
            return false;
        }

        return !$php7 || (isset(self::PHP7[$release]) && !str_contains($case['pattern'], "\0") && !str_contains($case['flags'], 'n'));
    }

    /**
     * The outcome a pcre2test observation stands for: null for a compiled
     * pattern, the offset and the error number of a refused one.
     *
     * @param Observation $observation
     *
     * @return Outcome
     */
    public static function outcome(array $observation): ?array
    {
        if ('accept' === $observation['verdict']) {
            return null;
        }

        if ('reject' !== $observation['verdict'] || !isset($observation['offset'], $observation['code'])) {
            throw new \RuntimeException('pcre2test gave no compile verdict.');
        }

        return [$observation['offset'], $observation['code']];
    }

    /**
     * The values of consecutive releases as runs: each release where the
     * value changes, with the value from there on.
     *
     * @template T
     *
     * @param array<string, T> $outcomes by release, in order
     *
     * @return array<string, T>
     */
    public static function runs(array $outcomes): array
    {
        $runs = [];
        $last = false;
        foreach ($outcomes as $release => $outcome) {
            if ($outcome !== $last) {
                $runs[$release] = $outcome;
                $last = $outcome;
            }
        }

        return $runs;
    }

    /**
     * The value on each release the runs cover, from the first of them up
     * to the last of $releases.
     *
     * @template T
     *
     * @param array<string, T> $runs
     * @param list<string>     $releases
     *
     * @return array<string, T>
     */
    public static function expand(array $runs, array $releases): array
    {
        $outcomes = [];
        $current = false;
        foreach ($releases as $release) {
            if (\array_key_exists($release, $runs)) {
                $current = $runs[$release];
            }
            if (false !== $current) {
                $outcomes[$release] = $current;
            }
        }

        return $outcomes;
    }

    /**
     * The canonical serialization of the fixture: its meta record pretty
     * printed, then one case per line, so that a case that moves is one
     * line of a diff.
     *
     * @param array{meta: array<string, mixed>, cases: list<ReleaseCase>} $fixture
     */
    public static function encode(array $fixture): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;
        $meta = str_replace("\n", "\n    ", json_encode($fixture['meta'], $flags | \JSON_PRETTY_PRINT));
        $cases = array_map(static fn (array $case): string => '        '.json_encode($case, $flags), $fixture['cases']);

        return "{\n    \"meta\": ".$meta.",\n    \"cases\": [\n".implode(",\n", $cases)."\n    ]\n}\n";
    }

    /**
     * The checksum of the cases, which an edit made without regenerating
     * moves.
     *
     * @param list<ReleaseCase> $cases
     */
    public static function checksum(array $cases): string
    {
        return hash('xxh128', json_encode($cases, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    /**
     * Builds the fixture from the releases built under $releasesDir, one
     * directory per release, "pcre2-10.32" to "pcre2-10.49", each holding
     * its pcre2test and its testdata.
     *
     * @throws \RuntimeException when a binary is not its release, or gives no
     *                           verdict for a case
     *
     * @return Measure
     */
    public static function build(string $releasesDir): array
    {
        // Every case of every suite, named after the newest release that holds it.
        $cases = [];
        $digests = [];
        $binaries = [];
        foreach (array_reverse(self::RELEASES) as $release) {
            $dir = $releasesDir.'/pcre2-'.$release;
            $binaries[$release] = self::checkBinary($dir.'/pcre2test', $release);
            [$own, $digests[$release]] = self::suiteCases($dir.'/testdata', $release);
            $cases += $own;
        }
        $cases = array_values($cases);

        $php = [];
        $php7 = [];
        $measured = [];
        $measuredAsPhp7 = [];
        foreach (self::RELEASES as $release) {
            $binary = $releasesDir.'/pcre2-'.$release.'/pcre2test';
            $outcomes = self::observeCases($binary, $release, false, $cases);
            $measured[$release] = \count($outcomes);
            foreach ($outcomes as $index => $outcome) {
                $php[$index][$release] = $outcome;
            }

            if (isset(self::PHP7[$release])) {
                $outcomes = self::observeCases($binary, $release, true, $cases);
                $measuredAsPhp7[$release] = \count($outcomes);
                foreach ($outcomes as $index => $outcome) {
                    $php7[$index][$release] = $outcome;
                }
            }
        }

        // The cases whose outcome moves between releases, or between PHP 7
        // and 8, and the releases each error number is reported on.
        $kept = [];
        $reported = [];
        foreach ($cases as $index => $case) {
            $runs = self::runs($php[$index] ?? []);
            $legacy = $php7[$index] ?? [];
            $legacyDiffers = false;
            foreach ($legacy as $release => $outcome) {
                $legacyDiffers = $legacyDiffers || $outcome !== ($php[$index][$release] ?? null);
            }

            if (\count($runs) < 2 && !$legacyDiffers) {
                continue;
            }

            $row = $case + ['php' => $runs];
            if ([] === $legacy) {
                $row['php7'] = false;
            } elseif ($legacyDiffers) {
                $row['php7'] = self::runs($legacy);
            }

            foreach ([$php[$index] ?? [], $legacy] as $outcomes) {
                foreach ($outcomes as $release => $outcome) {
                    if (null !== $outcome) {
                        $reported[$outcome[1]][$release] = true;
                    }
                }
            }

            $kept[] = $row;
        }

        return [
            'fixture' => [
                'meta' => [
                    'releases' => self::RELEASES,
                    'php' => self::PHP,
                    'php7' => self::PHP7,
                    'files' => array_map(static fn (int $number): string => 'testinput'.$number, self::FILES),
                    'testdata' => array_reverse($digests, true),
                    'cases' => \count($cases),
                    'measured' => $measured,
                    'measuredAsPhp7' => $measuredAsPhp7,
                    'messages' => self::messages($reported, $binaries),
                    'crossChecked' => self::checksum($kept),
                ],
                'cases' => $kept,
            ],
            'cases' => $cases,
            'binaries' => $binaries,
            'measured' => $measured,
            'measuredAsPhp7' => $measuredAsPhp7,
        ];
    }

    /**
     * Checks a pcre2test binary: the release it reports, $release unless
     * null, and the build options PHP's bundled PCRE2 uses. Returns the
     * binary.
     *
     * @throws \RuntimeException when it is not
     */
    public static function checkBinary(string $binary, ?string $release): string
    {
        if (!is_file($binary) || !is_executable($binary)) {
            throw new \RuntimeException(\sprintf('No executable pcre2test at %s.', $binary));
        }

        $version = trim(self::run($binary, ['-version']));
        if (1 !== preg_match('/^PCRE2 version (\S+)/', $version, $reported) || (null !== $release && $reported[1] !== $release)) {
            throw new \RuntimeException(\sprintf('%s reports "%s", not PCRE2 %s.', $binary, $version, $release ?? '10.x'));
        }

        if (!self::acceptsConfiguration(self::run($binary, ['-C']))) {
            throw new \RuntimeException(\sprintf('%s is not built as PHP\'s bundled PCRE2 is ("pcre2test -C" must show LF newline, \R matching any Unicode newline, link size 2, parentheses nest limit 250, Unicode support and \C supported).', $binary));
        }

        return $binary;
    }

    /**
     * The release a pcre2test reports, "10.50-DEV".
     */
    public static function version(string $binary): string
    {
        return 1 === preg_match('/^PCRE2 version (\S+)/', trim(self::run($binary, ['-version'])), $reported) ? $reported[1] : '?';
    }

    /**
     * The compilation cases of one suite PHP can express, keyed by flags and
     * body, and a digest of the files read.
     *
     * @throws \RuntimeException when a testinput file has no testoutput
     *
     * @return array{0: array<string, SuiteCase>, 1: string}
     */
    public static function suiteCases(string $testdata, string $label): array
    {
        $extractor = new Pcre2TestdataExtractor();
        $cases = [];
        $digest = '';
        foreach (self::FILES as $number) {
            $input = $testdata.'/testinput'.$number;
            if (!is_file($input)) {
                continue;
            }

            $output = self::outputFile($testdata, $number) ?? throw new \RuntimeException(\sprintf('No testoutput for %s.', $input));
            $digest .= \sprintf("%s %s\n%s %s\n", basename($input), hash_file('sha256', $input), basename($output), hash_file('sha256', $output));

            foreach ($extractor->extractFilePair($input, $output, 'testinput'.$number) as $row) {
                $flags = \is_string($row['flags'] ?? null) ? $row['flags'] : '';
                $pattern = \is_string($row['pattern'] ?? null) ? $row['pattern'] : '';
                if (null !== ($row['skipCategory'] ?? null)) {
                    // A modifier newer than the oldest supported PHP's PCRE2,
                    // caseless_restrict: PHP 8.4 spells it "r".
                    $reason = \is_string($row['skipReason'] ?? null) ? $row['skipReason'] : '';
                    if ('newer-than-floor' !== $row['skipCategory'] || !str_contains($reason, 'modifier introduced')) {
                        continue;
                    }

                    $letters = str_split($flags.'r');
                    sort($letters);
                    $flags = implode('', array_unique($letters));
                }

                $cases[$flags."\0".$pattern] ??= [
                    'id' => $label.':'.(\is_string($row['id'] ?? null) ? $row['id'] : '?'),
                    'pattern' => $pattern,
                    'delimiter' => \is_string($row['delimiter'] ?? null) ? $row['delimiter'] : '/',
                    'flags' => $flags,
                ];
            }
        }

        return [$cases, hash('sha256', $digest)];
    }

    /**
     * What a binary does with each case it applies to, by case index.
     *
     * @param list<SuiteCase> $cases
     *
     * @throws \RuntimeException when pcre2test gives no verdict for a case
     *
     * @return array<int, Outcome>
     */
    public static function observeCases(string $binary, string $release, bool $php7, array $cases): array
    {
        $indexes = [];
        $lines = [];
        foreach ($cases as $index => $case) {
            if (self::applies($case, $release, $php7)) {
                $indexes[] = $index;
                $lines[] = self::line($case['pattern'], $case['delimiter'], $case['flags'], $release, $php7);
            }
        }

        $outcomes = [];
        foreach (self::observe($binary, $lines) as $at => $observation) {
            $index = $indexes[$at];
            if ('diagnostic' === $observation['verdict']) {
                throw new \RuntimeException(\sprintf('pcre2test %s gave no verdict for %s (%s): %s', $release, $cases[$index]['id'], $php7 ? 'PHP 7' : 'PHP 8', $observation['message'] ?? ''));
            }

            $outcomes[$index] = self::outcome($observation);
        }

        return $outcomes;
    }

    /**
     * Whether "pcre2test -C" shows a build that compiles patterns the way
     * PHP's bundled PCRE2 does, in the layout of any release from 10.32:
     * LF newline ("Newline sequence is LF" up to 10.33), "\R" matching any
     * Unicode newline, link size 2, parentheses nest limit 250, Unicode
     * support, "\C" supported.
     */
    public static function acceptsConfiguration(string $configuration): bool
    {
        $lines = array_map(trim(...), explode("\n", $configuration));
        $linkSize = array_search('Internal link size', $lines, true);

        return (\in_array('Default newline sequence is LF', $lines, true) || \in_array('Newline sequence is LF', $lines, true))
            && \in_array('\R matches all Unicode newlines', $lines, true)
            && \in_array('\C is supported', $lines, true)
            && \in_array('Parentheses nest limit = 250', $lines, true)
            && (\in_array('Internal link size = 2', $lines, true)
                || (false !== $linkSize && 'Requested = 2' === ($lines[$linkSize + 1] ?? null) && 'Effective = 2' === ($lines[$linkSize + 2] ?? null)))
            && [] !== array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'UTF and UCP support'));
    }

    /**
     * Compiles each line with the given pcre2test and returns what it
     * observed for each, in order. The lines are batched behind separator
     * patterns; where pcre2test abandons the run on a case (10.47 does on
     * its error 170), that case is read alone and the batch goes on after
     * it.
     *
     * @param list<string> $lines
     *
     * @return list<Observation>
     */
    public static function observe(string $binary, array $lines): array
    {
        $observations = [];
        $offset = 0;
        $count = \count($lines);
        while ($offset < $count) {
            $batch = \array_slice($lines, $offset);
            foreach (self::observeBatch($binary, $batch) as $index => $observation) {
                if (null === $observation) {
                    $observations[] = self::observeAbandoned($binary, $batch[$index]);
                    $offset += $index + 1;

                    continue 2;
                }

                $observations[] = $observation;
            }

            $offset = $count;
        }

        return $observations;
    }

    /**
     * Runs pcre2test with the given options and input, returning what it
     * printed: on standard output without input, in its output file with.
     *
     * @param list<string> $options
     */
    public static function run(string $binary, array $options, string $input = ''): string
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'pcre2-releases-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'pcre2-releases-out-');
        if (false === $inputPath || false === $outputPath) {
            throw new \RuntimeException('Unable to create temporary files for pcre2test.');
        }

        try {
            file_put_contents($inputPath, $input);
            $command = [$binary, ...$options];
            if ('' !== $input) {
                $command[] = $inputPath;
                $command[] = $outputPath;
            }

            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!\is_resource($process)) {
                throw new \RuntimeException(\sprintf('Unable to run %s.', $binary));
            }

            $stdout = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return '' === $input ? $stdout : (string) file_get_contents($outputPath);
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    /**
     * The wording of each error number, on the releases that report it: a
     * number may name another error on another release.
     *
     * @param array<int, array<string, true>> $reported the releases each number is reported on
     * @param array<string, string>           $binaries
     *
     * @return array<int, array<string, string>>
     */
    private static function messages(array $reported, array $binaries): array
    {
        ksort($reported);
        $messages = [];
        foreach ($reported as $code => $releases) {
            $wording = [];
            foreach (self::RELEASES as $release) {
                if (!isset($releases[$release], $binaries[$release])) {
                    continue;
                }

                $printed = trim(self::run($binaries[$release], ['-error', (string) $code]));
                $wording[$release] = 1 === preg_match('/^Error \d+: (.*)$/', $printed, $text) ? $text[1] : throw new \RuntimeException(\sprintf('pcre2test %s does not name error %d.', $release, $code));
            }
            $messages[$code] = self::runs($wording);
        }

        return $messages;
    }

    /**
     * What pcre2test printed for each line of a batch, null from the first
     * line on which the run was abandoned.
     *
     * @param list<string> $lines
     *
     * @return list<Observation|null>
     */
    private static function observeBatch(string $binary, array $lines): array
    {
        $input = '';
        foreach ($lines as $index => $line) {
            $input .= \sprintf(self::SEPARATOR, $index)."\n\n".$line."\n\n";
        }
        $input .= \sprintf(self::SEPARATOR, \count($lines))."\n";

        $output = "\n".self::run($binary, ['-q'], $input);
        $observations = [];
        $position = 0;
        foreach ($lines as $index => $line) {
            $start = strpos($output, "\n".\sprintf(self::SEPARATOR, $index)."\n", $position);
            $end = false === $start ? false : strpos($output, "\n".\sprintf(self::SEPARATOR, $index + 1)."\n", $start + 1);
            if (false === $start || false === $end) {
                $observations[] = null;

                continue;
            }

            $segment = substr($output, $start, $end - $start + 1);
            $echo = "\n".\sprintf(self::SEPARATOR, $index)."\n\n".$line."\n";
            if (!str_starts_with($segment, $echo)) {
                throw new \RuntimeException(\sprintf('pcre2test output for line %d does not start with its echo: the batch is out of step.', $index));
            }

            $observations[] = self::read(substr($segment, \strlen($echo)));
            $position = $end;
        }

        return $observations;
    }

    /**
     * Compiles a line pcre2test abandoned its run on, alone.
     *
     * @return Observation
     */
    private static function observeAbandoned(string $binary, string $line): array
    {
        $printed = self::run($binary, ['-q'], $line."\n");

        return self::read($printed, true);
    }

    /**
     * The compile verdict in what pcre2test printed after a pattern's echo.
     * A "**" diagnostic is no verdict, unless the run was abandoned after a
     * failure, which then is the verdict.
     *
     * @return Observation
     */
    private static function read(string $printed, bool $abandoned = false): array
    {
        if (1 === preg_match('/^Failed: error (\d+) at offset (\d+): (.*)$/m', $printed, $failed)
            && ($abandoned || 1 !== preg_match('/^\s*\*\*/m', $printed))) {
            return ['verdict' => 'reject', 'offset' => (int) $failed[2], 'code' => (int) $failed[1], 'message' => $failed[3]];
        }

        if (1 === preg_match('/^\s*\*\*.*$/m', $printed, $diagnostic)) {
            return ['verdict' => 'diagnostic', 'message' => trim($diagnostic[0])];
        }

        return ['verdict' => 'accept'];
    }
}
