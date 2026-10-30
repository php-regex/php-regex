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
 * Two branches that both start with literal text are told apart by that
 * text: when neither text is a prefix of the other, a character the
 * branches share at their start ("associative-array" and "array" both
 * start with "a") never lets one branch take the other's input, and no
 * character-set overlap is reported. The overlap stays reported when the
 * texts are prefixes of each other, or when a branch starts with
 * something that is no literal.
 */
final class AlternationOverlapDivergingLiteralsTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideDivergingBranches(): iterable
    {
        yield 'two words sharing their first letter' => ['pattern' => '/(?:associative-array|array)+/'];
        yield 'the same words the other way round' => ['pattern' => '/(?:array|associative-array)+/'];
        yield 'a word followed by a group' => ['pattern' => '/(?:callable-(?:string|object)|class-string)+/'];
        yield 'an escaped literal in the text' => ['pattern' => '/(?:<\?xml\b.*?\?>|<!--.*?-->)+/'];
        yield 'words read through capturing groups' => ['pattern' => '/(?:(associative)-array|(array))+/'];
        yield 'a group holding more than text' => ['pattern' => '/(?:(ab\d)|ac)+/'];
        yield 'a branch holding bare flags alone' => ['pattern' => '/(?:(?i)(?s)|ab)+/'];
        yield 'an empty branch' => ['pattern' => '/(?:|ab)+/'];
        yield 'a caseless group after a case-sensitive text' => ['pattern' => '/(?:Ab(?i:\d)|ab)+/'];
        yield 'words told apart under /i' => ['pattern' => '/(?:Array|associative-array)+/i'];
        yield 'a name and a wider word that only share a letter' => ['pattern' => '/(?:ab|AC)+/i'];
        yield 'texts above ASCII' => ['pattern' => '/(?:été|école)+/u'];
        yield 'texts beside a byte class above ASCII, read through PCRE' => ['pattern' => '/(?:[\xC0-\xFF]a|ab|ac)+/'];
    }

    #[Test]
    #[DataProvider('provideDivergingBranches')]
    public function test_branches_whose_leading_texts_diverge_are_not_reported(string $pattern): void
    {
        $this->assertSame([], self::charsetOverlapMessages($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOverlappingBranches(): iterable
    {
        yield 'a text that prefixes the other, then a digit' => ['pattern' => '/(?:a\d|ab)+/'];
        yield 'a class before the text' => ['pattern' => '/(?:[ab]x|ay)+/'];
        yield 'a quantified first character' => ['pattern' => '/(?:a+b|ac)+/'];
        yield 'texts that fold to a prefix under /i' => ['pattern' => '/(?:A\d|ab)+/i'];
        yield 'a single character before a longer text' => ['pattern' => '/(?:a|ab\d)+/'];
        yield 'a third branch overlapping the first, past a second told apart' => ['pattern' => '/(?:ab|ac|ab\d)+/'];
        // The text after a "(?i:" or a bare "(?i)" is caseless: "aB" there
        // takes "ab" as the other branch does.
        yield 'a caseless group inside the text' => ['pattern' => '/(?:ab\d|a(?i:B)\d)+/'];
        yield 'a bare caseless flag inside the text' => ['pattern' => '/(?:ab\d|a(?i)B\d)+/'];
        yield 'a caseless group under a quantifier at the start' => ['pattern' => '/(?:(?i:a)+\d|A\d)+/'];
        yield 'a caseless first branch beside a case-sensitive one' => ['pattern' => '/(?:(?i)Ab\d|ab)+/'];
        yield 'a caseless second branch beside a case-sensitive one' => ['pattern' => '/(?:ab\d|(?i)AB)+/'];
        yield 'a caseless group around the text' => ['pattern' => '/(?:(?i:Ab)\d|ab)+/'];
        // A bare (?i) in one branch holds for the branches after it: PCRE
        // matches "AB" through the second branch here.
        yield 'a bare caseless flag in the first branch, reaching the second' => ['pattern' => '/(?:Ab(?i)\d|ab)+/'];
        yield 'texts that fold to a prefix with a final sigma' => ['pattern' => '/(?:σa\d|ςab)+/iu'];
        yield 'a text above ASCII that prefixes the other' => ['pattern' => '/(?:é\d|ét)+/u'];
        yield 'a text that prefixes the other, beside a byte class above ASCII' => ['pattern' => '/(?:[\xC0-\xFF]a|ab|a\d)+/'];
    }

    #[Test]
    #[DataProvider('provideOverlappingBranches')]
    public function test_branches_that_may_take_each_others_input_are_still_reported(string $pattern): void
    {
        $this->assertCount(1, self::charsetOverlapMessages($pattern), $pattern);
    }

    /**
     * @return list<string>
     */
    private static function charsetOverlapMessages(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if (str_contains($issue->message, 'have overlapping character sets')) {
                $messages[] = $issue->message;
            }
        }

        return $messages;
    }
}
