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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Nullability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether a node can match without consuming a character, read from the
 * tree alone: true, false, or null where a back reference or a call
 * decides at match time. The engine grounds every verdict given: on a
 * subject the pattern cannot consume, a nullable pattern matches the empty
 * string where any other fails. The extended class is read by the parser
 * alone: PHP's engine does not compile it.
 */
final class NullabilityTest extends TestCase
{
    #[Test]
    #[DataProvider('patterns')]
    public function test_a_node_is_nullable_when_it_can_match_without_consuming(string $pattern, string $subject, ?bool $expected, bool $engineReadsIt = true): void
    {
        if (null !== $expected && $engineReadsIt) {
            $this->assertSame($expected, 1 === preg_match($pattern, $subject, $matches) && '' === $matches[0], 'The engine on '.$pattern);
        }

        $this->assertSame($expected, RegexParser::create()->parse($pattern)->accept(new Nullability()));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, expected: ?bool, engineReadsIt?: bool}>
     */
    public static function patterns(): iterable
    {
        yield 'star' => ['pattern' => '/a*/', 'subject' => 'x', 'expected' => true];
        yield 'lazy star' => ['pattern' => '/a*?/', 'subject' => 'x', 'expected' => true];
        yield 'plus' => ['pattern' => '/a+/', 'subject' => 'x', 'expected' => false];
        yield 'zero repeat' => ['pattern' => '/a{0}/', 'subject' => 'x', 'expected' => true];
        yield 'two repeats' => ['pattern' => '/a{2}/', 'subject' => 'x', 'expected' => false];
        yield 'empty branch' => ['pattern' => '/a|/', 'subject' => 'x', 'expected' => true];
        yield 'two consuming branches' => ['pattern' => '/a|b/', 'subject' => 'x', 'expected' => false];
        yield 'empty pattern' => ['pattern' => '//', 'subject' => 'x', 'expected' => true];
        yield 'optional then literal' => ['pattern' => '/a?b/', 'subject' => 'x', 'expected' => false];
        yield 'two nullable groups' => ['pattern' => '/(a*)(b*)/', 'subject' => 'x', 'expected' => true];
        yield 'repeated nullable group' => ['pattern' => '/(?:a|)+/', 'subject' => 'x', 'expected' => true];
        yield 'word boundary' => ['pattern' => '/\b/', 'subject' => 'x', 'expected' => true];
        yield 'start anchor' => ['pattern' => '/^/', 'subject' => 'x', 'expected' => true];
        yield 'lookahead' => ['pattern' => '/(?=x)/', 'subject' => 'x', 'expected' => true];
        yield 'lookbehind' => ['pattern' => '/(?<=a)/', 'subject' => 'ab', 'expected' => true];
        yield 'class' => ['pattern' => '/[a]/', 'subject' => 'x', 'expected' => false];
        yield 'dot' => ['pattern' => '/./', 'subject' => '', 'expected' => false];
        yield 'shorthand' => ['pattern' => '/\d/', 'subject' => 'x', 'expected' => false];
        yield 'conditional with an empty no branch' => ['pattern' => '/(?(?=a)a|)/', 'subject' => 'x', 'expected' => true];
        yield 'conditional with two consuming branches' => ['pattern' => '/(?(?=a)a|b)/', 'subject' => 'x', 'expected' => false];
        yield 'conditional with a call in one branch' => ['pattern' => '/(?(?=a)(?1)|b)|(a)/', 'subject' => 'x', 'expected' => null];
        yield 'define block' => ['pattern' => '/(?(DEFINE)a)/', 'subject' => 'x', 'expected' => true];
        yield 'opening option then literal' => ['pattern' => '/(*UTF)a/', 'subject' => 'x', 'expected' => false];
        yield 'script run' => ['pattern' => '/(*sr:a)/', 'subject' => 'x', 'expected' => false];
        yield 'escaped character' => ['pattern' => '/\x61/', 'subject' => 'x', 'expected' => false];
        yield 'control character' => ['pattern' => '/\cA/', 'subject' => 'x', 'expected' => false];
        yield 'unicode property' => ['pattern' => '/\p{L}/', 'subject' => '!', 'expected' => false];
        yield 'extended class' => ['pattern' => '/(?[ !( [a] ^ [b] ) ])/', 'subject' => 'x', 'expected' => false, 'engineReadsIt' => false];
        yield 'keep' => ['pattern' => '/\K/', 'subject' => 'x', 'expected' => true];
        yield 'comment' => ['pattern' => '/(?#note)/', 'subject' => 'x', 'expected' => true];
        yield 'callout' => ['pattern' => '/(?C1)/', 'subject' => 'x', 'expected' => true];
        yield 'match limit' => ['pattern' => '/(*LIMIT_MATCH=10)/', 'subject' => 'x', 'expected' => true];
        yield 'back reference' => ['pattern' => '/(a*)\1/', 'subject' => 'x', 'expected' => null];
        yield 'back reference after a consuming literal' => ['pattern' => '/(a)b\1/', 'subject' => 'x', 'expected' => false];
        yield 'call' => ['pattern' => '/(a*)(?1)/', 'subject' => 'x', 'expected' => null];
        yield 'empty branch beside a reference' => ['pattern' => '/(a)(?:|\1)/', 'subject' => 'x', 'expected' => false];
        yield 'reference beside a consuming branch' => ['pattern' => '/(a*)(?:b|\1)/', 'subject' => 'x', 'expected' => null];
    }
}
