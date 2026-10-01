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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Tests\TestUtils\PhpErrorOffset;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A quantifier after "\E" repeats the last character of the quoted run, a
 * code point in UTF-8 mode and a byte otherwise; a lone "\E" or an empty
 * "\Q\E" is skipped. Every verdict and offset below is the one preg_match()
 * gives on PCRE2 10.48.
 */
final class QuantifiedQuotedRunTest extends TestCase
{
    #[Test]
    #[DataProvider('provideQuotedRuns')]
    public function test_only_the_last_quoted_character_is_repeated(string $pattern, string $prefix, string $repeated, int $splitAt): void
    {
        $sequence = Regex::create()->parse($pattern)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);

        [$before, $quantified] = \array_slice($sequence->children, -2);

        $this->assertInstanceOf(LiteralNode::class, $before);
        $this->assertSame($prefix, $before->value);
        $this->assertSame($splitAt, $before->getEndPosition());

        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertInstanceOf(LiteralNode::class, $quantified->node);
        $this->assertSame($repeated, $quantified->node->value);
        $this->assertSame($splitAt, $quantified->node->getStartPosition());
    }

    #[Test]
    public function test_a_modifier_past_a_quote_end_makes_the_quantifier_possessive(): void
    {
        $quantified = Regex::create()->parse('/a*\\E+/')->pattern;

        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertSame('*', $quantified->quantifier);
        $this->assertSame(QuantifierType::T_POSSESSIVE, $quantified->type);
    }

    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_at_the_offset_php_reports(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s must be refused by PHP.', $pattern));

        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
        // The offset is PHP's on PCRE2 10.47 and later; the running PHP
        // decides, as the library follows the PCRE2 it links.
        $this->assertSame(PhpErrorOffset::of($pattern), $result->offset, $pattern);
        if (PhpErrorOffset::runsPcre1047()) {
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, prefix: string, repeated: string, splitAt: int}>
     */
    public static function provideQuotedRuns(): iterable
    {
        yield 'two letters' => ['pattern' => '/\\Qab\\E*/', 'prefix' => 'a', 'repeated' => 'b', 'splitAt' => 3];
        yield 'metacharacters' => ['pattern' => '/\\Q(a)\\E?/', 'prefix' => '(a', 'repeated' => ')', 'splitAt' => 4];
        yield 'run after an empty quote' => ['pattern' => '/\\Q\\E\\Qab\\E*/', 'prefix' => 'a', 'repeated' => 'b', 'splitAt' => 7];
        yield 'code point under u' => ['pattern' => '/\\Qaé\\E+/u', 'prefix' => 'a', 'repeated' => 'é', 'splitAt' => 3];
        yield 'code point under (*UTF)' => ['pattern' => '/(*UTF)\\Qaé\\E+/', 'prefix' => 'a', 'repeated' => 'é', 'splitAt' => 9];
        yield 'byte without u' => ['pattern' => '/\\Qaé\\E+/', 'prefix' => "a\xC3", 'repeated' => "\xA9", 'splitAt' => 4];
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        yield '/a*\\E*/' => ['pattern' => '/a*\\E*/', 'offset' => 5];
        yield '/a{1,3}\\E{2}/' => ['pattern' => '/a{1,3}\\E{2}/', 'offset' => 11];
        yield '/a*\\Q\\E*/' => ['pattern' => '/a*\\Q\\E*/', 'offset' => 7];
        yield '/a*\\E+\\E+/' => ['pattern' => '/a*\\E+\\E+/', 'offset' => 8];
        yield '/(?#c)\\E??/' => ['pattern' => '/(?#c)\\E??/', 'offset' => 8];
        yield '/\\E*/' => ['pattern' => '/\\E*/', 'offset' => 3];
        yield '/\\Q\\E*/' => ['pattern' => '/\\Q\\E*/', 'offset' => 5];
        yield '/^\\E*/' => ['pattern' => '/^\\E*/', 'offset' => 4];
        yield '/(?i)\\E*/' => ['pattern' => '/(?i)\\E*/', 'offset' => 7];
        yield '/\\b\\Q\\E+/' => ['pattern' => '/\\b\\Q\\E+/', 'offset' => 7];
        yield '/\\Qab\\E**/' => ['pattern' => '/\\Qab\\E**/', 'offset' => 8];
    }
}
