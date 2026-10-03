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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without /u, PCRE reads a pattern byte by byte: a quantifier after "é"
 * repeats its last byte, not the character. preg_match('/^é+$/', "é\xA9")
 * matches and preg_match('/^é+$/', 'éé') does not.
 */
final class QuantifiedMultibyteLiteralTest extends TestCase
{
    #[Test]
    public function test_the_engine_repeats_the_last_byte_without_u(): void
    {
        $this->assertSame(1, preg_match('/^é+$/', "é\xA9"));
        $this->assertSame(0, preg_match('/^é+$/', 'éé'));
        $this->assertSame(1, preg_match('/^é+$/u', 'éé'));
    }

    #[Test]
    #[DataProvider('provideByteQuantifiers')]
    public function test_only_the_last_byte_is_repeated_without_u(string $pattern, string $prefix, string $repeated, int $splitAt): void
    {
        $sequence = Regex::create()->parse($pattern)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);

        $children = array_values(array_filter($sequence->children, static fn ($child): bool => $child instanceof LiteralNode || $child instanceof QuantifierNode));
        [$before, $quantified] = \array_slice($children, -2);

        $this->assertInstanceOf(LiteralNode::class, $before);
        $this->assertSame($prefix, $before->value);
        $this->assertSame($splitAt, $before->getEndPosition());

        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertInstanceOf(LiteralNode::class, $quantified->node);
        $this->assertSame($repeated, $quantified->node->value);
        $this->assertSame($splitAt, $quantified->node->getStartPosition());
    }

    #[Test]
    #[DataProvider('provideCodePointQuantifiers')]
    public function test_the_whole_code_point_is_repeated_in_utf8_mode(string $pattern): void
    {
        $quantified = Regex::create()->parse($pattern)->pattern;
        if ($quantified instanceof SequenceNode) {
            $quantified = $quantified->children[\count($quantified->children) - 1];
        }

        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertInstanceOf(LiteralNode::class, $quantified->node);
        $this->assertSame('é', $quantified->node->value);
    }

    #[Test]
    #[DataProvider('provideByteEquivalents')]
    public function test_the_solver_sees_the_language_the_engine_matches(string $pattern, string $bytes, string $member, string $outsider): void
    {
        $this->assertSame(1, preg_match($pattern, $member), $pattern);
        $this->assertSame(0, preg_match($pattern, $outsider), $pattern);
        $this->assertSame(1, preg_match($bytes, $member), $bytes);

        $this->assertTrue((new LanguageSolver())->equivalent($pattern, $bytes)->isEquivalent, \sprintf('%s and %s match the same strings.', $pattern, $bytes));
    }

    /**
     * @return iterable<string, array{pattern: string, prefix: string, repeated: string, splitAt: int}>
     */
    public static function provideByteQuantifiers(): iterable
    {
        yield 'two bytes, plus' => ['pattern' => '/é+/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'two bytes, braces' => ['pattern' => '/é{2}/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'two bytes, lazy' => ['pattern' => '/é??/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'three bytes' => ['pattern' => '/€*/', 'prefix' => "\xE2\x82", 'repeated' => "\xAC", 'splitAt' => 2];
        yield 'after a comment' => ['pattern' => '/é(?#c)+/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'after extended whitespace' => ['pattern' => '/é +/x', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCodePointQuantifiers(): iterable
    {
        yield 'u flag' => ['pattern' => '/é+/u'];
        yield '(*UTF)' => ['pattern' => '/(*UTF)é+/'];
    }

    /**
     * @return iterable<string, array{pattern: string, bytes: string, member: string, outsider: string}>
     */
    public static function provideByteEquivalents(): iterable
    {
        yield 'plus' => ['pattern' => '/^é+$/', 'bytes' => '/^\xC3\xA9+$/', 'member' => "é\xA9", 'outsider' => 'éé'];
        yield 'braces' => ['pattern' => '/^é{2}$/', 'bytes' => '/^\xC3\xA9{2}$/', 'member' => "é\xA9", 'outsider' => 'éé'];
        yield 'optional' => ['pattern' => '/^é?$/', 'bytes' => '/^\xC3\xA9?$/', 'member' => "\xC3", 'outsider' => ''];
    }
}
