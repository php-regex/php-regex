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
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An Artisan command refusing a pattern prints the message and, under it,
 * the caret snippet that points at the fault.
 */
final class InvalidPatternOutputTest extends TestCase
{
    /**
     * @param array<string, string> $options
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_command_prints_the_message_and_the_caret_snippet(string $command, array $options): void
    {
        $exitCode = Artisan::call($command, ['pattern' => '/(?<=a+)b/'] + $options);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("Lookbehind is unbounded. PCRE requires a bounded maximum length.\nLine 1: (?<=a+)b\n", $output);
        $this->assertMatchesRegularExpression('/^ {8}\^$/m', $output);
    }

    /**
     * @return iterable<string, array{command: string, options: array<string, string>}>
     */
    public static function provideCommands(): iterable
    {
        yield 'explain' => ['command' => 'regex:explain', 'options' => []];
        yield 'transpile' => ['command' => 'regex:transpile', 'options' => []];
    }

    /**
     * A pattern past the library's length limit, which PCRE compiles, is
     * not called invalid: the command says it was not analyzed, and why.
     *
     * @param array<string, string> $options
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_command_says_a_pattern_past_a_limit_was_not_analyzed(string $command, array $options): void
    {
        $pattern = '/a(?#'.str_repeat('x', 100_000).')/';
        $this->assertSame(1, preg_match($pattern, 'a'), 'control: PCRE compiles the pattern');

        $exitCode = Artisan::call($command, ['pattern' => $pattern] + $options);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Pattern not analyzed:', $output);
        $this->assertStringNotContainsString('Invalid', $output);
        $this->assertStringContainsString('raise the "max_pattern_length" option', $output);
    }

    #[Test]
    public function test_transpile_json_carries_the_snippet_apart(): void
    {
        Artisan::call('regex:transpile', ['pattern' => '/(?<=a+)b/', '--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        // The error envelope, its validation carrying the message and the
        // snippet as the text itself.
        $this->assertIsArray($payload);
        $this->assertSame('pattern', $payload['stage'] ?? null);
        $validation = $payload['validation'] ?? null;
        $this->assertIsArray($validation);
        $this->assertSame('Lookbehind is unbounded. PCRE requires a bounded maximum length.', $validation['error'] ?? null);
        // A string is written as itself: the snippet keeps its newline.
        $this->assertSame("Line 1: (?<=a+)b\n        ^", $validation['caret_snippet'] ?? null);
    }

    protected function getPackageProviders($app): array
    {
        return [
            PHPRegexServiceProvider::class,
        ];
    }
}
