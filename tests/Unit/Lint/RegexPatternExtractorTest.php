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
use PHPRegex\Linter\Internal\ForkedWorkerPool;
use PHPRegex\Linter\LintException;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class RegexPatternExtractorTest extends TestCase
{
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

    public function test_supports_parallel(): void
    {
        $actual = PatternExtractor::supportsParallel();

        $this->assertIsBool($actual);
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

    public function test_extract_with_progress_callback(): void
    {
        $file1 = $this->createTempPhpFile();
        $file2 = $this->createTempPhpFile();

        try {
            $occurrences = [
                $file1 => new PatternOccurrence('/test1/', 'file1.php', 1, 'source1'),
                $file2 => new PatternOccurrence('/test2/', 'file2.php', 2, 'source2'),
            ];

            $this->extractor->method('extract')->willReturnCallback(
                static function (array $files) use ($occurrences): array {
                    if (
                        1 === \count($files)
                        && isset($files[0])
                        && \is_string($files[0])
                        && isset($occurrences[$files[0]])
                    ) {
                        return [$occurrences[$files[0]]];
                    }

                    return array_values($occurrences);
                },
            );

            $progressCalls = [];
            $result = $this->patternExtractor->extract(
                [$file1, $file2],
                null,
                static function (int $current, int $total) use (&$progressCalls): void {
                    $progressCalls[] = ['current' => $current, 'total' => $total];
                },
            );
        } finally {
            @unlink($file1);
            @unlink($file2);
        }

        $this->assertCount(2, $result);
        $this->assertCount(3, $progressCalls);
    }

    public function test_extract_with_empty_result_reports_progress(): void
    {
        $this->extractor->method('extract')->willReturn([]);

        $progressCalls = [];
        $result = $this->patternExtractor->extract(
            ['src/'],
            null,
            static function (int $current, int $total) use (&$progressCalls): void {
                $progressCalls[] = ['current' => $current, 'total' => $total];
            },
        );

        $this->assertSame([], $result);
        $this->assertNotEmpty($progressCalls);
    }

    public function test_extract_reports_progress_with_no_files(): void
    {
        $this->extractor->method('extract')->willReturn([]);

        $progressCalls = [];
        $result = $this->patternExtractor->extract(
            [],
            null,
            static function (int $current, int $total) use (&$progressCalls): void {
                $progressCalls[] = [$current, $total];
            },
        );

        $this->assertSame([], $result);
        $this->assertSame([[0, 0]], $progressCalls);
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

    public function test_extract_parallel_falls_back_on_fork_failure(): void
    {
        $file1 = $this->createTempPhpFile();
        $file2 = $this->createTempPhpFile();
        $path1 = sys_get_temp_dir().'/regexparser_extract_'.uniqid('', true);
        $path2 = sys_get_temp_dir().'/regexparser_extract_'.uniqid('', true);

        LintFunctionOverrides::queueTempnam($path1);
        LintFunctionOverrides::queueTempnam($path2);
        LintFunctionOverrides::queuePcntlForkResult(1234);
        LintFunctionOverrides::queuePcntlForkResult(-1);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        try {
            $occurrences = [
                $file1 => new PatternOccurrence('/test1/', $file1, 1, 'source1'),
                $file2 => new PatternOccurrence('/test2/', $file2, 2, 'source2'),
            ];

            $this->extractor->method('extract')->willReturnCallback(
                static function (array $files) use ($occurrences): array {
                    if (
                        1 === \count($files)
                        && isset($files[0])
                        && \is_string($files[0])
                        && isset($occurrences[$files[0]])
                    ) {
                        return [$occurrences[$files[0]]];
                    }

                    return array_values($occurrences);
                },
            );

            $progressCalls = [];
            $result = $this->patternExtractor->extract(
                [$file1, $file2],
                null,
                static function (int $current, int $total) use (&$progressCalls): void {
                    $progressCalls[] = [$current, $total];
                },
                2,
            );
        } finally {
            @unlink($file1);
            @unlink($file2);
            @unlink($path2);
        }

        $this->assertCount(2, $result);
        $this->assertNotEmpty($progressCalls);
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

    public function test_write_worker_payload_creates_serialized_file(): void
    {
        $tmpFile = sys_get_temp_dir().'/test_payload_'.uniqid();
        $payload = ['ok' => true, 'result' => ['test']];

        (new ForkedWorkerPool())->runChild(static fn (): array => ['test'], $tmpFile);

        $this->assertFileExists($tmpFile);
        $content = file_get_contents($tmpFile);
        $this->assertIsString($content);
        $unserialized = unserialize($content);
        $this->assertIsArray($unserialized);
        /* @var array{ok: bool, result: array<int, string>} $unserialized */
        $this->assertSame($payload['ok'], $unserialized['ok']);

        @unlink($tmpFile);
    }

    /**
     * The occurrences a child collects come back to the parent whole.
     */
    public function test_worker_payload_round_trips_the_occurrences_a_child_writes(): void
    {
        $tmpFile = sys_get_temp_dir().'/test_payload_'.uniqid();
        $occurrence = new PatternOccurrence('/a+/', 'test.php', 3, 'preg_match');

        (new ForkedWorkerPool())->runChild(static fn (): array => [$occurrence], $tmpFile);
        $result = (new \ReflectionMethod($this->patternExtractor, 'readWorkerPayload'))->invoke($this->patternExtractor, $tmpFile);
        @unlink($tmpFile);

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertEquals(['ok' => true, 'result' => [$occurrence]], $result);
    }

    public function test_worker_payload_round_trips_the_failure_a_child_writes(): void
    {
        $tmpFile = sys_get_temp_dir().'/test_payload_'.uniqid();

        (new ForkedWorkerPool())->runChild(static fn (): never => throw new \LogicException('Broken chunk'), $tmpFile);
        $result = (new \ReflectionMethod($this->patternExtractor, 'readWorkerPayload'))->invoke($this->patternExtractor, $tmpFile);
        @unlink($tmpFile);

        $this->assertSame(['ok' => false, 'error' => ['message' => 'Broken chunk', 'class' => \LogicException::class]], $result);
    }

    public function test_read_worker_payload_reads_valid_payload(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('readWorkerPayload');

        $tmpFile = sys_get_temp_dir().'/test_read_payload_'.uniqid();
        copy(__DIR__.'/../../Fixtures/Lint/valid_result_payload.txt', $tmpFile);

        $result = $method->invoke($this->patternExtractor, $tmpFile);
        $this->assertIsArray($result);
        /* @var array{ok: bool, result: array<int, string>} $result */

        $this->assertTrue($result['ok']);
        $this->assertSame(['test'], $result['result']);

        @unlink($tmpFile);
    }

    public function test_read_worker_payload_handles_missing_file(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('readWorkerPayload');

        $nonExistentFile = sys_get_temp_dir().'/non_existent_'.uniqid();

        $result = $method->invoke($this->patternExtractor, $nonExistentFile);
        $this->assertIsArray($result);
        /* @var array{ok: bool, error?: mixed} $result */

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_read_worker_payload_handles_invalid_serialization(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('readWorkerPayload');

        $tmpFile = sys_get_temp_dir().'/test_invalid_payload_'.uniqid();
        copy(__DIR__.'/../../Fixtures/Lint/invalid_serialized.txt', $tmpFile);

        $result = $method->invoke($this->patternExtractor, $tmpFile);
        $this->assertIsArray($result);
        /* @var array{ok: bool, error?: mixed} $result */

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);

        @unlink($tmpFile);
    }

    public function test_read_worker_payload_handles_ok_false(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('readWorkerPayload');

        $tmpFile = sys_get_temp_dir().'/test_error_payload_'.uniqid();
        copy(__DIR__.'/../../Fixtures/Lint/test_error_payload.txt', $tmpFile);

        $result = $method->invoke($this->patternExtractor, $tmpFile);
        $this->assertIsArray($result);
        /* @var array{ok: bool, error?: array{message?: string, class?: string}} $result */

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertIsArray($result['error']);
        /* @var array{message?: string, class?: string} $error */
        $error = $result['error'];
        $this->assertSame('Test error', $error['message']);
        $this->assertSame('Exception', $error['class']);

        @unlink($tmpFile);
    }

    public function test_read_worker_payload_handles_invalid_error_payload(): void
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $method = $reflection->getMethod('readWorkerPayload');

        $tmpFile = sys_get_temp_dir().'/test_bad_error_payload_'.uniqid();
        copy(__DIR__.'/../../Fixtures/Lint/invalid_error_payload.txt', $tmpFile);

        $result = $method->invoke($this->patternExtractor, $tmpFile);
        $this->assertIsArray($result);
        /* @var array{ok: bool, error?: mixed} $result */

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);

        @unlink($tmpFile);
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

    public function test_extract_parallel_handles_tempnam_failure(): void
    {
        LintFunctionOverrides::queueTempnam(false);
        $this->extractor->method('extract')->willReturn([]);

        $result = $this->invokePrivate('extractParallel', ['a.php', 'b.php'], 2, null);

        $this->assertSame([], $result);
    }

    public function test_extract_parallel_merges_results_and_reports_progress(): void
    {
        $path1 = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);
        $path2 = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);

        $occ1 = new PatternOccurrence('/a+/', 'a.php', 1, 'preg_match');
        $occ2 = new PatternOccurrence('/b+/', 'b.php', 2, 'preg_match');

        copy(__DIR__.'/../../Fixtures/Lint/occ_a_payload.txt', $path1);
        copy(__DIR__.'/../../Fixtures/Lint/occ_b_payload.txt', $path2);

        LintFunctionOverrides::queueTempnam($path1);
        LintFunctionOverrides::queueTempnam($path2);
        LintFunctionOverrides::queuePcntlForkResult(111);
        LintFunctionOverrides::queuePcntlForkResult(222);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $progressCalls = [];
        $result = $this->invokePrivate(
            'extractParallel',
            ['a.php', 'b.php'],
            2,
            static function (int $current, int $total) use (&$progressCalls): void {
                $progressCalls[] = [$current, $total];
            },
        );

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertNotEmpty($progressCalls);
    }

    public function test_extract_parallel_throws_on_worker_error_payload(): void
    {
        $path = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);
        copy(__DIR__.'/../../Fixtures/Lint/error_payload.txt', $path);

        LintFunctionOverrides::queueTempnam($path);
        LintFunctionOverrides::queuePcntlForkResult(111);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $this->expectException(LintException::class);
        $this->expectExceptionMessage('Parallel collection failed: RuntimeException: Boom');

        $this->invokePrivate('extractParallel', ['a.php'], 1, null);
    }

    public function test_extract_parallel_skips_non_array_results(): void
    {
        $path = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);
        copy(__DIR__.'/../../Fixtures/Lint/not_array_result_payload.txt', $path);

        LintFunctionOverrides::queueTempnam($path);
        LintFunctionOverrides::queuePcntlForkResult(111);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $result = $this->invokePrivate('extractParallel', ['a.php'], 1, null);

        $this->assertSame([], $result);
    }

    private function createTempPhpFile(string $fixture = 'default.php'): string
    {
        $path = sys_get_temp_dir().'/regexparser_'.uniqid('', true).'.php';
        copy(__DIR__.'/../../Fixtures/Lint/'.$fixture, $path);

        return $path;
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->patternExtractor);
        $refMethod = $reflection->getMethod($method);

        return $refMethod->invoke($this->patternExtractor, ...$args);
    }
}
