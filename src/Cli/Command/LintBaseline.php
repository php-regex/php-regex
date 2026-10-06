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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\CliException;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Internal\LintStatsCounter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Validation\ValidationResult;

/**
 * The issues a lint run already knows, read from a baseline file and taken
 * out of a report.
 *
 * Each entry names an issue by its identifier, its file relative to the
 * working directory and a hash of the exact bytes of its pattern: the issue
 * stays known when its line moves or its message is reworded. The file is
 * read with its "." and ".." segments resolved, so "code", "./code" and
 * "code/../code" name the same files. One entry takes out one issue. The
 * line only settles which issues the entries take when there are more
 * issues than entries sharing these three: the entries and the issues are
 * aligned in line order, as a diff aligns two versions of a file, and the
 * copies of a pattern inserted since the baseline are reported.
 *
 * A 1.x baseline, a plain list, is still read with its own key: the file,
 * the line and the message.
 *
 * @internal
 *
 * @phpstan-import-type LintIssue from LintReport
 * @phpstan-import-type LintResult from LintReport
 */
final readonly class LintBaseline
{
    public const VERSION = 1;

    /**
     * The steps past which the entries of one key take the last issues in
     * line order instead of the alignment that changes the shift least.
     */
    private const ALIGNMENT_BUDGET = 1_000_000;

    /**
     * @param array<string, array<int, int>> $lines  the line of each entry, by key
     * @param bool                           $legacy whether the file is a 1.x list
     */
    private function __construct(private array $lines, public bool $legacy) {}

    /**
     * @throws CliException when the file is missing, unreadable, or holds no baseline
     */
    public static function load(string $file): self
    {
        if (!file_exists($file)) {
            throw new CliException(\sprintf('Baseline file not found: %s', $file));
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new CliException(\sprintf('Baseline file not readable: %s', $file));
        }

        $content = @file_get_contents($file);
        if (false === $content) {
            throw new CliException(\sprintf('Could not read the baseline file: %s', $file));
        }

        try {
            $data = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CliException(\sprintf('The baseline file %s is not valid JSON: %s.', $file, $e->getMessage()), 0, $e);
        }

        if (\is_array($data)) {
            return self::fromList($file, $data);
        }

        if ($data instanceof \stdClass) {
            return self::fromDocument($file, $data);
        }

        throw self::notABaseline($file);
    }

    /**
     * The baseline of every issue of the report, as the JSON document every
     * command writes: one entry per issue, in the report's order, its keys
     * those of the report's issue. Each string is written with the bytes
     * that are not UTF-8 spelled "\xHH", so that the encoding cannot fail;
     * the hash keeps the exact pattern.
     */
    public static function generate(LintReport $report): string
    {
        $issues = [];
        foreach ($report->results as $result) {
            $pattern = $result['pattern'] ?? '';
            foreach ($result['issues'] as $issue) {
                $issues[] = [
                    'file' => self::relativePath($issue['file']),
                    'line' => $issue['line'],
                    'column' => $issue['column'] ?? null,
                    'issue_id' => self::issueId($issue),
                    'message' => $issue['message'],
                    'severity' => $issue['type'],
                    'pattern' => $pattern,
                    'pattern_hash' => self::hash($pattern),
                ];
            }
        }

        // Every string is valid UTF-8 once spelled and every number an
        // integer, so the encoding cannot fail: the file is never empty.
        return JsonDocument::encode(['version' => self::VERSION, 'issues' => $issues]);
    }

    /**
     * The report without the issues the baseline knows: a result keeps its
     * column and file offset, loses the problem entry of each known issue,
     * and leaves the report once nothing is left in it.
     */
    public function filter(LintReport $report): LintReport
    {
        $candidates = [];
        foreach ($report->results as $r => $result) {
            foreach ($result['issues'] as $i => $issue) {
                $key = $this->keyOf($issue, $result);
                if (isset($this->lines[$key])) {
                    $candidates[$key][] = [$r, $i, $issue['line']];
                }
            }
        }

        $known = [];
        foreach ($candidates as $key => $issues) {
            foreach (self::align($this->lines[$key], $issues) as [$r, $i]) {
                $known[$r][$i] = true;
            }
        }

        $results = [];
        foreach ($report->results as $r => $result) {
            if (!isset($known[$r])) {
                $results[] = $result;

                continue;
            }

            $issues = [];
            $removed = [];
            foreach ($result['issues'] as $i => $issue) {
                if (isset($known[$r][$i])) {
                    $removed[] = $issue['message'];
                } else {
                    $issues[] = $issue;
                }
            }

            // Each issue has a problem entry with its message; the ones of
            // the optimizations stay.
            $problems = [];
            foreach ($result['problems'] as $problem) {
                $at = DiagnosticType::Optimization === $problem->type ? false : array_search($problem->message, $removed, true);
                if (false === $at) {
                    $problems[] = $problem;
                } else {
                    unset($removed[$at]);
                }
            }

            if ([] === $issues && [] === $result['optimizations'] && [] === $problems) {
                continue;
            }

            $result['issues'] = $issues;
            $result['problems'] = $problems;
            $results[] = $result;
        }

        return new LintReport($results, LintStatsCounter::count($results));
    }

    /**
     * The issues of one key the entries of that key take out.
     *
     * The entries and the issues are aligned in line order, as a diff aligns
     * two versions of a file: entry after entry, from the top, each takes a
     * later issue than the one before it. Of these alignments, the one kept
     * changes the least the shift from an entry to its issue, starting from
     * no shift: the sum, over the entries, of the difference between the
     * shift of an entry and the shift of the entry before it. A block of
     * lines inserted above some copies of a pattern shifts them all by the
     * same amount, so the copies inserted are the issues left out of the
     * alignment, and they are reported. On a tie the later issues are taken
     * and the earlier ones reported, as an insertion above pushes the copies
     * down. Of issues on one line, the first reported takes an entry first.
     *
     * When there are as many entries as issues, or more, every issue is
     * taken. Past ALIGNMENT_BUDGET steps, the last issues are taken, in line
     * order, without weighing the shifts.
     *
     * @param array<int, int>                   $entries the lines of the entries
     * @param list<array{int|string, int, int}> $issues  the result, the issue and the line of each issue
     *
     * @return list<array{int|string, int, int}> the issues taken
     */
    private static function align(array $entries, array $issues): array
    {
        $taken = \count($entries);
        $skipped = \count($issues) - $taken;
        if ($skipped <= 0) {
            return $issues;
        }

        sort($entries);
        // By line, and on one line in the opposite of the report order: the
        // tie that takes the later issues takes the first reported.
        $order = array_keys($issues);
        usort($order, static fn (int $a, int $b): int => [$issues[$a][2], $b] <=> [$issues[$b][2], $a]);
        $sorted = array_map(static fn (int $at): array => $issues[$at], $order);

        if ($taken * ($skipped + 1) ** 2 > self::ALIGNMENT_BUDGET) {
            return \array_slice($sorted, $skipped);
        }

        // $cost[$k][$j]: the least change of shift over the entries up to
        // $k when entry $k takes issue $j, and $from[$k][$j] the issue entry
        // $k - 1 then takes. Entry $k can only take an issue from $k to
        // $k + $skipped, so that each entry after it has one left.
        $cost = [];
        $from = [];
        foreach (range(0, $skipped) as $j) {
            $cost[0][$j] = abs($sorted[$j][2] - $entries[0]);
        }
        for ($k = 1; $k < $taken; $k++) {
            for ($j = $k; $j <= $k + $skipped; $j++) {
                $shift = $sorted[$j][2] - $entries[$k];
                $best = $k - 1;
                $bestCost = \PHP_INT_MAX;
                for ($p = $k - 1; $p < $j; $p++) {
                    $total = $cost[$k - 1][$p] + abs($shift - ($sorted[$p][2] - $entries[$k - 1]));
                    if ($total <= $bestCost) {
                        $best = $p;
                        $bestCost = $total;
                    }
                }
                $cost[$k][$j] = $bestCost;
                $from[$k][$j] = $best;
            }
        }

        $last = $taken - 1;
        $at = $last;
        for ($j = $last + 1; $j <= $last + $skipped; $j++) {
            if ($cost[$last][$j] <= $cost[$last][$at]) {
                $at = $j;
            }
        }

        $aligned = [];
        for ($k = $last; $k >= 0; $k--) {
            $aligned[] = $sorted[$at];
            $at = $from[$k][$at] ?? $at;
        }

        return $aligned;
    }

    /**
     * @param array<mixed> $list
     */
    private static function fromList(string $file, array $list): self
    {
        $lines = [];
        foreach ($list as $item) {
            $entry = $item instanceof \stdClass ? get_object_vars($item) : null;
            if (null === $entry || !\is_string($entry['file'] ?? null) || !\is_int($entry['line'] ?? null) || !\is_string($entry['message'] ?? null)) {
                throw self::notABaseline($file);
            }
            $lines[self::legacyKey(self::normalizePath($entry['file']), $entry['line'], $entry['message'])][] = $entry['line'];
        }

        return new self($lines, true);
    }

    private static function fromDocument(string $file, \stdClass $document): self
    {
        $data = get_object_vars($document);
        if (!\array_key_exists('version', $data) || !\is_array($data['issues'] ?? null)) {
            throw self::notABaseline($file);
        }
        if (self::VERSION !== $data['version']) {
            throw new CliException(\sprintf('The baseline file %s is not of version %d, the one this version reads: regenerate it with --generate-baseline.', $file, self::VERSION));
        }

        $lines = [];
        foreach ($data['issues'] as $item) {
            $entry = $item instanceof \stdClass ? get_object_vars($item) : null;
            if (null === $entry
                || !\is_string($entry['issue_id'] ?? null)
                || !\is_string($entry['file'] ?? null)
                || !\is_string($entry['pattern_hash'] ?? null)
                || !\is_int($entry['line'] ?? null)
            ) {
                throw self::notABaseline($file);
            }
            $lines[self::key($entry['issue_id'], self::normalizeSpelledPath($entry['file']), $entry['pattern_hash'])][] = $entry['line'];
        }

        return new self($lines, false);
    }

    private static function notABaseline(string $file): CliException
    {
        return new CliException(\sprintf('The file %s is not a lint baseline: expected {"version": %d, "issues": [...]}, as written by --generate-baseline.', $file, self::VERSION));
    }

    /**
     * @param LintIssue  $issue
     * @param LintResult $result
     */
    private function keyOf(array $issue, array $result): string
    {
        if ($this->legacy) {
            return self::legacyKey(self::relativePath($issue['file']), $issue['line'], $issue['message']);
        }

        return self::key(self::issueId($issue), self::escape(self::relativePath($issue['file'])), self::hash($result['pattern'] ?? ''));
    }

    private static function key(string $issueId, string $file, string $patternHash): string
    {
        return $issueId."\0".$file."\0".$patternHash;
    }

    private static function legacyKey(string $file, int $line, string $message): string
    {
        return $file.':'.$line.':'.$message;
    }

    /**
     * The rule of a lint issue, the error code of a pattern that does not
     * compile.
     *
     * @param LintIssue $issue
     */
    private static function issueId(array $issue): string
    {
        $validation = $issue['validation'] ?? null;
        $code = $issue['issueId'] ?? ($validation instanceof ValidationResult ? $validation->errorCode?->value : null) ?? '';

        return self::escape($code);
    }

    private static function hash(string $pattern): string
    {
        return hash('xxh128', $pattern);
    }

    /**
     * The string with each byte that is not part of a UTF-8 character
     * spelled "\xHH", as the machine report formats spell it.
     */
    private static function escape(string $value): string
    {
        return JsonDocument::spellInvalidBytes($value);
    }

    /**
     * The path relative to the working directory when it lies under it,
     * with forward slashes and its "." and ".." segments resolved.
     */
    private static function relativePath(string $path): string
    {
        $normalizedPath = self::normalizePath($path);
        $cwd = getcwd();
        if (false === $cwd) {
            return $normalizedPath;
        }

        $normalizedCwd = rtrim(self::normalizePath($cwd), '/');
        if ('' === $normalizedCwd) {
            return $normalizedPath;
        }

        $prefix = $normalizedCwd.'/';
        if (str_starts_with($normalizedPath, $prefix)) {
            return substr($normalizedPath, \strlen($prefix));
        }

        return $normalizedPath;
    }

    /**
     * A path as a baseline spells it, with its "." and ".." segments
     * resolved and spelled again: each "\xHH" that stands for a byte the
     * spelling escapes is read back first, so that its backslash is not
     * taken for a separator.
     */
    private static function normalizeSpelledPath(string $path): string
    {
        $bytes = LibraryPcre::replaceCallback(
            '/\\\\x([89A-F][0-9A-F])/',
            static fn (array $match): string => \chr((int) hexdec($match[1])),
            $path,
        ) ?? $path;

        return self::escape(self::normalizePath($bytes));
    }

    /**
     * The path with forward slashes, read lexically: a run of separators is
     * one, a "." segment is dropped, and a ".." segment takes out the segment
     * before it. A ".." above the root of an absolute path is dropped; one
     * above the start of a relative path is kept.
     */
    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = match (true) {
            str_starts_with($path, '/') => '/',
            \strlen($path) >= 3 && Ascii::isAlpha($path[0]) && ':' === $path[1] && '/' === $path[2] => substr($path, 0, 3),
            default => '',
        };
        $path = substr($path, \strlen($root));

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment && [] !== $segments && '..' !== end($segments)) {
                array_pop($segments);

                continue;
            }
            if ('..' === $segment && '' !== $root) {
                continue;
            }
            $segments[] = $segment;
        }

        return $root.implode('/', $segments);
    }
}
