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
 * A lookahead only checks that its body can match: the last item repeated
 * past its minimum changes nothing, as the first item of a lookbehind.
 * PHP 8.4.26 / PCRE2 10.49: each reported pattern gives the same
 * preg_match_all() result, offsets included, as the rewrite the message
 * names, on every subject below; each silent one differs on one at least.
 */
final class LookaroundEdgeQuantifierTest extends TestCase
{
    private const RULE = 'regex.lint.lookaround.edgeQuantifier';

    private const SUBJECTS = ['', 'a', 'aa', 'aaa', 'aaaa', 'aaaaaaa', 'ab', 'aab', 'abb', 'b', 'ba', 'baa', 'aaab', 'xaaay', 'xa', 'xaa', 'aax', 'aab!'];

    #[Test]
    #[DataProvider('provideEdgeQuantifiers')]
    public function test_a_quantifier_past_its_minimum_at_the_edge_of_a_lookaround_is_reported(string $pattern, string $rewrite, string $message): void
    {
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(
                [preg_match_all($rewrite, $subject, $expected, \PREG_OFFSET_CAPTURE), $expected],
                [preg_match_all($pattern, $subject, $actual, \PREG_OFFSET_CAPTURE), $actual],
                $pattern.' on '.$subject,
            );
        }

        $this->assertSame([$message], $this->messages($pattern, true));
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, message: string}>
     */
    public static function provideEdgeQuantifiers(): iterable
    {
        yield 'a range ending a lookahead' => ['pattern' => '/x(?=a{2,6})/', 'rewrite' => '/x(?=a{2})/', 'message' => '"a{2,6}" ends a lookahead: only its minimum is ever checked, "(?=a{2})" asserts the same.'];
        yield 'a star ending a lookahead' => ['pattern' => '/(?=ab*)/', 'rewrite' => '/(?=a)/', 'message' => '"b*" ends a lookahead: only its minimum is ever checked, "(?=a)" asserts the same.'];
        yield 'a lazy plus ending a lookahead' => ['pattern' => '/(?=a+?)a/', 'rewrite' => '/(?=a)a/', 'message' => '"a+?" ends a lookahead: only its minimum is ever checked, "(?=a)" asserts the same.'];
        yield 'a range ending a negative lookahead' => ['pattern' => '/(?!a{2,6})a/', 'rewrite' => '/(?!a{2})a/', 'message' => '"a{2,6}" ends a lookahead: only its minimum is ever checked, "(?!a{2})" asserts the same.'];
        yield 'a range starting a lookbehind' => ['pattern' => '/(?<=a{2,6})b/', 'rewrite' => '/(?<=a{2})b/', 'message' => '"a{2,6}" starts a lookbehind: only its minimum is ever checked, "(?<=a{2})" asserts the same.'];
        yield 'a range ending one alternative' => ['pattern' => '/(?=b|a{2,3})/', 'rewrite' => '/(?=b|a{2})/', 'message' => '"a{2,3}" ends a lookahead: only its minimum is ever checked, "(?=b|a{2})" asserts the same.'];
    }

    #[Test]
    #[DataProvider('provideCheckedQuantifiers')]
    public function test_a_quantifier_whose_count_matters_is_not_reported(string $pattern, string $rewrite): void
    {
        $differs = false;
        foreach (self::SUBJECTS as $subject) {
            $original = [preg_match_all($pattern, $subject, $actual, \PREG_OFFSET_CAPTURE), $actual];
            $differs = $differs || $original !== [preg_match_all($rewrite, $subject, $expected, \PREG_OFFSET_CAPTURE), $expected];
        }

        $this->assertTrue($differs, $pattern.' behaves as '.$rewrite);
        $this->assertSame([], $this->messages($pattern, true));
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string}>
     */
    public static function provideCheckedQuantifiers(): iterable
    {
        yield 'a capture keeps the run' => ['pattern' => '/(?=(a{2,6}))/', 'rewrite' => '/(?=(a{2}))/'];
        yield 'an anchor follows the repeat' => ['pattern' => '/(?=a{2,6}$)/', 'rewrite' => '/(?=a{2}$)/'];
        yield 'the repeat starts a lookahead' => ['pattern' => '/(?=a{1,3}b)/', 'rewrite' => '/(?=ab)/'];
        // A lookbehind of variable length reads the end of the subject at
        // its own position, one of fixed length the real one: cutting the
        // repeat may turn the first into the second.
        yield 'an end anchor in a lookbehind' => ['pattern' => '/(?<=b{1,2}\z)a/', 'rewrite' => '/(?<=b\z)a/'];
        yield 'a lookahead in a lookbehind' => ['pattern' => '/(?<=b{1,2}(?=a))a/', 'rewrite' => '/(?<=b(?=a))a/'];
        yield 'an end anchor in another branch of a lookbehind' => ['pattern' => '/(?<!\z|b?c)/', 'rewrite' => '/(?<!\z|c)/'];
        yield 'a word boundary in a lookbehind' => ['pattern' => '/(?<=b{1,2}\b)/', 'rewrite' => '/(?<=b\b)/'];
    }

    #[Test]
    public function test_a_fixed_count_or_a_non_atomic_lookahead_is_not_reported(): void
    {
        $this->assertSame([], $this->messages('/(?=a{2})/', true));
        $this->assertSame([], $this->messages('/(*napla:a{2,6})a/', true));
    }

    /**
     * What the automata cannot answer keeps the rule silent: a possessive
     * repeat, an ASCII option the questions cannot carry; and a body that
     * holds a capture anywhere, or nothing but a comment.
     */
    #[Test]
    public function test_a_body_the_rule_cannot_judge_is_not_reported(): void
    {
        $this->assertSame([], $this->messages('/(?=a{2,}+)/', true));
        $this->assertSame([], $this->messages('/(?aD)x(?=a{2,6})/', true));
        $this->assertSame([], $this->messages('/(?=x(a)b{2,3})/', true));
        $this->assertSame([], $this->messages('/a(?=(?#c)(?#d))/', true));
    }

    #[Test]
    public function test_the_rule_is_off_by_default(): void
    {
        $this->assertSame([], $this->messages('/x(?=a{2,6})/', false));
    }

    /**
     * @return list<string>
     */
    private function messages(string $pattern, bool $enabled): array
    {
        $linter = new PatternLinter($enabled ? ['lookaround.edgeQuantifier' => true] : []);
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if (self::RULE === $issue->id) {
                $messages[] = $issue->message;
            }
        }

        return $messages;
    }
}
