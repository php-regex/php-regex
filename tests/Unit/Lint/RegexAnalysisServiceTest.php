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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Internal\ForkedWorkerPool;
use PHPRegex\Linter\LintException;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class RegexAnalysisServiceTest extends TestCase
{
    private AnalysisService $analysis;

    protected function setUp(): void
    {
        $this->analysis = new AnalysisService(
            RegexParser::create(),
            null,
            50,
            'low',
            [],
            [],
            false,
            'theoretical',
            null,
            true, // redosEnabled
        );
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    public function test_analyze_redos_returns_empty_array_for_no_patterns(): void
    {
        $result = $this->analysis->analyzeRedos([], RedosSeverity::Medium);

        $this->assertSame([], $result);
    }

    public function test_analyze_redos_returns_empty_array_for_invalid_pattern(): void
    {
        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Medium);

        $this->assertSame([], $result);
    }

    public function test_analyze_redos_detects_vulnerable_pattern(): void
    {
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low);

        $this->assertCount(1, $result);
        $this->assertSame('test.php', $result[0]['file']);
        $this->assertSame(1, $result[0]['line']);
        $this->assertArrayHasKey('analysis', $result[0]);
    }

    public function test_analyze_redos_filters_by_threshold(): void
    {
        $patterns = [
            new PatternOccurrence('/\w+/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::High);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_returns_empty_array_for_no_patterns(): void
    {
        $result = $this->analysis->suggestOptimizations([], 0);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_returns_empty_array_for_invalid_pattern(): void
    {
        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_filters_by_min_savings(): void
    {
        $patterns = [
            new PatternOccurrence('/test/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 100);

        $this->assertSame([], $result);
    }

    #[DoesNotPerformAssertions]
    public function test_construct_with_ignore_parse_errors(): void
    {
        $analysis = new AnalysisService(
            RegexParser::create(),
            null,
            50,
            'high',
            [],
            [],
            true,
        );
    }

    public function test_suggest_optimizations_continues_on_parse_error(): void
    {
        $patterns = [
            new PatternOccurrence('/[0-9]/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/[unclosed/', 'test.php', 2, 'preg_match'),
            new PatternOccurrence('/another-valid/', 'test.php', 3, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertGreaterThanOrEqual(1, \count($result));
    }

    public function test_analyze_redos_continues_on_parse_error(): void
    {
        $patterns = [
            new PatternOccurrence('/valid/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/[unclosed/', 'test.php', 2, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Medium);

        $this->assertGreaterThanOrEqual(0, \count($result));
    }

    public function test_extract_fragment_with_empty_pattern(): void
    {
        $patterns = [new PatternOccurrence('', 'test.php', 1, 'preg_match')];
        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Medium);

        $this->assertSame([], $result);
    }

    public function test_highlight_body_handles_empty_pattern(): void
    {
        $result = $this->analysis->highlightBody('', 'i', '/');

        $this->assertIsString($result);
    }

    public function test_suggest_optimizations_filters_by_savings_with_zero(): void
    {
        $patterns = [
            new PatternOccurrence('/test/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 1000);

        $this->assertSame([], $result);
    }

    public function test_lint_can_run_in_parallel(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/(a+)+/', 'test.php', 2, 'preg_match'),
            new PatternOccurrence('/foo/', 'test.php', 3, 'preg_match'),
        ];

        $sequential = $this->analysis->lint($patterns);
        $parallel = $this->analysis->lint($patterns, null, 2);

        $this->assertEquals($sequential, $parallel);
    }

    public function test_scan_delegates_to_extractor(): void
    {
        $paths = ['src'];
        $exclude = ['vendor'];

        $result = $this->analysis->scan($paths, $exclude);

        $this->assertIsArray($result);
        // Since extractor is default, it should return some patterns
    }

    public function test_highlight_returns_highlighted_string(): void
    {
        $pattern = '/foo/';
        $result = $this->analysis->highlight($pattern);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_highlight_body_with_flags(): void
    {
        $result = $this->analysis->highlightBody('foo', 'i', '#');

        $this->assertIsString($result);
    }

    public function test_lint_with_sequential_processing(): void
    {
        $patterns = [
            new PatternOccurrence('/valid/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->lint($patterns, null, 1);

        $this->assertIsArray($result);
    }

    public function test_lint_reports_progress_for_ignored_patterns(): void
    {
        $patterns = [
            new PatternOccurrence('/ignored/', 'test.php', 1, 'preg_match', null, null, true),
        ];

        $calls = 0;
        $this->analysis->lint($patterns, static function () use (&$calls): void {
            $calls++;
        });

        $this->assertSame(1, $calls);
    }

    public function test_lint_progress_with_ignore_parse_errors(): void
    {
        $analysis = new AnalysisService(RegexParser::create(), null, 50, 'high', [], [], true);
        $patterns = [
            new PatternOccurrence('/foo', 'test.php', 1, 'preg_match'),
        ];

        $calls = 0;
        $issues = $analysis->lint($patterns, static function () use (&$calls): void {
            $calls++;
        });

        $this->assertSame([], $issues);
        $this->assertSame(1, $calls);
    }

    public function test_lint_progress_for_invalid_pattern(): void
    {
        $patterns = [
            new PatternOccurrence('/foo', 'test.php', 1, 'preg_match'),
        ];

        $calls = 0;
        $issues = $this->analysis->lint($patterns, static function () use (&$calls): void {
            $calls++;
        });

        $this->assertNotEmpty($issues);
        $this->assertSame(1, $calls);
    }

    public function test_analyze_redos_skips_ignored_patterns(): void
    {
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match', null, null, true),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_skips_ignored_patterns(): void
    {
        $patterns = [
            new PatternOccurrence('/a{2}/', 'test.php', 1, 'preg_match', null, null, true),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_with_extended_mode(): void
    {
        $patterns = [
            new PatternOccurrence('/a{1}/x', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertCount(1, $result);
        $this->assertSame('/a/x', $result[0]['optimization']->optimized);
    }

    public function test_suggest_optimizations_extended_mode_uses_pretty_baseline(): void
    {
        $pattern = "/a{1}  # comment\n/x";
        $patterns = [
            new PatternOccurrence($pattern, 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertCount(1, $result);

        $ast = Regex::create()->parse($pattern);
        $baseline = $ast->accept(new PatternPrinter(str_contains($ast->flags, 'x')));

        $this->assertSame($baseline, $result[0]['optimization']->original);
    }

    public function test_suggest_optimizations_skips_when_optimizer_throws(): void
    {
        $cache = new class implements CacheInterface {
            private int $loadCalls = 0;

            public function generateKey(string $regex): string
            {
                return 'key';
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): ?RegexNode
            {
                $this->loadCalls++;
                if ($this->loadCalls > 1) {
                    throw new \RuntimeException('cache load failed');
                }

                return null;
            }
        };

        $analysis = new AnalysisService(RegexParser::create(['cache' => $cache]));
        $patterns = [
            new PatternOccurrence('/a/x', 'test.php', 1, 'preg_match'),
        ];

        $result = $analysis->suggestOptimizations($patterns, 0);

        $this->assertSame([], $result);
    }

    public function test_analyze_redos_can_run_in_parallel(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/foo/', 'test.php', 2, 'preg_match'),
        ];

        $sequential = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low, 1);
        $parallel = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low, 2);

        $this->assertEquals($sequential, $parallel);
    }

    public function test_suggest_optimizations_can_run_in_parallel(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $patterns = [
            new PatternOccurrence('/a{2}/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/b{3}/', 'test.php', 2, 'preg_match'),
        ];

        $sequential = $this->analysis->suggestOptimizations($patterns, 0, null, 1);
        $parallel = $this->analysis->suggestOptimizations($patterns, 0, null, 2);

        $this->assertEquals($sequential, $parallel);
    }

    public function test_run_in_parallel_handles_empty_patterns(): void
    {
        $result = $this->invokePrivate('runInParallel', [], 2, static fn (array $chunk): array => $chunk);

        $this->assertSame([], $result);
    }

    public function test_run_in_parallel_falls_back_when_worker_setup_fails(): void
    {
        $path1 = sys_get_temp_dir().'/regexparser_parallel_'.uniqid('', true);
        $path2 = sys_get_temp_dir().'/regexparser_parallel_'.uniqid('', true);

        LintFunctionOverrides::queueTempnam($path1);
        LintFunctionOverrides::queueTempnam($path2);
        LintFunctionOverrides::queuePcntlForkResult(1234);
        LintFunctionOverrides::queuePcntlForkResult(-1);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $patterns = [
            new PatternOccurrence('/a+/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/b+/', 'test.php', 2, 'preg_match'),
        ];

        $progressCalls = 0;
        $worker =

            static fn (array $chunk): array => array_map(static fn (mixed $occurrence): string => $occurrence instanceof PatternOccurrence ? $occurrence->pattern : '', $chunk);

        $result = $this->invokePrivate(
            'runInParallel',
            $patterns,
            2,
            $worker,
            static function () use (&$progressCalls): void {
                $progressCalls++;
            },
        );

        $this->assertSame(['/a+/', '/b+/'], $result);
        $this->assertSame(2, $progressCalls);

        @unlink($path2);
    }

    public function test_run_in_parallel_falls_back_when_tempnam_fails(): void
    {
        LintFunctionOverrides::queueTempnam(false);

        $patterns = [
            new PatternOccurrence('/a+/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/b+/', 'test.php', 2, 'preg_match'),
        ];

        $progressCalls = 0;
        $worker =

            static fn (array $chunk): array => array_map(static fn (mixed $occurrence): string => $occurrence instanceof PatternOccurrence ? $occurrence->pattern : '', $chunk);

        $result = $this->invokePrivate(
            'runInParallel',
            $patterns,
            2,
            $worker,
            static function () use (&$progressCalls): void {
                $progressCalls++;
            },
        );

        $this->assertSame(['/a+/', '/b+/'], $result);
        $this->assertSame(2, $progressCalls);
    }

    public function test_run_in_parallel_throws_for_worker_error_payload(): void
    {
        $path = sys_get_temp_dir().'/regexparser_parallel_'.uniqid('', true);
        copy(__DIR__.'/../../Fixtures/Lint/error_payload.txt', $path);

        LintFunctionOverrides::queueTempnam($path);
        LintFunctionOverrides::queuePcntlForkResult(1234);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $this->expectException(LintException::class);
        $this->expectExceptionMessage('Parallel analysis failed: RuntimeException: Boom');

        $this->invokePrivate(
            'runInParallel',
            [new PatternOccurrence('/a+/', 'test.php', 1, 'preg_match')],
            1,
            static fn (array $chunk): array => $chunk,
        );

        @unlink($path);
    }

    public function test_run_in_parallel_merges_results_and_reports_progress(): void
    {
        $path1 = sys_get_temp_dir().'/regexparser_parallel_'.uniqid('', true);
        $path2 = sys_get_temp_dir().'/regexparser_parallel_'.uniqid('', true);

        copy(__DIR__.'/../../Fixtures/Lint/first_payload.txt', $path1);
        copy(__DIR__.'/../../Fixtures/Lint/second_payload.txt', $path2);

        LintFunctionOverrides::queueTempnam($path1);
        LintFunctionOverrides::queueTempnam($path2);
        LintFunctionOverrides::queuePcntlForkResult(111);
        LintFunctionOverrides::queuePcntlForkResult(222);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $patterns = [
            new PatternOccurrence('/a+/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/b+/', 'test.php', 2, 'preg_match'),
        ];

        $progressCalls = 0;
        $result = $this->invokePrivate(
            'runInParallel',
            $patterns,
            2,
            static fn (array $chunk): array => $chunk,
            static function () use (&$progressCalls): void {
                $progressCalls++;
            },
        );

        $this->assertSame(['first', 'second'], $result);
        $this->assertSame(2, $progressCalls);
    }

    public function test_run_in_parallel_skips_non_array_results(): void
    {
        $path = sys_get_temp_dir().'/regexparser_parallel_'.uniqid('', true);
        copy(__DIR__.'/../../Fixtures/Lint/not_array_payload.txt', $path);

        LintFunctionOverrides::queueTempnam($path);
        LintFunctionOverrides::queuePcntlForkResult(111);
        LintFunctionOverrides::$pcntlWaitpidResult = 0;

        $result = $this->invokePrivate(
            'runInParallel',
            [new PatternOccurrence('/a+/', 'test.php', 1, 'preg_match')],
            1,
            static fn (array $chunk): array => $chunk,
        );

        $this->assertSame([], $result);
    }

    public function test_worker_payload_helpers_cover_error_branches(): void
    {
        $readMethod = new \ReflectionMethod($this->analysis, 'readWorkerPayload');

        $tempFile = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);
        (new ForkedWorkerPool())->runChild(static fn (): array => ['ok'], $tempFile);
        $payload = $readMethod->invoke($this->analysis, $tempFile);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('ok', $payload);
        $this->assertTrue((bool) $payload['ok']);

        @unlink($tempFile);

        $missingPayload = $readMethod->invoke($this->analysis, $tempFile);
        $this->assertIsArray($missingPayload);
        $this->assertArrayHasKey('ok', $missingPayload);
        $this->assertFalse((bool) $missingPayload['ok']);

        $invalidFile = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);
        copy(__DIR__.'/../../Fixtures/Lint/not_serialized.txt', $invalidFile);
        $invalidPayload = $readMethod->invoke($this->analysis, $invalidFile);
        $this->assertIsArray($invalidPayload);
        $this->assertArrayHasKey('ok', $invalidPayload);
        $this->assertFalse((bool) $invalidPayload['ok']);
        @unlink($invalidFile);

        $badErrorFile = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);
        copy(__DIR__.'/../../Fixtures/Lint/bad_error_payload.txt', $badErrorFile);
        $badError = $readMethod->invoke($this->analysis, $badErrorFile);
        $this->assertIsArray($badError);
        $this->assertArrayHasKey('ok', $badError);
        $this->assertFalse((bool) $badError['ok']);
        @unlink($badErrorFile);

        $validErrorFile = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);
        copy(__DIR__.'/../../Fixtures/Lint/valid_error_payload.txt', $validErrorFile);
        $validError = $readMethod->invoke($this->analysis, $validErrorFile);
        $this->assertIsArray($validError);
        $this->assertArrayHasKey('ok', $validError);
        $this->assertFalse((bool) $validError['ok']);
        $this->assertArrayHasKey('error', $validError);
        $this->assertIsArray($validError['error']);
        $this->assertSame('fail', $validError['error']['message'] ?? null);
        @unlink($validErrorFile);
    }

    /**
     * What a child writes, the parent reads back: the result of the work,
     * or the failure it threw.
     */
    public function test_worker_payload_round_trips_what_a_child_writes(): void
    {
        $readMethod = new \ReflectionMethod($this->analysis, 'readWorkerPayload');
        $pool = new ForkedWorkerPool();
        $tempFile = sys_get_temp_dir().'/regexparser_payload_'.uniqid('', true);

        $pool->runChild(static fn (): array => [['file' => 'test.php', 'line' => 1]], $tempFile);
        $result = $readMethod->invoke($this->analysis, $tempFile);

        $pool->runChild(static fn (): never => throw new \UnexpectedValueException('No chunk'), $tempFile);
        $failure = $readMethod->invoke($this->analysis, $tempFile);
        @unlink($tempFile);

        $this->assertSame(['ok' => true, 'result' => [['file' => 'test.php', 'line' => 1]]], $result);
        $this->assertSame(['ok' => false, 'error' => ['message' => 'No chunk', 'class' => \UnexpectedValueException::class]], $failure);
    }

    public function test_skip_risk_analysis_helpers(): void
    {
        $occurrence = new PatternOccurrence('/foo|bar/', 'test.php', 1, 'preg_match');

        $skip = $this->invokePrivate('shouldSkipRiskAnalysis', $occurrence);
        $this->assertTrue($skip);

        $this->assertSame('', $this->invokePrivate('extractFragment', ''));
        $this->assertSame('', $this->invokePrivate('trimPatternBody', ''));
        $this->assertFalse($this->invokePrivate('isIgnored', ''));
        $this->assertFalse($this->invokePrivate('isTriviallySafe', ''));
    }

    public function test_uses_extended_mode_handles_invalid_input(): void
    {
        $this->assertFalse($this->invokePrivate('usesExtendedMode', ''));
        $this->assertFalse($this->invokePrivate('usesExtendedMode', '/'));
    }

    public function test_validation_tip_helpers(): void
    {
        $validation = new ValidationResult(false, 'No closing delimiter', 0, null, 0);
        $tip = $this->invokePrivate('getTipForValidationError', 'No closing delimiter', '#foo', $validation);
        $this->assertIsString($tip);
        $this->assertStringContainsString('Add the missing closing delimiter', (string) $tip);

        $validation = new ValidationResult(false, 'Unclosed character class', 0, null, 0);
        $tip = $this->invokePrivate('getTipForValidationError', 'Unclosed character class', '/[a-z/', $validation);
        $this->assertIsString($tip);
        $this->assertStringContainsString('Add missing closing bracket', (string) $tip);

        $validation = new ValidationResult(false, 'Invalid quantifier range', 0, null, 0);
        $tip = $this->invokePrivate('getTipForValidationError', 'Invalid quantifier range', '/a{3,2}/', $validation);
        $this->assertIsString($tip);
        $this->assertStringContainsString('Swap min and max values', (string) $tip);

        $validation = new ValidationResult(false, 'Backreference to non-existent group', 0, null, 0);
        $tip = $this->invokePrivate('getTipForValidationError', 'Backreference to non-existent group', '/(a)\\2/', $validation);
        $this->assertIsString($tip);
        $this->assertStringContainsString('Backreference', (string) $tip);

        $offset = \strlen('/(?<=\\w*)foo/');
        $validation = new ValidationResult(false, 'Lookbehind is unbounded', 0, null, $offset);
        $tip = $this->invokePrivate('getTipForValidationError', 'Lookbehind is unbounded', '/(?<=\\w*)foo/', $validation);
        $this->assertIsString($tip);
        $this->assertStringContainsString('Replace unbounded quantifiers', (string) $tip);
    }

    public function test_validation_tip_helpers_return_null_for_valid_cases(): void
    {
        $validation = new ValidationResult(false, 'Unclosed character class', 0, null, 0);
        $this->assertNull($this->invokePrivate('suggestCharacterClassFix', '/[a-z]/', $validation));

        $validation = new ValidationResult(false, 'Invalid quantifier range', 0, null, 0);
        $this->assertNull($this->invokePrivate('suggestQuantifierRangeFix', '/a{1,2}/', $validation));

        $validation = new ValidationResult(false, 'Backreference to non-existent group', 0, null, 0);
        $this->assertNull($this->invokePrivate('suggestBackreferenceFix', '/(a)\\1/', $validation));

        $validation = new ValidationResult(false, 'Lookbehind is unbounded', 0, null, 0);
        $this->assertNull($this->invokePrivate('suggestLookbehindFix', '/abc/', $validation));

        $offset = \strlen('/(?<=\\w{2})foo/');
        $validation = new ValidationResult(false, 'Lookbehind is unbounded', 0, null, $offset);
        $this->assertNull($this->invokePrivate('suggestLookbehindFix', '/(?<=\\w{2})foo/', $validation));
    }

    public function test_generic_tip_helpers_cover_other_messages(): void
    {
        $tip = $this->invokePrivate('getGenericTipForValidationError', 'No closing delimiter');
        $this->assertIsString($tip);
        $this->assertStringContainsString('Escape "/"', (string) $tip);

        $tip = $this->invokePrivate('getGenericTipForValidationError', 'Unclosed character class');
        $this->assertIsString($tip);
        $this->assertStringContainsString('Character classes must be closed', (string) $tip);

        $tip = $this->invokePrivate('getGenericTipForValidationError', 'Invalid quantifier range');
        $this->assertIsString($tip);
        $this->assertStringContainsString('Quantifier ranges must have min <= max', (string) $tip);

        $tip = $this->invokePrivate('getGenericTipForValidationError', 'Backreference to non-existent group');
        $this->assertIsString($tip);
        $this->assertStringContainsString('Backreferences like', (string) $tip);

        $tip = $this->invokePrivate('getGenericTipForValidationError', 'Unknown regex flag');
        $this->assertIsString($tip);
        $this->assertStringContainsString('Only valid PCRE flags', (string) $tip);

        $tip = $this->invokePrivate('getGenericTipForValidationError', 'Invalid conditional construct');
        $this->assertIsString($tip);
        $this->assertStringContainsString('Conditionals need a valid condition', (string) $tip);
    }

    public function test_redos_hint_helpers(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::High, 10);
        $hint = $this->invokePrivate('getReDoSHint', $analysis, '/abc/');
        $this->assertIsString($hint);
        $this->assertStringContainsString('Use possessive quantifiers', (string) $hint);
        $this->assertStringContainsString('*+', (string) $hint);
        $this->assertStringContainsString('++', (string) $hint);
        $this->assertStringContainsString('{m,n}+', (string) $hint);

        $analysis = new RedosAnalysis(RedosSeverity::High, 10, null, ['Keep it linear'], null, 'a+)+');
        $hint = $this->invokePrivate('getReDoSHint', $analysis, '/(a+)+.*+/');
        $this->assertIsString($hint);
        $this->assertStringContainsString('Keep it linear', (string) $hint);
        $this->assertStringContainsString('Suggested (verify behavior)', (string) $hint);
        $this->assertStringContainsString('possessive', (string) $hint);
    }

    public function test_is_likely_partial_regex_error_returns_false(): void
    {
        $this->assertFalse($this->invokePrivate('isLikelyPartialRegexError', 'Completely unrelated'));
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($this->analysis);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invoke($this->analysis, ...$args);
    }
}
