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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:compare --format=json prints the document the JSON output reference
 * describes: every key whatever the method, null when the method does not
 * fill it, and the error envelope when the comparison cannot be made.
 */
final class CompareJsonContractTest extends LaravelTestCase
{
    /**
     * @param array<string, mixed> $verdict
     */
    #[Test]
    #[DataProvider('provideComparisons')]
    public function test_the_verdict_follows_the_contract(string $pattern1, string $pattern2, string $method, int $exitCode, array $verdict): void
    {
        $status = Artisan::call('regex:compare', ['pattern1' => $pattern1, 'pattern2' => $pattern2, '--method' => $method, '--format' => 'json']);
        $output = Artisan::output();

        $this->assertSame($exitCode, $status, $output);
        $document = JsonContract::decodeDocument($output);
        JsonContract::assertShape('laravel_compare', $document);
        JsonContract::assertSnakeCaseKeys($document);
        $this->assertSame($method, $document['method']);
        $this->assertSame($verdict, array_intersect_key($document, $verdict));
    }

    /**
     * Each example is checked with preg_match() (PCRE2 10.49): [ab] and a
     * share "a"; [a-z]+ allows "aa", which edit does not; [0-9]+ and \d+
     * match the same strings without u.
     *
     * @return iterable<string, array{pattern1: string, pattern2: string, method: string, exitCode: int, verdict: array<string, mixed>}>
     */
    public static function provideComparisons(): iterable
    {
        $noExample = ['example' => null, 'counterexample' => null, 'left_only_example' => null, 'right_only_example' => null];

        yield 'disjoint' => ['pattern1' => '/[a-z]+/', 'pattern2' => '/[0-9]+/', 'method' => 'intersection', 'exitCode' => 0, 'verdict' => ['disjoint' => true, 'subset' => null, 'equivalent' => null] + $noExample];
        yield 'sharing a string' => ['pattern1' => '/[ab]/', 'pattern2' => '/a/', 'method' => 'intersection', 'exitCode' => 1, 'verdict' => ['disjoint' => false, 'subset' => null, 'equivalent' => null, 'example' => 'a', 'counterexample' => null]];
        yield 'a subset' => ['pattern1' => '/edit/', 'pattern2' => '/[a-z]+/', 'method' => 'subset', 'exitCode' => 0, 'verdict' => ['disjoint' => null, 'subset' => true, 'equivalent' => null] + $noExample];
        yield 'not a subset' => ['pattern1' => '/[a-z]+/', 'pattern2' => '/edit/', 'method' => 'subset', 'exitCode' => 1, 'verdict' => ['disjoint' => null, 'subset' => false, 'equivalent' => null, 'example' => null, 'left_only_example' => null, 'right_only_example' => null]];
        yield 'equivalent' => ['pattern1' => '/[0-9]+/', 'pattern2' => '/\d+/', 'method' => 'equivalence', 'exitCode' => 0, 'verdict' => ['disjoint' => null, 'subset' => null, 'equivalent' => true] + $noExample];
        yield 'not equivalent' => ['pattern1' => '/ab?/', 'pattern2' => '/a/', 'method' => 'equivalence', 'exitCode' => 1, 'verdict' => ['disjoint' => null, 'subset' => null, 'equivalent' => false, 'example' => null, 'counterexample' => 'ab', 'left_only_example' => 'ab', 'right_only_example' => null]];
        // Without u the byte \xFF is a string of its own: the JSON spells it.
        yield 'a byte that is not UTF-8' => ['pattern1' => "/\xFF/", 'pattern2' => '/a/', 'method' => 'equivalence', 'exitCode' => 1, 'verdict' => ['counterexample' => '\xFF', 'left_only_example' => '\xFF', 'right_only_example' => 'a']];
    }

    /**
     * @param array<string, string> $options
     */
    #[Test]
    #[DataProvider('provideFailures')]
    public function test_a_failure_prints_the_envelope(string $pattern1, string $pattern2, array $options, int $exitCode, string $stage, bool $validation): void
    {
        $status = Artisan::call('regex:compare', ['pattern1' => $pattern1, 'pattern2' => $pattern2, '--format' => 'json'] + $options);
        $output = Artisan::output();

        $this->assertSame($exitCode, $status, $output);
        $document = JsonContract::decodeDocument($output);
        JsonContract::assertShape('error', $document);
        $this->assertSame($stage, $document['stage']);
        $this->assertSame($validation, \array_key_exists('validation', $document));
    }

    /**
     * @return iterable<string, array{pattern1: string, pattern2: string, options: array<string, string>, exitCode: int, stage: string, validation: bool}>
     */
    public static function provideFailures(): iterable
    {
        yield 'an unknown method' => ['pattern1' => '/a/', 'pattern2' => '/b/', 'options' => ['--method' => 'union'], 'exitCode' => 2, 'stage' => 'usage', 'validation' => false];
        yield 'an unknown minimizer' => ['pattern1' => '/a/', 'pattern2' => '/b/', 'options' => ['--minimizer' => 'nosuch'], 'exitCode' => 2, 'stage' => 'usage', 'validation' => false];
        yield 'an unknown determinizer' => ['pattern1' => '/a/', 'pattern2' => '/b/', 'options' => ['--determinizer' => 'nosuch'], 'exitCode' => 2, 'stage' => 'usage', 'validation' => false];
        yield 'an invalid first pattern' => ['pattern1' => '/(a/', 'pattern2' => '/b/', 'options' => [], 'exitCode' => 1, 'stage' => 'pattern', 'validation' => true];
        yield 'an invalid second pattern' => ['pattern1' => '/a/', 'pattern2' => '/(?<=a+)b/', 'options' => [], 'exitCode' => 1, 'stage' => 'pattern', 'validation' => true];
        // A backreference carries match state no automaton reads.
        yield 'a construct the solver cannot read' => ['pattern1' => '/(a)\1/', 'pattern2' => '/aa/', 'options' => ['--method' => 'equivalence'], 'exitCode' => 1, 'stage' => 'pattern', 'validation' => false];
    }

    /**
     * The console says the same failures in text: the error line, and for an
     * invalid pattern which one, with its message.
     *
     * @param array<string, string> $options
     */
    #[Test]
    #[DataProvider('provideConsoleFailures')]
    public function test_a_failure_in_the_console_is_text(string $pattern1, string $pattern2, array $options, int $exitCode, string $expected): void
    {
        $status = Artisan::call('regex:compare', ['pattern1' => $pattern1, 'pattern2' => $pattern2] + $options);
        $output = Artisan::output();

        $this->assertSame($exitCode, $status, $output);
        $this->assertStringContainsString($expected, $output);
        $this->assertNull(json_decode($output), 'Text, not JSON.');
    }

    /**
     * @return iterable<string, array{pattern1: string, pattern2: string, options: array<string, string>, exitCode: int, expected: string}>
     */
    public static function provideConsoleFailures(): iterable
    {
        yield 'an unknown minimizer' => ['pattern1' => '/a/', 'pattern2' => '/b/', 'options' => ['--minimizer' => 'nosuch'], 'exitCode' => 2, 'expected' => "Invalid minimizer 'nosuch'"];
        yield 'an invalid second pattern' => ['pattern1' => '/a/', 'pattern2' => '/(a/', 'options' => [], 'exitCode' => 1, 'expected' => 'Invalid second pattern:'];
        yield 'a construct the solver cannot read' => ['pattern1' => '/(a)\1/', 'pattern2' => '/aa/', 'options' => [], 'exitCode' => 1, 'expected' => 'Comparison failed: Backreferences'];
    }

    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
