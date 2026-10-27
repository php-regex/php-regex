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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under x the rewrite keeps the pattern as its author laid it out: only the
 * parts it changes are written again, in place, and the newlines, indents
 * and comments around them stay.
 */
final class ExtendedLayoutTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, expected: string, subject: string}>
     */
    public static function provideLaidOutPatterns(): iterable
    {
        yield 'indents and comments' => [
            'pattern' => "/\n\t\tfoo{1}\t# one\n\t\t(?:a|b)  # two\n/x",
            'expected' => "/\n\t\tfoo\t# one\n\t\t[ab]  # two\n/x",
            'subject' => 'foob',
        ];

        yield 'whitespace before the delimiter' => [
            'pattern' => "\n /a{1} # c\n/x",
            'expected' => "\n /a # c\n/x",
            'subject' => 'a',
        ];

        yield 'whitespace after the modifiers' => [
            'pattern' => "/a{1} # c\n/x\n",
            'expected' => "/a # c\n/x\n",
            'subject' => 'a',
        ];

        yield 'a useless flag dropped' => [
            'pattern' => "/[0-9]  # digit\n/xs",
            'expected' => "/\\d  # digit\n/x",
            'subject' => '7',
        ];
    }

    #[Test]
    #[DataProvider('provideLaidOutPatterns')]
    public function test_optimize_keeps_the_layout_under_x(string $pattern, string $expected, string $subject): void
    {
        // Oracle: both compile and agree on the subject.
        $this->assertSame(1, preg_match($pattern, $subject, $before));
        $this->assertSame(1, preg_match($expected, $subject, $after));
        $this->assertSame($before, $after);

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern);

        $this->assertSame($pattern, $result->original);
        $this->assertSame($expected, $result->optimized);
    }

    #[Test]
    public function test_optimize_factorizes_in_place_under_x(): void
    {
        $pattern = "/x\n\t(?:abc|abd)  # c\n/x";
        $expected = "/x\n\t(?:ab(?:c|d))  # c\n/x";
        $this->assertSame(1, preg_match($expected, 'xabd'));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(factorize: true));

        $this->assertSame($expected, $result->optimized);
    }

    #[Test]
    public function test_optimize_without_the_text_of_the_tree_compiles_it_again(): void
    {
        // A cache that gives the tree back without the text it was read
        // from: there is no layout to keep, and the tree is compiled again.
        $parsed = RegexParser::create(['cache' => null])->parse("/a{1}  # c\n/x");
        $cache = new class(new RegexNode($parsed->pattern, $parsed->flags, $parsed->delimiter, $parsed->startPosition, $parsed->endPosition)) implements CacheInterface {
            public function __construct(private readonly RegexNode $ast) {}

            public function generateKey(string $seed): string
            {
                return 'key';
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): RegexNode
            {
                return $this->ast;
            }
        };

        $result = (new Optimizer(RegexParser::create(['cache' => $cache])))->optimize("/a{1}  # c\n/x");

        $this->assertSame("/\n    a  # c\n/x", $result->optimized);
    }

    #[Test]
    public function test_optimize_leaves_a_laid_out_pattern_it_cannot_shorten_as_written(): void
    {
        $pattern = "/\n\t\\d+\t# digits\n/x";

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: false));

        $this->assertSame($pattern, $result->optimized);
        $this->assertFalse($result->isChanged());
    }
}
