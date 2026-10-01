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

use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

class FuzzTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    /**
     * Property-based fuzzing test for AST round-trip.
     */
    public function test_ast_round_trip(): void
    {
        $patterns = [
            '/a/',
            '/(a|b)/',
            '/[a-z]/',
            '/\d+/',
            '/(?P<name>foo)/',
            '/(?:bar)/',
            '/a{1,3}/',
            '/(?=lookahead)/',
            '/(?<=lookbehind)/',
        ];

        foreach ($patterns as $pattern) {
            // Parse to AST
            $ast = $this->regex->parse($pattern);

            // Compile back to string
            $compiler = new PatternPrinter();
            $recompiled = $ast->accept($compiler);

            // Parse the recompiled
            // The compiled pattern carries its own delimiters and flags.
            $ast2 = $this->regex->parse($recompiled);

            // For now, just ensure no exceptions
            $this->assertInstanceOf(RegexNode::class, $ast);
            $this->assertInstanceOf(RegexNode::class, $ast2);
        }
    }
}
