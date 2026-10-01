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

namespace PhpRegex\Tests\Unit\Api;

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoundTripTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    /**
     * @return \Iterator<(int|string), array{0: string, 1?: string}>
     */
    public static function providePatterns(): \Iterator
    {
        yield ['/abc/'];
        yield ['/^test$/i'];
        // The compiler escapes the hyphen for safety
        yield ['/[a-z0-9_-]+/', '/[a-z0-9_-]+/'];
        yield ['/(?:foo|bar){1,2}?/s'];
        yield ['/(?<name>\w+)/'];
        yield ['/\\/home\\/user/'];
        yield ['#Hash matches#'];
        // The compiler preserves brace form for Unicode properties
        yield ['/\\p{L}+/u', '/\\p{L}+/u'];
        // Correction here: We define group 1 (a) so that the condition (?(1)...) is valid
        yield ['/(a)(?(1)b|c)/'];
    }

    #[DataProvider('providePatterns')]
    public function test_parse_and_compile_is_idempotent(string $pattern, ?string $expected = null): void
    {
        $compiler = new PatternPrinter();

        $ast = $this->regexService->parse($pattern);
        $compiled = $ast->accept($compiler);

        $this->assertSame($expected ?? $pattern, $compiled);

        // This assertion was failing because the regex didn't have group 1
        $this->assertNotFalse(@preg_match($compiled, ''), "Compiled regex '$compiled' is invalid");
    }
}
