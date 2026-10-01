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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class PcreFeatureCompletenessTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_atomic_groups(): void
    {
        $patterns = [
            '/(?>foo)bar/',
            '/(?>a+)b/',
            '/(?>[a-z]+)\d/',
            '/(?>test|testing)s/',
            '/(?>(?>a)b)c/',
            '/(?>abc|ab)c/',
            '/a(?>bc|b)c/',
            '/(?>x+)x/',
            '/(?>a{2,5})a/',
            '/(?>(?:foo|bar))baz/',
            '/(?>(a|b))c/',
            '/(?>test(?:ing)?)s/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $hasAtomic = $this->hasGroupType($ast, GroupType::T_GROUP_ATOMIC);
                $this->assertTrue($hasAtomic, "Pattern should contain atomic group: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Atomic group pattern should parse: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_possessive_quantifiers(): void
    {
        $patterns = [
            '/a++/',
            '/a*+/',
            '/a?+/',
            '/a{2,5}+/',
            '/[a-z]++/',
            '/\d*+/',
            '/\w?+/',
            '/(foo|bar)++/',
            '/[^abc]*+/',
            '/\s{1,3}+/',
            '/.++/',
            '/(?:test)?+/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $hasPossessive = $this->hasQuantifierType($ast, QuantifierType::T_POSSESSIVE);
                $this->assertTrue($hasPossessive, "Pattern should contain possessive quantifier: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Possessive quantifier pattern should parse: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_conditional_patterns(): void
    {
        $patterns = [
            '/(a)(?(1)b|c)/',
            '/(test)?(?(1)yes)/',
            '/(?<name>a)(?(name)b|c)/',
            '/(a)b(?(1)c)/',
            '/(a)(b)?(?(2)c|d)/',
            '/(?(?=test)a|b)/',
            '/(?(?!test)a|b)/',
            '/(?(?<=a)b|c)/',
            '/(?(?<!a)b|c)/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $this->assertSame('/', $ast->delimiter, "Conditional pattern should parse: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Conditional pattern parsing failed: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_conditional_patterns_advanced_features(): void
    {
        $advancedPatterns = [
            '/(?(1)yes|no)/',
            '/(a)(?(DEFINE)(?<foo>bar))(?(1)\k<foo>)/',
        ];

        foreach ($advancedPatterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $this->assertSame('/', $ast->delimiter, "Advanced conditional pattern should parse: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Advanced conditional pattern failed: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_named_groups(): void
    {
        $patterns = [
            '/(?<word>\w+)/',
            '/(?<year>\d{4})/',
            '/(?P<name>[a-z]+)/',
            '/(?P<test>foo|bar)/',
            '/(?<first>a)(?<second>b)/',
            '/(?P<group1>\d+)(?P<group2>\w+)/',
            '/(?<outer>(?<inner>test))/',
            '/(?<name>[a-z]+)\k<name>/',
            '/(?P<x>a)(?P<y>b)\k<x>\k<y>/',
            '/(?<digits>\d+)-(?<letters>[a-z]+)/',
            '/(?<tag><(?<name>\w+)>)/',
            '/(?<test>(?:foo|bar))/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $hasNamed = $this->hasGroupType($ast, GroupType::T_GROUP_NAMED);
                $this->assertTrue($hasNamed, "Pattern should contain named group: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Named group pattern should parse: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_unicode_properties(): void
    {
        $patterns = [
            '/\p{L}+/',
            '/\p{N}/',
            '/\p{Lu}/',
            '/\p{Ll}/',
            '/\P{L}/',
            '/\p{Greek}/',
            '/\p{Latin}/',
            '/\p{Nd}+/',
            '/\p{Zs}/',
            '/\p{Sc}\d+/',
            '/[\p{L}\p{N}]+/',
            '/\p{Arabic}+/u',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $this->assertSame('/', $ast->delimiter, "Unicode property pattern should parse: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Unicode property pattern should parse: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_subroutines_and_recursion(): void
    {
        $patterns = [
            '/(?R)/',
            '/(a(?R)?b)/',
            '/(test)(?1)/',
            '/(?<group>test)(?&group)/',
            '/\((?:[^()]++|(?R))*\)/',
            '/(a)(?1)(?1)/',
            '/(?<digit>\d)(?&digit)/',
            '/(?<x>a|(?&x)b)/',
            '/(foo|(?R))/',
            '/(?<name>[a-z]+)(?&name)/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $this->assertSame('/', $ast->delimiter, "Subroutine pattern should parse: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Subroutine pattern parsing failed: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_comments(): void
    {
        $patterns = [
            '/test(?#this is a comment)/',
            '/(?#comment at start)foo/',
            '/a(?#middle comment)b/',
            '/(?#first)a(?#second)b(?#third)/',
            '/[a-z](?#character class followed by comment)/',
            '/\d+(?#digits)/',
            '/(?#comment)\w+/',
            '/test(?#)end/',
            '/(?#special chars: @#$%^&*)pattern/',
            '/a(?#first)b(?#second)c/',
            '/(?#unicode: \u{1F600})test/',
            '/pattern(?#important note)more/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $this->assertSame('/', $ast->delimiter, "Comment pattern should parse: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Comment pattern should parse: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_assertions(): void
    {
        $patterns = [
            '/(?=test)/',
            '/(?!test)/',
            '/(?<=foo)/',
            '/(?<!bar)/',
            '/\w+(?=\d)/',
            '/(?!abc)\w+/',
            '/(?<=start)test/',
            '/(?<!end)test/',
            '/(?=a)(?=b)/',
            '/(?!x)(?!y)/',
            '/test(?=ing|ed)/',
            '/(?<=foo|bar)test/',
            '/(?<!do|re)mi/',
            '/\w+(?!\d)/',
            '/(?<=\d{3})test/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $hasLookaround = $this->hasGroupType($ast, GroupType::T_GROUP_LOOKAHEAD_POSITIVE)
                    || $this->hasGroupType($ast, GroupType::T_GROUP_LOOKAHEAD_NEGATIVE)
                    || $this->hasGroupType($ast, GroupType::T_GROUP_LOOKBEHIND_POSITIVE)
                    || $this->hasGroupType($ast, GroupType::T_GROUP_LOOKBEHIND_NEGATIVE);

                $this->assertTrue($hasLookaround, "Pattern should contain assertion: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Assertion pattern should parse: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_extended_mode(): void
    {
        $patterns = [
            '/a b c/x',
            "/test  # comment\ning/x",
            "/\n  \w+  # word\n  \d+  # digit\n/x",
            '/a   b   c/x',
            "/(\n  foo  # first\n  |\n  bar  # second\n)/x",
            '/[ ] /x',
            '/\  /x',
            "/test\n\n\npattern/x",
            '/(?x: a b c )/',
            "/# start\ntest\n# end/x",
            "/\d+  # digits\n-\n\w+  # word/x",
            '/a#comment b/x',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $this->assertTrue(str_contains($pattern, '/x') || str_contains($pattern, '(?x:'), "Extended mode pattern should parse: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("Extended mode pattern parsing failed: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    public function test_pcre_verbs(): void
    {
        $patterns = [
            '/(*FAIL)/',
            '/(*ACCEPT)/',
            '/(*COMMIT)/',
            '/test(*SKIP)/',
            '/foo(*PRUNE)bar/',
            '/(*THEN)/',
            '/a(*MARK:label)b/',
            '/(*UTF8)pattern/',
            '/(*UCP)test/',
            '/(*CR)/',
            '/(*LF)/',
            '/(*CRLF)/',
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $this->assertSame('/', $ast->delimiter, "PCRE verb pattern should parse: {$pattern}");
            } catch (ParserException $e) {
                $this->fail("PCRE verb pattern should parse: {$pattern}. Error: {$e->getMessage()}");
            }
        }
    }

    private function hasGroupType(object $node, GroupType $type): bool
    {
        if ($node instanceof GroupNode && $node->type === $type) {
            return true;
        }

        $properties = get_object_vars($node);
        foreach ($properties as $prop) {
            if ($prop instanceof NodeInterface) {
                if ($this->hasGroupType($prop, $type)) {
                    return true;
                }
            } elseif (\is_array($prop)) {
                foreach ($prop as $item) {
                    if ($item instanceof NodeInterface) {
                        if ($this->hasGroupType($item, $type)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function hasQuantifierType(object $node, QuantifierType $type): bool
    {
        if ($node instanceof QuantifierNode && $node->type === $type) {
            return true;
        }

        $properties = get_object_vars($node);
        foreach ($properties as $prop) {
            if ($prop instanceof NodeInterface) {
                if ($this->hasQuantifierType($prop, $type)) {
                    return true;
                }
            } elseif (\is_array($prop)) {
                foreach ($prop as $item) {
                    if ($item instanceof NodeInterface) {
                        if ($this->hasQuantifierType($item, $type)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
