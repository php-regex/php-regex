<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\TestUtils;

/**
 * Compiles suite cases with a real pcre2test of the PCRE2 floor release
 * (the oldest PCRE2 a supported PHP ships), at extraction time only.
 *
 * Each case is written as one pcre2test pattern line that compiles the way
 * PHP compiles the same pattern: every PHP pattern flag becomes the matching
 * pcre2test modifier ("u" sets UTF and UCP, as PHP does), and
 * allow_lookaround_bsk is always added because php-src sets
 * PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK in every compile context. The flag "X"
 * has no pcre2test equivalent and changes no compilation in PCRE2, so it maps
 * to nothing; any other flag without a mapping throws rather than compile
 * the case in a context that silently drops it. PHP reads "xx" as "x" given
 * twice (preg_match('/[a b]/xx', ' ') returns 1), so it maps to extended,
 * never to pcre2test's extended_more.
 *
 * The binaries are checked before use: the version must be the expected
 * release, and "pcre2test -C" must show the build options PHP's bundled
 * PCRE2 uses (LF newline, \R matching any Unicode newline, link size 2,
 * parentheses nest limit 250, Unicode support, \C supported). The
 * library widths, JIT and line-editing support do not change how a pattern
 * compiles and are not checked.
 *
 * @phpstan-type FloorObservation = array{verdict: string, offset: int|null, pcre2Code: int|null}
 */
final class Pcre2FloorOracle
{
    /**
     * PHP pattern flag to pcre2test modifier(s); an empty string maps the
     * flag to nothing.
     */
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
    ];

    /**
     * Lines "pcre2test -C" must print, exactly, for a build that compiles
     * patterns the way PHP's bundled PCRE2 does.
     */
    private const REQUIRED_CONFIGURATION = [
        'Default newline sequence is LF',
        '\R matches all Unicode newlines',
        '\C is supported',
        'Parentheses nest limit = 250',
    ];

    /**
     * The pattern line of one case separating batched cases in a single
     * pcre2test run; its echo marks where the next case's output starts.
     */
    private const SEPARATOR = '/pcre2-floor-oracle-separator-%d/';

    /**
     * The pcre2test pattern line for a PHP pattern body, delimiter and
     * flags. The body is written verbatim: pcre2test, like PHP, hands an
     * escaped delimiter to PCRE2 as written, and reads a body spanning
     * several lines as continuation lines.
     */
    public static function pcre2testLine(string $body, string $delimiter, string $flags): string
    {
        $modifiers = [];

        foreach ('' === $flags ? [] : str_split($flags) as $flag) {
            $modifier = self::FLAG_MODIFIERS[$flag] ?? null;

            if (null === $modifier) {
                throw new \RuntimeException(\sprintf('PHP flag "%s" has no pcre2test equivalent: the case cannot be compiled in PHP\'s context.', $flag));
            }

            if ('' !== $modifier && !\in_array($modifier, $modifiers, true)) {
                $modifiers[] = $modifier;
            }
        }

        $modifiers[] = 'allow_lookaround_bsk';

        return $delimiter.$body.$delimiter.implode(',', $modifiers);
    }

    /**
     * Reads the compile verdict from the pcre2test -q output of one pattern
     * line: a "Failed: error N at offset M" line is a rejection, an echo
     * without one is an accept. Output that carries no verdict throws: no
     * verdict is ever guessed. That covers nothing at all, and a "** ..."
     * diagnostic anywhere, even beside a failure line, which may then
     * belong to another pattern.
     *
     * @return FloorObservation
     */
    public static function parseOutput(string $output): array
    {
        if ('' === trim($output)) {
            throw new \RuntimeException('pcre2test printed nothing for the pattern: no floor verdict to read.');
        }

        if (1 === preg_match('/^\s*\*\*.*$/m', $output, $diagnostic)) {
            throw new \RuntimeException(\sprintf('pcre2test did not compile the pattern line: %s', trim($diagnostic[0])));
        }

        if (1 === preg_match('/^Failed: error (\d+) at offset (\d+):/m', $output, $failed)) {
            return ['verdict' => 'reject', 'offset' => (int) $failed[2], 'pcre2Code' => (int) $failed[1]];
        }

        return ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null];
    }

    /**
     * Whether "pcre2test -version" output names exactly the expected
     * release (the floor by default).
     */
    public static function acceptsVersionLine(string $versionOutput, string $expectedVersion = Pcre2TestdataExtractor::PCRE2_FLOOR): bool
    {
        if (1 !== preg_match('/^PCRE2 version (\S+)/', trim($versionOutput), $version)) {
            return false;
        }

        return $expectedVersion === $version[1];
    }

    /**
     * Whether "pcre2test -C" output shows a build that compiles patterns
     * the way PHP's bundled PCRE2 does.
     */
    public static function acceptsConfiguration(string $configurationOutput): bool
    {
        $lines = array_map(trim(...), explode("\n", $configurationOutput));

        foreach (self::REQUIRED_CONFIGURATION as $required) {
            if (!\in_array($required, $lines, true)) {
                return false;
            }
        }

        if (!self::hasLinkSizeTwo($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            if (str_starts_with($line, 'UTF and UCP support')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs "pcre2test -version" and "pcre2test -C" and tells whether the
     * binary is the expected release with the expected build options,
     * returning a description of what it reported.
     *
     * @return array{0: bool, 1: string}
     */
    public static function checkBinary(string $binary, string $expectedVersion = Pcre2TestdataExtractor::PCRE2_FLOOR): array
    {
        $version = trim(self::run($binary, ['-version'], ''));

        if (!self::acceptsVersionLine($version, $expectedVersion)) {
            return [false, \sprintf('reports "%s", not PCRE2 %s', $version, $expectedVersion)];
        }

        $configuration = self::run($binary, ['-C'], '');

        if (!self::acceptsConfiguration($configuration)) {
            $missing = [];

            foreach ([...self::REQUIRED_CONFIGURATION, 'UTF and UCP support'] as $required) {
                if (!str_contains($configuration, $required)) {
                    $missing[] = $required;
                }
            }

            if (!self::hasLinkSizeTwo(array_map(trim(...), explode("\n", $configuration)))) {
                $missing[] = 'Internal link size = 2';
            }

            return [false, \sprintf('%s, but its build options differ from PHP\'s bundled PCRE2 (missing from "pcre2test -C": %s)', $version, implode('; ', $missing))];
        }

        return [true, $version];
    }

    /**
     * Compiles every row with the given pcre2test in one run and returns
     * each row's observation, keyed by case id. The rows are separated by a
     * marker pattern line whose echo splits the output back per case. A
     * missing marker, or a segment that does not start with the exact echo
     * of the line written for its case, throws instead of recording an
     * observation for the wrong pattern.
     *
     * @param list<array<string, mixed>> $rows rows in the extractor's canonical shape
     *
     * @return array<string, FloorObservation>
     */
    public static function observeAll(string $binary, array $rows): array
    {
        $input = '';
        $ids = [];

        $written = [];

        foreach ($rows as $index => $row) {
            $body = \is_string($row['pattern'] ?? null) ? $row['pattern'] : '';
            $delimiter = \is_string($row['delimiter'] ?? null) ? $row['delimiter'] : '/';
            $flags = \is_string($row['flags'] ?? null) ? $row['flags'] : '';
            $ids[] = \is_string($row['id'] ?? null) ? $row['id'] : '?';
            $written[] = self::pcre2testLine($body, $delimiter, $flags);

            $input .= \sprintf(self::SEPARATOR, $index)."\n\n";
            $input .= $written[$index]."\n\n";
        }

        $input .= \sprintf(self::SEPARATOR, \count($rows))."\n";

        $output = "\n".self::run($binary, ['-q'], $input);
        $observations = [];
        $start = self::afterSeparator($output, 0, 0);

        foreach ($ids as $index => $id) {
            if (null === $start) {
                throw new \RuntimeException(\sprintf('pcre2test output has no separator before case %s.', $id));
            }

            $next = self::afterSeparator($output, $index + 1, $start);

            if (null === $next) {
                throw new \RuntimeException(\sprintf('pcre2test output ends inside case %s: the floor run did not reach the next case.', $id));
            }

            $segment = substr($output, $start, $next - $start - \strlen(\sprintf(self::SEPARATOR, $index + 1)) - 1);
            $echo = "\n".$written[$index]."\n";

            if (!str_starts_with($segment, $echo)) {
                throw new \RuntimeException(\sprintf('pcre2test output for case %s does not start with the echo of the line written for it: the batch is out of step.', $id));
            }

            // Only what pcre2test printed after the echo is read, behind the
            // pattern's first line: a continuation line of a multi-line
            // body can never pose as a failure or a diagnostic.
            $printed = explode("\n", $written[$index])[0]."\n".substr($segment, \strlen($echo));

            try {
                $observations[$id] = self::parseOutput($printed);
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException(\sprintf('Case %s: %s', $id, $exception->getMessage()), 0, $exception);
            }

            $start = $next;
        }

        return $observations;
    }

    /**
     * Whether the configuration lines show internal link size 2, in either
     * layout pcre2test prints: "Internal link size = 2" (10.40), or an
     * "Internal link size" heading followed by "Requested = 2" and
     * "Effective = 2" (10.48).
     *
     * @param list<string> $lines trimmed "pcre2test -C" lines
     */
    private static function hasLinkSizeTwo(array $lines): bool
    {
        if (\in_array('Internal link size = 2', $lines, true)) {
            return true;
        }

        $heading = array_search('Internal link size', $lines, true);

        return false !== $heading
            && 'Requested = 2' === ($lines[$heading + 1] ?? null)
            && 'Effective = 2' === ($lines[$heading + 2] ?? null);
    }

    /**
     * The offset right after the echoed separator line with the given index,
     * searching from $from, or null when the output holds no such line.
     */
    private static function afterSeparator(string $output, int $index, int $from): ?int
    {
        $line = "\n".\sprintf(self::SEPARATOR, $index)."\n";
        $position = strpos($output, $line, max(0, $from - 1));

        return false === $position ? null : $position + \strlen($line);
    }

    /**
     * Runs pcre2test with the given input and returns what it printed. The
     * input and output go through temporary files (pcre2test's own
     * "pcre2test [options] infile outfile" form), so no pipe buffer can
     * stall a large batch.
     *
     * @param list<string> $options
     */
    private static function run(string $binary, array $options, string $input): string
    {
        $inputPath = tempnam(sys_get_temp_dir(), 'pcre2-floor-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'pcre2-floor-out-');

        if (false === $inputPath || false === $outputPath) {
            throw new \RuntimeException('Unable to create temporary files for pcre2test.');
        }

        try {
            if (false === file_put_contents($inputPath, $input)) {
                throw new \RuntimeException(\sprintf('Unable to write %s.', $inputPath));
            }

            $command = [$binary, ...$options];

            if ('' !== $input) {
                $command[] = $inputPath;
                $command[] = $outputPath;
            }

            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

            if (!\is_resource($process)) {
                throw new \RuntimeException(\sprintf('Unable to run %s.', $binary));
            }

            $stdout = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if ('' === $input) {
                return false === $stdout ? '' : $stdout;
            }

            $output = file_get_contents($outputPath);

            return false === $output ? '' : $output;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }
}
