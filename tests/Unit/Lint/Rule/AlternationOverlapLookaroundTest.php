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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lookaround reads nothing: two branches overlap on the characters they
 * take, never on what a lookaround in one of them looks at. The engine is
 * the oracle: the characters each branch starts a match it takes with, at
 * each offset of a text with a character before it or not.
 */
final class AlternationOverlapLookaroundTest extends TestCase
{
    private const CHARACTERS = ['a', 'b', 'B', 'x', '!', '>', '$', '\\', 'z'];

    /**
     * Under u, characters that share their first byte: "ö", "è" and "é"
     * all start with 0xC3.
     */
    private const UTF_CHARACTERS = ['a', 'b', 'x', 'ö', 'è', 'é', 'z'];

    /**
     * @param array{string, string} $branches
     */
    #[Test]
    #[DataProvider('provideAlternations')]
    public function test_branches_overlap_on_the_characters_they_take(string $pattern, array $branches, bool $reported): void
    {
        $shared = array_intersect(self::starts($branches[0]), self::starts($branches[1]));
        $this->assertSame($reported, [] !== $shared, \sprintf('Oracle: the branches of %s start a match with %s.', $pattern, implode(', ', $shared) ?: 'no shared character'));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn (object $issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($reported, \in_array('regex.lint.overlap.charset', $ids, true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, reported: bool}>
     */
    public static function provideAlternations(): iterable
    {
        yield 'a negative lookahead before the shared character' => ['pattern' => '/^(?:(?!x)a|a)+$/', 'branches' => ['(?!x)a', 'a'], 'reported' => true];
        yield 'a lookbehind before a character the other branch never takes' => ['pattern' => '/(?:[^$]+|(?<=\\\\)\$)+/', 'branches' => ['[^$]+', '(?<=\\\\)\$'], 'reported' => false];
        yield 'a branch that is a lookahead alone' => ['pattern' => '/^(?:>(?:(?!!)|!))+$/', 'branches' => ['(?!!)', '!'], 'reported' => false];
        yield 'a positive lookahead wider than its branch' => ['pattern' => '/^(?:(?=[ab])a|b)+$/', 'branches' => ['(?=[ab])a', 'b'], 'reported' => false];
        // The option group inside the lookahead takes no character either.
        yield 'a lookahead holding an option group' => ['pattern' => '/\A(?:(?=(?i:b))b|B)+\z/', 'branches' => ['(?=(?i:b))b', 'B'], 'reported' => false];
        yield 'a lookahead holding an option group before the shared character' => ['pattern' => '/\A(?:(?=(?i:b))b|b)+\z/', 'branches' => ['(?=(?i:b))b', 'b'], 'reported' => true];
        // A lookahead narrows the characters its branch starts with: a
        // positive one to those its body starts with, a negative one of a
        // single character leaves that character out.
        yield 'a lookahead narrower than its branch' => ['pattern' => '/\A(?:(?=b)[ab]|a)+\z/', 'branches' => ['(?=b)[ab]', 'a'], 'reported' => false];
        yield 'a lookahead narrower than its branch, on the shared character' => ['pattern' => '/\A(?:(?=a)[ab]|a)+\z/', 'branches' => ['(?=a)[ab]', 'a'], 'reported' => true];
        yield 'a lookahead of a choice narrower than its branch' => ['pattern' => '/\A(?:(?=b|x)[ab]|a)+\z/', 'branches' => ['(?=b|x)[ab]', 'a'], 'reported' => false];
        yield 'a lookahead of two characters, on the shared character' => ['pattern' => '/(?:(?=az)[ab]|a)+/', 'branches' => ['(?=az)[ab]', 'a'], 'reported' => true];
        yield 'a lookahead that may read nothing' => ['pattern' => '/\A(?:(?=b?)[ab]|a)+\z/', 'branches' => ['(?=b?)[ab]', 'a'], 'reported' => true];
        yield 'a lookahead on one way into the branch' => ['pattern' => '/\A(?:(?:(?=b)|x)[ab]|a)+\z/', 'branches' => ['(?:(?=b)|x)[ab]', 'a'], 'reported' => false];
        yield 'a negative lookahead leaving out the shared character' => ['pattern' => '/\A(?:(?!a)[ab]|a)+\z/', 'branches' => ['(?!a)[ab]', 'a'], 'reported' => false];
        yield 'a negative lookahead of a choice leaving out the shared character' => ['pattern' => '/\A(?:(?!a|x)[ab]|a)+\z/', 'branches' => ['(?!a|x)[ab]', 'a'], 'reported' => false];
        // "ab?" refuses "a" whatever follows it, "ab" only before "b".
        yield 'a negative lookahead of a character and more' => ['pattern' => '/\A(?:(?!ab?)[ab]|a)+\z/', 'branches' => ['(?!ab?)[ab]', 'a'], 'reported' => false];
        yield 'a negative lookahead of a character and more, on the character left' => ['pattern' => '/\A(?:(?!ab?)[ab]|b)+\z/', 'branches' => ['(?!ab?)[ab]', 'b'], 'reported' => true];
        yield 'a negative lookahead of two characters, on the shared character' => ['pattern' => '/(?:(?!ab)a|a)+/', 'branches' => ['(?!ab)a', 'a'], 'reported' => true];
        yield 'a negative lookahead of a group' => ['pattern' => '/\A(?:(?!(?:a))[ab]|a)+\z/', 'branches' => ['(?!(?:a))[ab]', 'a'], 'reported' => false];
        // Without u "é" is two bytes: the lookahead refuses its first only before its second.
        yield 'a negative lookahead of a character of two bytes' => ['pattern' => '/\A(?:(?!é)[ab]|a)+\z/', 'branches' => ['(?!é)[ab]', 'a'], 'reported' => true];
        // A lookahead the rule cannot read leaves its branch unjudged.
        yield 'a negative lookahead of a repeated character' => ['pattern' => '/\A(?:(?!a+)[ab]|a)+\z/', 'branches' => ['(?!a+)[ab]', 'a'], 'reported' => false];
        yield 'a negative lookahead of a choice holding a repeated character' => ['pattern' => '/\A(?:(?!a|b+)[ab]|a)+\z/', 'branches' => ['(?!a|b+)[ab]', 'a'], 'reported' => false];
        // What stands before the lookahead and reads nothing passes it on.
        yield 'a lookahead after an option setting' => ['pattern' => '/\A(?:(?i)(?=b)[ab]|a)+\z/', 'branches' => ['(?i)(?=b)[ab]', 'a'], 'reported' => false];
        yield 'a lookahead after a character taken no time' => ['pattern' => '/\A(?:x{0}(?=b)[ab]|a)+\z/', 'branches' => ['x{0}(?=b)[ab]', 'a'], 'reported' => false];
        yield 'a lookahead after an empty alternative' => ['pattern' => '/\A(?:(?:|x)(?=b)[ab]|a)+\z/', 'branches' => ['(?:|x)(?=b)[ab]', 'a'], 'reported' => false];
        yield 'a lookahead in a group that may read nothing' => ['pattern' => '/\A(?:(?:(?=b)x?)[ab]|a)+\z/', 'branches' => ['(?:(?=b)x?)[ab]', 'a'], 'reported' => false];
    }

    /**
     * Under u the probe reads characters, not the bytes of their UTF-8: two
     * characters sharing their first byte are no overlap, and the branches
     * are read past a lookaround as they are without u.
     *
     * @param array{string, string} $branches
     */
    #[Test]
    #[DataProvider('provideAlternationsInUtfMode')]
    public function test_branches_in_utf_mode_overlap_on_the_characters_they_take(string $pattern, array $branches, bool $reported): void
    {
        $shared = array_intersect(self::starts($branches[0], 'u'), self::starts($branches[1], 'u'));
        $this->assertSame($reported, [] !== $shared, \sprintf('Oracle: the branches of %s start a match with %s.', $pattern, implode(', ', $shared) ?: 'no shared character'));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn (object $issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($reported, \in_array('regex.lint.overlap.charset', $ids, true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, reported: bool}>
     */
    public static function provideAlternationsInUtfMode(): iterable
    {
        yield 'a lookahead before a character sharing its first byte with the other branch' => ['pattern' => '/^(?:(?=ö)ö|è)+$/u', 'branches' => ['(?=ö)ö', 'è'], 'reported' => false];
        yield 'a negative lookahead before a character sharing its first byte with the other branch' => ['pattern' => '/^(?:(?!è)ö|è)+$/u', 'branches' => ['(?!è)ö', 'è'], 'reported' => false];
        yield 'a lookahead before the character the other branch takes' => ['pattern' => '/^(?:(?=ö)ö|ö)+$/u', 'branches' => ['(?=ö)ö', 'ö'], 'reported' => true];
        yield 'a lookahead before the ASCII character the other branch takes' => ['pattern' => '/^(?:(?=a)a|a)+$/u', 'branches' => ['(?=a)a', 'a'], 'reported' => true];
        yield 'a lookbehind before a character sharing its first byte with the other branch' => ['pattern' => '/^(?:(?<=a)ö|è)+$/u', 'branches' => ['(?<=a)ö', 'è'], 'reported' => false];
        yield 'a lookahead narrower than its branch' => ['pattern' => '/\A(?:(?=b)[ab]|a)+\z/u', 'branches' => ['(?=b)[ab]', 'a'], 'reported' => false];
        yield 'a lookahead narrower than its class past ASCII' => ['pattern' => '/\A(?:(?=ö)[öè]|è)+\z/u', 'branches' => ['(?=ö)[öè]', 'è'], 'reported' => false];
        yield 'a lookahead narrower than its class past ASCII, on the shared character' => ['pattern' => '/\A(?:(?=è)[öè]|è)+\z/u', 'branches' => ['(?=è)[öè]', 'è'], 'reported' => true];
        yield 'a negative lookahead leaving out the shared character past ASCII' => ['pattern' => '/\A(?:(?!è)[öè]|è)+\z/u', 'branches' => ['(?!è)[öè]', 'è'], 'reported' => false];
    }

    /**
     * The characters the branch starts a match it takes with, at the start
     * of a text and after each character.
     *
     * @return list<string>
     */
    private static function starts(string $branch, string $modifiers = ''): array
    {
        $characters = 'u' === $modifiers ? self::UTF_CHARACTERS : self::CHARACTERS;
        $starts = [];
        foreach (['', ...$characters] as $before) {
            foreach ($characters as $character) {
                if (1 === preg_match('/\G(?:'.$branch.')/'.$modifiers, $before.$character.'zz', $match, 0, \strlen($before)) && '' !== $match[0]) {
                    $starts['u' === $modifiers ? mb_substr($match[0], 0, 1, 'UTF-8') : $match[0][0]] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($starts));
    }
}
