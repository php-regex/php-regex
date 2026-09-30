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
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RegexParser\Bridge\Laravel\RegexParserServiceProvider;

/**
 * The Artisan commands exit with 2 when the command line or the
 * configuration cannot be used, and keep 1 for the patterns they judged.
 */
#[WithConfig('regex-parser.cache.directory', null)]
#[WithConfig('regex-parser.cache.store', null)]
final class UsageErrorExitCodeTest extends TestCase
{
    /**
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('provideUsageErrors')]
    public function test_a_usage_error_exits_2(string $command, array $parameters): void
    {
        $this->assertSame(2, Artisan::call($command, $parameters));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideUsageErrors(): iterable
    {
        yield 'regex:explain with an unknown --format' => ['regex:explain', ['pattern' => '/a/', '--format' => 'xml']];
        yield 'regex:transpile with an unknown --target' => ['regex:transpile', ['pattern' => '/a/', '--target' => 'perl']];
        yield 'regex:compare with an unknown --minimizer' => ['regex:compare', ['pattern1' => '/a/', 'pattern2' => '/b/', '--minimizer' => 'nosuch']];
        yield 'regex:compare with an unknown --determinizer, before judging the patterns' => ['regex:compare', ['pattern1' => '/(/', 'pattern2' => '/b/', '--determinizer' => 'nosuch']];
        yield 'regex:lint with an unknown --format' => ['regex:lint', ['paths' => ['.'], '--format' => 'xml']];
    }

    #[Test]
    #[DataProvider('provideFormats')]
    #[WithConfig('regex-parser.php_version', 'eight')]
    public function test_a_configuration_the_lint_cannot_use_exits_2(string $format): void
    {
        $this->assertSame(2, Artisan::call('regex:lint', ['paths' => ['.'], '--format' => $format, '--no-routes' => true, '--no-validators' => true, '--jobs' => '1']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFormats(): iterable
    {
        yield 'console' => ['console'];
        yield 'json' => ['json'];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[Test]
    #[DataProvider('providePatternProblems')]
    public function test_a_pattern_with_a_problem_exits_1(string $command, array $parameters): void
    {
        $this->assertSame(1, Artisan::call($command, $parameters));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function providePatternProblems(): iterable
    {
        yield 'regex:explain on an invalid pattern' => ['regex:explain', ['pattern' => '/(/']];
        yield 'regex:transpile on an invalid pattern' => ['regex:transpile', ['pattern' => '/(/']];
        yield 'regex:compare on patterns that differ' => ['regex:compare', ['pattern1' => '/a/', 'pattern2' => '/b/']];
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RegexParserServiceProvider::class];
    }
}
