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

namespace RegexParser\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RegexParser\Bridge\Laravel\RegexParserServiceProvider;

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

    #[Test]
    public function test_transpile_json_carries_the_snippet_apart(): void
    {
        Artisan::call('regex:transpile', ['pattern' => '/(?<=a+)b/', '--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame('Lookbehind is unbounded. PCRE requires a bounded maximum length.', $payload['details'] ?? null);
        $this->assertSame("Line 1: (?<=a+)b\n        ^", $payload['snippet'] ?? null);
    }

    protected function getPackageProviders($app): array
    {
        return [
            RegexParserServiceProvider::class,
        ];
    }
}
