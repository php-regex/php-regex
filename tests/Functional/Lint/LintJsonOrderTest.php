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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint report comes out in one order whatever the number of workers:
 * results by file (compared bytewise), line, column (null first),
 * file_offset, source; the issues of a result by position (null first),
 * then issue_id. The promise is the order, not the bytes: timing fields may
 * differ between runs. And "file" is relative to the working directory,
 * with "/", whichever way the path was given.
 */
final class LintJsonOrderTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    /**
     * B.php sorts before a.php bytewise ("B" is 0x42, "a" 0x61), and a.php
     * before a/x.php ("." is 0x2E, "/" 0x2F). Several patterns share a
     * line, and (a+)+$ has several issues on one position.
     */
    private const FILES = [
        'src/a.php' => <<<'PHP'
            <?php

            preg_match('/(a/', $s); preg_match('/a{1}/', $s); preg_match('/(a+)+$/', $s);
            preg_match('/[a-zA-Z0-9_]+/', $s);
            preg_match('/(b+)+$/', $s);

            PHP,
        'src/B.php' => <<<'PHP'
            <?php

            preg_match('/x{1}/', $s); preg_match('/(?<=a+)b/', $s);
            preg_match('/[0-9]/', $s);

            PHP,
        'src/a/x.php' => <<<'PHP'
            <?php

            preg_match('/(c+)+$/', $s); preg_match('/y{1}/', $s);

            PHP,
        'src/c.php' => <<<'PHP'
            <?php

            preg_match('/(d+)+$/', $s);
            preg_match('/z{1}/', $s); preg_match('/(e/', $s);

            PHP,
    ];

    private const LINT = ['--format=json', '--redos', '--jobs=2'];

    #[Test]
    public function test_lint_json_order_is_the_same_on_every_parallel_run(): void
    {
        $this->enterProject(self::FILES);

        $first = self::order($this->lint(['src', ...self::LINT]));
        $second = self::order($this->lint(['src', ...self::LINT]));
        $serial = self::order($this->lint(['src', '--format=json', '--redos', '--jobs=1']));

        $this->assertSame($first, $second);
        $this->assertSame($first, $serial);
    }

    #[Test]
    public function test_lint_json_results_are_sorted_by_file_line_column_offset_and_source(): void
    {
        $this->enterProject(self::FILES);

        $results = $this->lint(['src', ...self::LINT]);

        $keys = array_map(self::resultKey(...), $results);
        $sorted = $keys;
        usort($sorted, self::compareKeys(...));
        $this->assertSame($sorted, $keys);

        // Bytewise, not natural nor case-insensitive.
        $files = [];
        foreach ($results as $result) {
            $this->assertIsString($result['file'] ?? null);
            if (!\in_array($result['file'], $files, true)) {
                $files[] = $result['file'];
            }
        }
        $this->assertSame(['src/B.php', 'src/a.php', 'src/a/x.php', 'src/c.php'], $files);
    }

    #[Test]
    public function test_lint_json_issues_are_sorted_by_position_then_issue_id(): void
    {
        $this->enterProject(self::FILES);

        $results = $this->lint(['src', ...self::LINT]);

        $checked = 0;
        foreach ($results as $result) {
            $issues = array_map(
                static fn (array $issue): array => [$issue['position'] ?? null, $issue['issue_id'] ?? null],
                self::rows($result['issues'] ?? null),
            );
            $sorted = $issues;
            usort($sorted, self::compareKeys(...));
            $this->assertSame($sorted, $issues, 'Issues of '.json_encode([$result['pattern'] ?? null, $result['file'] ?? null]));
            $checked += \count($issues) > 1 ? 1 : 0;
        }

        // (a+)+$ and its siblings: a nested-quantifier warning and a
        // quantified-capture info at position 0, and the ReDoS verdict.
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * In-process, one worker: the ReDoS verdict names no position and comes
     * before the issues at position 0, which follow by issue_id.
     */
    #[Test]
    public function test_lint_json_issue_without_position_comes_first(): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/(b+)+$/', \$s);\n"]);

        [, $stdout] = $this->runRegex(['lint', 'src', '--format=json', '--redos', '--jobs=1', '--no-optimize']);

        $results = self::rows(JsonContract::decodeDocument($stdout)['results'] ?? null);
        $this->assertCount(1, $results, $stdout);
        $this->assertSame(
            [[null, 'regex.lint.redos'], [0, 'regex.lint.group.quantifiedCapture'], [0, 'regex.lint.quantifier.nested']],
            array_map(static fn (array $issue): array => [$issue['position'] ?? null, $issue['issue_id'] ?? null], self::rows($results[0]['issues'] ?? null)),
        );
    }

    /**
     * @param list<string> $paths
     */
    #[Test]
    #[DataProvider('providePathsToTheSameFiles')]
    public function test_lint_json_file_is_relative_with_slashes_however_the_path_is_given(array $paths): void
    {
        $directory = $this->enterProject(self::FILES);
        $paths = array_map(static fn (string $path): string => str_replace('{cwd}', $directory, $path), $paths);

        $expected = self::files($this->lint(['src', '--format=json', '--jobs=1']));
        $actual = self::files($this->lint([...$paths, '--format=json', '--jobs=1']));

        $this->assertSame(['src/B.php', 'src/a.php', 'src/a/x.php', 'src/c.php'], $expected);
        $this->assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{paths: list<string>}>
     */
    public static function providePathsToTheSameFiles(): iterable
    {
        yield 'absolute' => ['paths' => ['{cwd}/src']];
        yield 'with a trailing slash' => ['paths' => ['src/']];
        yield 'with a leading dot' => ['paths' => ['./src']];
        yield 'absolute, with a trailing slash' => ['paths' => ['{cwd}/src/']];
    }

    /**
     * A file outside the working directory keeps its absolute path.
     *
     * @param list<string> $paths
     */
    #[Test]
    #[DataProvider('providePathsOutsideTheWorkingDirectory')]
    public function test_lint_json_file_is_absolute_outside_the_working_directory(array $paths): void
    {
        $directory = $this->enterProject(self::FILES + ['work/.keep' => '']);
        chdir($directory.'/work');
        $paths = array_map(static fn (string $path): string => str_replace('{project}', $directory, $path), $paths);

        $files = self::files($this->lint([...$paths, '--format=json', '--jobs=1']));

        $this->assertSame(
            [$directory.'/src/B.php', $directory.'/src/a.php', $directory.'/src/a/x.php', $directory.'/src/c.php'],
            $files,
        );
    }

    /**
     * @return iterable<string, array{paths: list<string>}>
     */
    public static function providePathsOutsideTheWorkingDirectory(): iterable
    {
        yield 'absolute' => ['paths' => ['{project}/src']];
        yield 'relative, up a directory' => ['paths' => ['../src']];
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<array<string, mixed>> the results of the report
     */
    private function lint(array $arguments): array
    {
        [$exitCode, $stdout, $stderr] = $this->runRegexProcess(['lint', ...$arguments]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);

        return self::rows($document['results'] ?? null);
    }

    /**
     * A decoded list of JSON objects.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            $entry = [];
            foreach ($row as $key => $item) {
                $entry[(string) $key] = $item;
            }
            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * What the order promise covers: each result's sort key and its issues'
     * sort keys, timing fields left out.
     *
     * @param list<array<string, mixed>> $results
     *
     * @return list<array{list<mixed>, list<array{mixed, mixed}>}>
     */
    private static function order(array $results): array
    {
        $order = [];
        foreach ($results as $result) {
            $order[] = [
                self::resultKey($result),
                array_map(static fn (array $issue): array => [$issue['position'] ?? null, $issue['issue_id'] ?? null], self::rows($result['issues'] ?? null)),
            ];
        }

        return $order;
    }

    /**
     * The distinct file values, sorted here: the order has tests of its own.
     *
     * @param list<array<string, mixed>> $results
     *
     * @return list<string>
     */
    private static function files(array $results): array
    {
        $files = [];
        foreach ($results as $result) {
            self::assertIsString($result['file'] ?? null);
            $files[$result['file']] = $result['file'];
        }
        $files = array_values($files);
        usort($files, strcmp(...));

        return $files;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<mixed>
     */
    private static function resultKey(array $result): array
    {
        return [$result['file'] ?? null, $result['line'] ?? null, $result['column'] ?? null, $result['file_offset'] ?? null, $result['source'] ?? null];
    }

    /**
     * Element by element: null first, strings bytewise, numbers by value.
     *
     * @param list<mixed> $left
     * @param list<mixed> $right
     */
    private static function compareKeys(array $left, array $right): int
    {
        foreach ($left as $index => $value) {
            $other = $right[$index] ?? null;
            if ($value === $other) {
                continue;
            }
            if (null === $value) {
                return -1;
            }
            if (null === $other) {
                return 1;
            }
            if (\is_string($value) && \is_string($other)) {
                return strcmp($value, $other) <=> 0;
            }

            return $value <=> $other;
        }

        return 0;
    }
}
