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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A (?#...) comment is transparent to a quantifier: "a(?#c)*" is "a*", and
 * with nothing repeatable before the comment PCRE refuses the quantifier
 * ("quantifier does not follow a repeatable item"). Every row was checked
 * with preg_match() on PCRE2 10.48 and with pcre2test 10.40.
 */
final class QuantifierAfterCommentTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_a_quantifier_with_nothing_repeatable_before_the_comment(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    #[Test]
    #[DataProvider('provideAcceptedPatterns')]
    public function test_validate_accepts_a_quantifier_repeating_the_item_before_the_comment(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PHP but was reported invalid: %s', $pattern, (string) $result->error));
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchingPatterns')]
    public function test_the_tree_repeats_the_item_before_the_comment(string $pattern, array $subjects): void
    {
        $ast = Regex::create()->parse($pattern);
        $compiled = $ast->accept(new PatternPrinter());

        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s on "%s"', $pattern, $compiled, $subject));
        }

        // A sample built from the tree must be matched by the pattern itself.
        $this->assertSame(1, preg_match($pattern, $ast->accept(new SampleGenerator())));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        yield 'comment at the start: /(?#c)*/' => ['pattern' => '/(?#c)*/'];
        yield 'comment after an option setting: /(?i)(?#c)*/' => ['pattern' => '/(?i)(?#c)*/'];
        yield 'comment after a start anchor: /^(?#c)*/' => ['pattern' => '/^(?#c)*/'];
        yield 'comment after an end anchor: /$(?#c){2}/' => ['pattern' => '/$(?#c){2}/'];
        yield 'comment after a word boundary: /\\b(?#c)?/' => ['pattern' => '/\\b(?#c)?/'];
        yield 'two comments at the start: /(?#c)(?#d)+/' => ['pattern' => '/(?#c)(?#d)+/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPatterns(): iterable
    {
        yield 'comment after a literal: /a(?#c)*/' => ['pattern' => '/a(?#c)*/'];
        yield 'two comments after a literal: /a(?#c)(?#d)+/' => ['pattern' => '/a(?#c)(?#d)+/'];
        yield 'comment after a group: /(?:a)(?#c){2}/' => ['pattern' => '/(?:a)(?#c){2}/'];
        yield 'comment after a class: /[a](?#c)+/' => ['pattern' => '/[a](?#c)+/'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideMatchingPatterns(): iterable
    {
        yield '/^a(?#c)*$/' => ['pattern' => '/^a(?#c)*$/', 'subjects' => ['', 'a', 'aaa', 'b']];
        yield '/^(?:ab)(?#c){2}$/' => ['pattern' => '/^(?:ab)(?#c){2}$/', 'subjects' => ['abab', 'ab', 'ababab']];
        yield '/^[ab](?#c)(?#d)+$/' => ['pattern' => '/^[ab](?#c)(?#d)+$/', 'subjects' => ['a', 'abba', 'c']];
    }
}
