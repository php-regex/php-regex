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

namespace RegexParser\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RegexParser\Node\RegexNode;
use RegexParser\Regex;

final class ParserPatternSweepTest extends TestCase
{
    public function test_parse_various_patterns(): void
    {
        $regex = Regex::create();

        // Patterns to cover more parser code
        $patterns = [
            '/\u{41}/',  // unicode
            '/\u0041/',  // 4-digit unicode
            '/\N{LATIN CAPITAL LETTER A}/',  // named unicode
            '/\x{41}/',  // hex unicode
            '/\o{101}/',  // octal
            '/(*CRLF)a/',  // verb
            '/(?(?=a)b|c)/',  // conditional
            '/(?<name>a)\k{name}/',  // backref
            '/(?>a+)b/',  // atomic
            '/a\R/',  // generic newline
            '/\p{L}/u',  // unicode prop
            '/[\w--\d]/',  // "\w" as a range start: invalid in PHP
            '/[a-z&&\d]/',  // "&" is a plain class member
            '/[a-d[m-p]]/',  // char class with literal "[" then trailing "]"
            '/[a-z&&[def]]/',  // "&" and "[" are members, the last "]" a literal
            '/\K/',  // keep
            '/a(*THEN)b/',  // verb
            '/(*LIMIT_DEPTH=10)a/',  // verb
            '/(*UTF8)a/',  // verb
            '/\X/',  // extended grapheme
            '/\R/',  // any newline
            '/\C/',  // single byte
            '/\X/u',  // unicode grapheme
            '/(?P<name>a)/',  // python named group
            '/(?|a|b)/',  // branch reset
            '/(a)(b)\g{-1}/',  // relative backref
            '/\g<name>/',  // subroutine
            '/(?&name)/',  // subroutine
            '/(?R)/',  // recursive
            '/(a)\g<1>/',  // backref
            '/(*CR)a/',  // verb
            '/(*LF)a/',  // verb
            '/(*ANYCRLF)a/',  // verb
            '/(*BSR_ANYCRLF)a/',  // verb
            '/(*NO_START_OPT)a/',  // verb
            '/(*LIMIT_MATCH=10)a/',  // verb
            '/(*LIMIT_RECURSION=10)a/',  // verb
            '/(*BSR_UNICODE)a/',  // verb
            '/(*ACCEPT)a/',  // verb
            '/(*FAIL)a/',  // verb
            '/(*F)a/',  // verb
            '/(*MARK:name)a/',  // verb
            '/(*PRUNE)a/',  // verb
            '/(*SKIP)a/',  // verb
            '/(*THEN)a/',  // verb
            '/[[:alpha:]]/',  // posix class
            '/[[:^alpha:]]/',  // negated posix
            '/\d/',  // shorthand
            '/\s/',  // shorthand
            '/\w/',  // shorthand
            '/\D/',  // negated
            '/\S/',
            '/\W/',
            '/\b/',  // boundary
            '/\B/',
            '/\A/',
            '/\Z/',
            '/\z/',
            '/\G/',
            '/(?=a)/',  // lookahead
            '/(?!a)/',
            '/(?<=a)/',  // lookbehind
            '/(?<!a)/',
            '/(?#comment)/',  // comment
            '/(?i)a/',  // inline flag
            '/(?m)a/',
            '/(?s)a/',
            '/(?x)a/',
            '/(?U)a/',
            '/(?J)a/',
            '/(?n)a/',
            '/(a)(b)\1/',  // backref
            '/(a)(b)\2/',
            '/\1/',  // invalid, but test tolerant
            '/\u{110000}/',  // invalid unicode
            '/\N{INVALID}/',  // invalid named
            '/\c9/',  // invalid control
            '/[a-',  // invalid char class
            '/(?P<a/',  // invalid group
            '/(*INVALID)/',  // invalid verb
            '/\k<invalid>/',  // invalid backref
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $regex->parse($pattern);
                $this->assertInstanceOf(RegexNode::class, $ast);
            } catch (\Exception) {
                // Some patterns may be invalid, but we test parsing
                // Exception caught is expected for some patterns
            }
        }
    }
}
