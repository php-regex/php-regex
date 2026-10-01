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

namespace PhpRegex\Tests\Integration;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class DeepDiveBugFixTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_quote_mode_in_char_class(): void
    {
        // [\Q-\E] should be parsed as a character class containing a literal hyphen
        $ast = $this->regexService->parse('/[\Q-\E]/');
        $charClass = $ast->pattern;

        $this->assertInstanceOf(CharClassNode::class, $charClass);
        $this->assertInstanceOf(LiteralNode::class, $charClass->expression);
        $this->assertSame('-', $charClass->expression->value);
    }

    public function test_compiler_escapes_multi_char_literals(): void
    {
        // \Q[a-z]\E should be parsed as a literal string "[a-z]"
        // And compiled back to "\[a-z\]" (or similar escaping)
        $pattern = '/\Q[a-z]\E/';
        $ast = $this->regexService->parse($pattern);

        $compiler = new PatternPrinter();
        $compiled = $ast->accept($compiler);

        // The compiled regex should match the literal string "[a-z]"
        // So it must be escaped.
        $this->assertStringContainsString('\[', $compiled);
        $this->assertStringContainsString('\]', $compiled);

        // Verify round-trip parse
        $ast2 = $this->regexService->parse($compiled);
        // The AST might be a Sequence of Literals or a single Literal depending on optimization.
        // Let's verify it compiles back to a valid regex that matches the literal string "[a-z]".
        $compiled2 = $ast2->accept(new PatternPrinter());

        // We expect the compiled regex to match the literal string "[a-z]"
        // The compiled regex should be something like /\[a-z\]/ or /\[a-z]/
        // It already includes delimiters because we visited the RegexNode
        $this->assertMatchesRegularExpression($compiled2, '[a-z]', "Compiled regex '$compiled2' should match literal '[a-z]'");
    }

    public function test_trailing_backslash_check(): void
    {
        // /\\/ is valid (matches literal backslash)
        $ast = $this->regexService->parse('/\\\\/');
        $this->assertInstanceOf(LiteralNode::class, $ast->pattern);
        $this->assertSame('\\', $ast->pattern->value);

        // /abc\/ is invalid (trailing backslash)
        // Note: In PHP string, '/abc\\/' is /abc\/.
        // The parser will fail to find the closing delimiter because it's escaped.
        $this->expectException(ParserException::class);
        $this->regexService->parse('/abc\\/');
    }
}
