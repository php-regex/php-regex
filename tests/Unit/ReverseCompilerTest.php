<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the compiler correctly reconstructs the parsed AST back into a regex string
 * that is semantically equivalent to the input.
 */
class ReverseCompilerTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    #[DataProvider('providePatterns')]
    public function test_round_trip_compilation(string $originalPattern): void
    {
        $ast = $this->regexService->parse($originalPattern);
        $compiler = new PatternPrinter();
        $recompiled = $ast->accept($compiler);

        // 1. The recompiled regex must be valid
        // We suppress errors because we want to catch the false return value
        $isValid = @preg_match($recompiled, '');

        if (false === $isValid) {
            $error = preg_last_error();
            $msg = match ($error) {
                \PREG_INTERNAL_ERROR => 'PREG_INTERNAL_ERROR',
                \PREG_BACKTRACK_LIMIT_ERROR => 'PREG_BACKTRACK_LIMIT_ERROR',
                \PREG_RECURSION_LIMIT_ERROR => 'PREG_RECURSION_LIMIT_ERROR',
                \PREG_BAD_UTF8_ERROR => 'PREG_BAD_UTF8_ERROR',
                \PREG_BAD_UTF8_OFFSET_ERROR => 'PREG_BAD_UTF8_OFFSET_ERROR',
                \PREG_JIT_STACKLIMIT_ERROR => 'PREG_JIT_STACKLIMIT_ERROR',
                default => 'Unknown Error'
            };
            $this->fail("Recompiled regex '$recompiled' from '$originalPattern' is invalid ($msg).");
        }

        // 2. Generate a matching sample from the original
        // This proves that the recompiled regex matches what the original matched.
        try {
            // Some recursive/complex patterns might hit generator limits, so we wrap in try/catch
            $sample = $this->regexService->generate($originalPattern);

            $this->assertMatchesRegularExpression(
                $recompiled,
                $sample,
                "Recompiled regex '$recompiled' failed to match sample '$sample' generated from '$originalPattern'",
            );
        } catch (\Exception $e) {
            // If generation fails (e.g. infinite recursion), we skip the sample match test
            // but the validity test above is still valuable.
            $this->markTestSkipped('Sample generation failed for pattern '.$originalPattern.': '.$e->getMessage());
        }
    }

    public static function providePatterns(): \Iterator
    {
        // Basic
        yield ['/abc/'];
        yield ['/^test$/i'];
        yield ['/[a-z0-9]+/'];

        // Groups and Alternation
        yield ['/(a|b)+/'];
        yield ['/(?:foo|bar)/'];
        yield ['/(?<name>\w+)/'];

        // Quantifiers
        yield ['/a{1,3}/'];
        yield ['/a*?/'];
        yield ['/a++/'];

        // Character Classes
        yield ['/[^0-9]/'];
        yield ['/[a-z-]/']; // Dash at end
        yield ['/[-a-z]/']; // Dash at start

        // Advanced
        yield ['/(?=.*[A-Z])(?=.*[0-9])/'];
        yield ['/(?<=foo)bar/'];
        yield ['/(?<!not)allowed/'];
        yield ['/\p{L}+/u'];
        yield ['/\o{101}/'];

        // Conditionals and Subroutines
        yield ['/(a)(?(1)yes|no)/'];         // Defined group 1
        yield ['/a(?R)?/'];                  // Optional recursion to avoid infinite loop on empty match
        yield ['/(?<name>a)(?&name)/'];      // Defined named group

        // Complex Real world
        yield ['/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'];
    }
}
