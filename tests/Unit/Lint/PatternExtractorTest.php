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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintException;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\RecordingLintProgress;
use PHPRegex\Tests\Support\StringListAssertions;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class PatternExtractorTest extends TestCase
{
    use StringListAssertions;

    use TemporaryProject;

    private ExtractorInterface&Stub $extractor;

    private PatternExtractor $patternExtractor;

    protected function setUp(): void
    {
        $this->extractor = $this->createStub(ExtractorInterface::class);
        $this->patternExtractor = new PatternExtractor($this->extractor);
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    public function test_extract_with_empty_paths(): void
    {
        $this->extractor->method('extract')->willReturn([]);

        $result = $this->patternExtractor->extract([]);

        $this->assertSame([], $result);
    }

    public function test_extract_with_default_exclude_paths(): void
    {
        $this->extractor->method('extract')->willReturn([]);

        $result = $this->patternExtractor->extract(['src/']);

        $this->assertSame([], $result);
    }

    public function test_extract_with_custom_exclude_paths(): void
    {
        $this->extractor->method('extract')->willReturn([]);

        $result = $this->patternExtractor->extract(['src/'], ['tests', 'vendor']);

        $this->assertSame([], $result);
    }

    /**
     * The file walk is a step of its own, with no total, then the files are
     * extracted under a step whose total is the number found. An extractor
     * that is not told about pattern functions has no declarations step.
     */
    public function test_extract_progress_reports_the_files_step_then_the_patterns_step(): void
    {
        $file1 = $this->createTempPhpFile();
        $file2 = $this->createTempPhpFile();
        $progress = new RecordingLintProgress();

        try {
            $this->extractor->method('extract')->willReturnCallback(self::onePerFile(...));

            $result = $this->patternExtractor->extract([$file1, $file2], null, $progress);
        } finally {
            @unlink($file1);
            @unlink($file2);
        }

        $this->assertCount(2, $result);
        $this->assertSame([[LintProgress::STEP_FILES, null], [LintProgress::STEP_PATTERNS, 2]], $progress->steps());
        $this->assertSame(2, $progress->advancedIn(LintProgress::STEP_FILES));
        $this->assertSame(2, $progress->advancedIn(LintProgress::STEP_PATTERNS));
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_PATTERNS], $progress->finishedSteps());
        $this->assertSame(2, $progress->finishedCount());
        $this->assertSame(0, $progress->advancedOutsideSteps());
        $this->assertSame([], $progress->workerCounts());
    }

    /**
     * On a real tree of more than a thousand files, the files step counts
     * every file found, in batches or not, and the patterns step extracts
     * that many.
     */
    public function test_extract_progress_extracts_as_many_files_as_the_files_step_found(): void
    {
        $this->extractor->method('extract')->willReturn([]);
        $progress = new RecordingLintProgress();

        $result = $this->patternExtractor->extract(['src/', 'tests/'], null, $progress);

        $this->assertSame([], $result);
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_PATTERNS], array_column($progress->steps(), 0));
        $found = $progress->advancedIn(LintProgress::STEP_FILES);
        $this->assertGreaterThan(1000, $found);
        $this->assertSame($found, $progress->steps()[1][1]);
        $this->assertSame($found, $progress->advancedIn(LintProgress::STEP_PATTERNS));
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_PATTERNS], $progress->finishedSteps());
    }

    /**
     * With no file found, the files step is started and finished, and
     * nothing else: there is nothing to extract.
     */
    public function test_extract_with_no_files_reports_only_the_files_step(): void
    {
        $this->extractor->method('extract')->willReturn([]);
        $progress = new RecordingLintProgress();

        $result = $this->patternExtractor->extract([], null, $progress);

        $this->assertSame([], $result);
        $this->assertSame([[LintProgress::STEP_FILES, null]], $progress->steps());
        $this->assertSame([LintProgress::STEP_FILES], $progress->finishedSteps());
        $this->assertSame(0, $progress->advancedTotal());
    }

    public function test_extract_with_workers_greater_than_one(): void
    {
        $occurrence = new PatternOccurrence('/test/', 'file.php', 1, 'source');
        $this->extractor->method('extract')->willReturn([$occurrence]);

        $file = $this->createTempPhpFile();

        try {
            $result = $this->patternExtractor->extract([$file], null, null, 2);
        } finally {
            @unlink($file);
        }

        $this->assertCount(1, $result);
    }

    /**
     * When no worker can be forked, the extraction runs in the parent: the
     * same occurrences, the same steps, every file advanced.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_extract_falls_back_to_serial_with_full_progress_when_no_worker_can_be_forked(): void
    {
        $file1 = $this->createTempPhpFile();
        $file2 = $this->createTempPhpFile();
        $serialProgress = new RecordingLintProgress();
        $fallbackProgress = new RecordingLintProgress();

        try {
            $this->extractor->method('extract')->willReturnCallback(self::onePerFile(...));
            $serial = $this->patternExtractor->extract([$file1, $file2], null, $serialProgress);

            LintFunctionOverrides::queuePcntlForkResult(-1);
            $fallback = $this->patternExtractor->extract([$file1, $file2], null, $fallbackProgress, 2);
        } finally {
            @unlink($file1);
            @unlink($file2);
        }

        $this->assertSame([], LintFunctionOverrides::$pcntlForkSequence, 'The run tried to fork.');
        $this->assertCount(2, $fallback);
        $this->assertEquals($serial, $fallback);
        $this->assertSame([[LintProgress::STEP_FILES, null, 2], [LintProgress::STEP_PATTERNS, 2, 2]], $fallbackProgress->stepSums());
        $this->assertSame($serialProgress->stepSums(), $fallbackProgress->stepSums());
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_PATTERNS], $fallbackProgress->finishedSteps());
    }

    public function test_extract_marks_inline_ignored_patterns(): void
    {
        $file = $this->createTempPhpFile('ignored_pattern.php');

        try {
            $occurrence = new PatternOccurrence('/(a+)+/', $file, 3, 'preg_match');
            $this->extractor->method('extract')->willReturn([$occurrence]);

            $result = $this->patternExtractor->extract([$file]);
        } finally {
            @unlink($file);
        }

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->isIgnored);
    }

    /**
     * Three files on three workers: the occurrences come back whole and in
     * the serial order, the steps are the serial ones with the same sums,
     * and the workers started are announced once.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_extract_on_workers_gives_the_serial_occurrences_and_progress(): void
    {
        $project = $this->makeProject(['a.php' => "<?php\n", 'b.php' => "<?php\n", 'c.php' => "<?php\n"]);
        $files = [$project.'/a.php', $project.'/b.php', $project.'/c.php'];
        $this->extractor->method('extract')->willReturnCallback(self::onePerFile(...));
        $serialProgress = new RecordingLintProgress();
        $parallelProgress = new RecordingLintProgress();

        $serial = $this->patternExtractor->extract($files, null, $serialProgress, 1);
        $parallel = $this->patternExtractor->extract($files, null, $parallelProgress, 3);

        $this->assertCount(3, $parallel);
        $this->assertContainsOnlyInstancesOf(PatternOccurrence::class, $parallel);
        $this->assertEquals($serial, $parallel);
        $this->assertSame($files, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->file, $parallel));
        $this->assertSame($serialProgress->stepSums(), $parallelProgress->stepSums());
        $this->assertSame([[LintProgress::STEP_FILES, null, 3], [LintProgress::STEP_PATTERNS, 3, 3]], $parallelProgress->stepSums());
        $this->assertSame([3], $parallelProgress->workerCountsIn(LintProgress::STEP_PATTERNS));
        $this->assertSame([], $serialProgress->workerCounts());
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_PATTERNS], $parallelProgress->finishedSteps());
        $this->assertSame(0, $parallelProgress->advancedOutsideSteps());
    }

    /**
     * A worker that throws fails the extraction with the class and message
     * it threw, and the file it was reading, its UTF-8 name kept; no
     * payload file is left.
     */
    #[DataProvider('provideWorkerFailures')]
    #[RequiresFunction('pcntl_fork')]
    public function test_extract_on_workers_reports_a_failing_worker_with_its_file(\Throwable $failure, string $expected): void
    {
        $project = $this->makeProject(['a.php' => "<?php\n", 'défaillant.php' => "<?php\n"]);
        $failing = $project.'/défaillant.php';
        $this->extractor->method('extract')->willReturnCallback(
            static function (array $files) use ($failing, $failure): array {
                self::assertOnlyStrings($files);
                if (\in_array($failing, $files, true)) {
                    throw $failure;
                }

                return self::onePerFile($files);
            },
        );
        $before = self::payloadFiles();
        $payloads = $this->makeProject();
        LintFunctionOverrides::$tempnamDirectory = $payloads;

        try {
            $this->patternExtractor->extract([$project.'/a.php', $failing], null, new RecordingLintProgress(), 2);
            $this->fail('The failure of the worker is reported.');
        } catch (LintException $e) {
            $this->assertSame(\sprintf($expected, $failing), $e->getMessage());
        }

        $this->assertSame([], array_values(array_diff(scandir($payloads) ?: [], ['.', '..'])));
        $this->assertSame([], array_values(array_diff(self::payloadFiles(), $before)));
    }

    /**
     * @return iterable<string, array{failure: \Throwable, expected: string}>
     */
    public static function provideWorkerFailures(): iterable
    {
        yield 'runtime exception' => [
            'failure' => new \RuntimeException('Boom'),
            'expected' => 'Parallel collection failed: RuntimeException: Boom (in %s)',
        ];
        yield 'logic exception' => [
            'failure' => new \LogicException('Broken chunk'),
            'expected' => 'Parallel collection failed: LogicException: Broken chunk (in %s)',
        ];
    }

    /**
     * The inline markers are applied where the file is extracted: on the
     * workers they silence the same occurrences as in the parent.
     *
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[DataProvider('provideStrategies')]
    #[RequiresFunction('pcntl_fork')]
    public function test_extract_inline_ignores_are_the_same_in_serial_and_on_workers(\Closure $strategy): void
    {
        $project = $this->makeProject([
            'a.php' => "<?php\n// @regex-ignore-next-line\npreg_match('/a1(/', \$s);\npreg_match('/a2(/', \$s);\n",
            'b.php' => "<?php\npreg_match('/b1(/', \$s); // @regex-ignore\npreg_match('/b2(/', \$s);\n/* @regex-ignore */ preg_match('/b3(/', \$s);\n/* @regex-suppress */ preg_match('/b4(/', \$s);\n",
            'c.php' => "<?php\npreg_match('/c1(/', \$s);\n",
            'd.php' => "<?php\n/**\n * @regex-ignore-next-line\n */\npreg_match('/d1(/', \$s);\npreg_match('/d2(/', \$s);\n",
            'e.php' => "<?php\npreg_match('/e1(/', \$s);\n// @regex-ignore-next-line\n",
        ]);
        $extractor = new PatternExtractor($strategy());
        $serialProgress = new RecordingLintProgress();
        $parallelProgress = new RecordingLintProgress();

        $serial = $extractor->extract([$project], [], $serialProgress, 1);
        $parallel = $extractor->extract([$project], [], $parallelProgress, 3);

        $this->assertEquals($serial, $parallel);
        $this->assertSame(self::summary($serial), self::summary($parallel));
        $this->assertSame([
            'a.php:3:/a1(/:ignored',
            'a.php:4:/a2(/:kept',
            'b.php:2:/b1(/:ignored',
            'b.php:3:/b2(/:kept',
            'b.php:4:/b3(/:ignored',
            'b.php:5:/b4(/:kept',
            'c.php:2:/c1(/:kept',
            'd.php:5:/d1(/:ignored',
            'd.php:6:/d2(/:kept',
            'e.php:2:/e1(/:kept',
        ], self::summary($parallel));
        $this->assertSame($serialProgress->stepSums(), $parallelProgress->stepSums());
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 5],
            [LintProgress::STEP_DECLARATIONS, 5, 5],
            [LintProgress::STEP_PATTERNS, 5, 5],
        ], $parallelProgress->stepSums());
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_DECLARATIONS, LintProgress::STEP_PATTERNS], $parallelProgress->finishedSteps());
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): ExtractorInterface}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokens' => ['strategy' => static fn (): TokenBasedExtractionStrategy => new TokenBasedExtractionStrategy()];
        yield 'php-parser' => ['strategy' => static fn (): PhpParserExtractionStrategy => new PhpParserExtractionStrategy()];
    }

    public function test_is_template_file_identifies_blade(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('isTemplateFile');

        $this->assertTrue($method->invoke($this->patternExtractor, 'view.blade.php'));
    }

    public function test_is_template_file_identifies_twig(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('isTemplateFile');

        $this->assertTrue($method->invoke($this->patternExtractor, 'template.twig.php'));
    }

    public function test_is_template_file_identifies_tpl(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('isTemplateFile');

        $this->assertTrue($method->invoke($this->patternExtractor, 'file.tpl.php'));
    }

    public function test_is_template_file_identifies_regular_php(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('isTemplateFile');

        $this->assertFalse($method->invoke($this->patternExtractor, 'regular.php'));
    }

    public function test_collect_php_files_skips_templates_and_excluded_dirs(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('collectPhpFiles');

        $root = sys_get_temp_dir().'/regexparser_collect_'.uniqid('', true);
        $nested = $root.'/nested';
        $vendor = $root.'/vendor';

        mkdir($nested, 0o777, true);
        mkdir($vendor, 0o777, true);

        $keep = $root.'/keep.php';
        $template = $root.'/skip.blade.php';
        $nestedKeep = $nested.'/ok.php';
        $nestedTemplate = $nested.'/view.twig.php';
        $vendorFile = $vendor.'/skip.php';

        copy(__DIR__.'/../../Fixtures/php.txt', $keep);
        copy(__DIR__.'/../../Fixtures/php.txt', $template);
        copy(__DIR__.'/../../Fixtures/php.txt', $nestedKeep);
        copy(__DIR__.'/../../Fixtures/php.txt', $nestedTemplate);
        copy(__DIR__.'/../../Fixtures/php.txt', $vendorFile);

        try {
            $files = $method->invoke($this->patternExtractor, ['', $root], ['vendor']);
        } finally {
            @unlink($keep);
            @unlink($template);
            @unlink($nestedKeep);
            @unlink($nestedTemplate);
            @unlink($vendorFile);
            @rmdir($vendor);
            @rmdir($nested);
            @rmdir($root);
        }

        $this->assertIsArray($files);
        $this->assertContains($keep, $files);
        $this->assertContains($nestedKeep, $files);
        $this->assertNotContains($template, $files);
        $this->assertNotContains($nestedTemplate, $files);
        $this->assertNotContains($vendorFile, $files);
    }

    /**
     * Read from a base, an exclude matches a file under it by the path
     * below it, as a run from the base walks it: a directory of the same
     * name above the base excludes nothing. A file outside the base is
     * matched by the path it is walked as.
     *
     * @param list<string> $expected relative to the temporary root
     */
    #[DataProvider('provideExcludeBases')]
    public function test_excludes_are_read_from_the_base_they_are_given(?string $base, array $expected): void
    {
        $root = $this->makeProject([
            'vendor/app/src/Keep.php' => "<?php\n",
            'vendor/app/src/Legacy/Old.php' => "<?php\n",
            'vendor/app/src/Generated/Proxy.php' => "<?php\n",
            'vendor/app/vendor/acme/Lib.php' => "<?php\n",
            'vendor/lib/Shared.php' => "<?php\n",
        ]);
        $this->extractor->method('extract')->willReturnCallback(self::onePerFile(...));

        $occurrences = $this->patternExtractor->extract(
            [$root.'/vendor/app/src', $root.'/vendor/app/vendor', $root.'/vendor/lib'],
            ['vendor', 'src/Legacy', '/Generated/'],
            excludeBase: null === $base ? null : $root.'/'.$base,
        );

        $files = array_map(static fn (PatternOccurrence $occurrence): string => substr($occurrence->file, \strlen($root) + 1), $occurrences);
        sort($files);
        $this->assertSame($expected, $files);
    }

    /**
     * @return iterable<string, array{base: string|null, expected: list<string>}>
     */
    public static function provideExcludeBases(): iterable
    {
        yield 'no base: every file under a vendor directory' => ['base' => null, 'expected' => []];
        yield 'the project' => ['base' => 'vendor/app', 'expected' => ['vendor/app/src/Keep.php']];
        yield 'the project, written with a trailing slash' => ['base' => 'vendor/app/', 'expected' => ['vendor/app/src/Keep.php']];
    }

    /**
     * One occurrence per file, named after it.
     *
     * @param array<string> $files
     *
     * @return list<PatternOccurrence>
     */
    private static function onePerFile(array $files): array
    {
        return array_values(array_map(static fn (string $file): PatternOccurrence => new PatternOccurrence('/'.basename($file, '.php').'/', $file, 1, 'preg_match'), $files));
    }

    /**
     * @param array<PatternOccurrence> $occurrences
     *
     * @return list<string> "file:line:pattern:ignored|kept", sorted
     */
    private static function summary(array $occurrences): array
    {
        $summary = array_values(array_map(
            static fn (PatternOccurrence $occurrence): string => \sprintf('%s:%d:%s:%s', basename($occurrence->file), $occurrence->line, $occurrence->pattern, $occurrence->isIgnored ? 'ignored' : 'kept'),
            $occurrences,
        ));
        sort($summary);

        return $summary;
    }

    /**
     * The payload files a run of workers could leave in the temporary
     * directory.
     *
     * @return list<string>
     */
    private static function payloadFiles(): array
    {
        return array_values(array_filter(glob(sys_get_temp_dir().'/'.ForkedWorkerPool::PAYLOAD_PREFIX.'*') ?: [], is_file(...)));
    }

    private function createTempPhpFile(string $fixture = 'default.php'): string
    {
        $path = sys_get_temp_dir().'/regexparser_'.uniqid('', true).'.php';
        copy(__DIR__.'/../../Fixtures/Lint/'.$fixture, $path);

        return $path;
    }
}
