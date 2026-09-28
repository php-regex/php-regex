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

namespace RegexParser\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;
use RegexParser\Regex;

/**
 * A lone \E or an empty \Q\E is transparent to a quantifier, like a
 * comment: "a\E*" is "a*", "a*\E+" is "a*+", and "a{1,3}\E{2}" is refused.
 * A quantifier after a quoted run repeats its last character only:
 * "\Qab\E*" is "ab*". Every row was checked with preg_match() on PCRE2
 * 10.48 and with pcre2test 10.40.
 */
final class QuantifierAfterQuoteEndTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_a_quantifier_with_nothing_repeatable_before_the_quote_end(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchingPatterns')]
    public function test_the_tree_repeats_what_php_repeats(string $pattern, array $subjects): void
    {
        $result = Regex::create()->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s compiles in PHP but was reported invalid: %s', $pattern, (string) $result->error));

        $ast = Regex::create()->parse($pattern);
        $compiled = $ast->accept(new CompilerNodeVisitor());
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s on "%s"', $pattern, $compiled, $subject));
        }

        // A sample built from the tree must be matched by the pattern itself.
        $this->assertSame(1, preg_match($pattern, $ast->accept(new SampleGeneratorNodeVisitor())), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        yield 'repeat of a counted item through \\E: /a{1,3}\\E{2}/' => ['pattern' => '/a{1,3}\\E{2}/'];
        yield 'comment then \\E at the start: /(?#c)\\E??/' => ['pattern' => '/(?#c)\\E??/'];
        yield 'repeat of a starred item through an empty quote: /a*\\Q\\E*/' => ['pattern' => '/a*\\Q\\E*/'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideMatchingPatterns(): iterable
    {
        yield '/^a\\E*$/' => ['pattern' => '/^a\\E*$/', 'subjects' => ['', 'a', 'aaa', 'b']];
        yield '/^a\\Q\\E+$/' => ['pattern' => '/^a\\Q\\E+$/', 'subjects' => ['a', 'aa', '']];
        yield '/^\\Qab\\E*$/' => ['pattern' => '/^\\Qab\\E*$/', 'subjects' => ['a', 'abb', 'abab', '']];
        yield '/^\\Qab\\E{2}$/' => ['pattern' => '/^\\Qab\\E{2}$/', 'subjects' => ['abb', 'abab']];
        yield '/^a*\\E+$/' => ['pattern' => '/^a*\\E+$/', 'subjects' => ['aa', '']];
        yield '/^a\\E {,2}$/x' => ['pattern' => '/^a\\E {,2}$/x', 'subjects' => ['a', 'aa', '']];
        yield '/^\\Qa\\E\\E*$/' => ['pattern' => '/^\\Qa\\E\\E*$/', 'subjects' => ['', 'aaa']];
    }
}
