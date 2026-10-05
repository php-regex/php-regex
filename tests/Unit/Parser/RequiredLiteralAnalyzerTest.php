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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\Analysis\RequiredLiteralAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The strings every match holds: a subject without one of them cannot
 * match, so str_contains() may turn it away before preg_match() runs.
 */
final class RequiredLiteralAnalyzerTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_strings_every_match_holds(string $pattern, array $expected): void
    {
        $this->assertSame($expected, (new RequiredLiteralAnalyzer())->analyze(RegexParser::create()->parse($pattern)));
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_every_match_the_engine_finds_holds_them(string $pattern, array $expected): void
    {
        $ast = RegexParser::create()->parse($pattern);
        $generator = new SampleGenerator();

        for ($seed = 0; $seed < 20; $seed++) {
            $generator->setSeed($seed);
            $subject = $ast->accept($generator);
            $this->assertSame(1, preg_match($pattern, $subject, $matches), \sprintf('%s must match %s.', $pattern, json_encode($subject)));

            foreach ($expected as $literal) {
                $this->assertStringContainsString($literal, $matches[0], \sprintf('%s matched %s.', $pattern, json_encode($matches[0])));
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<string>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'literal' => ['pattern' => '/foo/', 'expected' => ['foo']];
        yield 'two literals around a class' => ['pattern' => '/foo\d+bar/', 'expected' => ['foo', 'bar']];
        yield 'shared start of branches' => ['pattern' => '/foobar|foobaz/', 'expected' => ['fooba']];
        yield 'no shared text' => ['pattern' => '/ab|cd/', 'expected' => []];
        yield 'optional letter' => ['pattern' => '/a?b/', 'expected' => ['b']];
        yield 'repeat followed by a literal' => ['pattern' => '/(?:abc)+x/', 'expected' => ['abcx']];
        yield 'literal followed by a repeat' => ['pattern' => '/x(?:abc)+/', 'expected' => ['xabc']];
        yield 'class between literals' => ['pattern' => '/x[ab]y/', 'expected' => ['x', 'y']];
        yield 'caseless' => ['pattern' => '/abc/i', 'expected' => []];
        yield 'lookahead' => ['pattern' => '/(?=b)bar/', 'expected' => ['bar']];
        yield 'anchors' => ['pattern' => '/^https?:\/\/(\w+)\.com$/', 'expected' => ['http', '://', '.com']];
        yield 'capture' => ['pattern' => '/(foo)(\d)(bar)/', 'expected' => ['foo', 'bar']];
        yield 'optional group' => ['pattern' => '/a(?:bc)?d/', 'expected' => ['a', 'd']];
        yield 'nothing required' => ['pattern' => '/\w*/', 'expected' => []];
        yield 'utf-8' => ['pattern' => '/é+ü/u', 'expected' => ['éü']];
        yield 'control escape between literals' => ['pattern' => '/a\\cAb/', 'expected' => ["a\x01b"]];
        yield 'callout between literals' => ['pattern' => '/ab(?C1)cd/', 'expected' => ['abcd']];
        yield 'script run' => ['pattern' => '/(*sr:abc)/', 'expected' => ['abc']];
    }
}
