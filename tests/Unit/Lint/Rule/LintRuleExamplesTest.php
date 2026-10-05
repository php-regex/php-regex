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
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One pattern that must trip each lint rule, and one that must not.
 *
 * A rule that stops firing, or starts firing on innocent patterns, is
 * invisible to the corpus tests — they only say how many issues a project
 * has. This says which rule says what, and the last test makes sure a new
 * rule cannot be added without an example.
 */
final class LintRuleExamplesTest extends TestCase
{
    /**
     * Rule id => [a pattern that must raise it, a pattern that must not].
     */
    private const EXAMPLES = [
        'regex.lint.alternation.dotNewline' => ['/(.|\n)/', '/[\s\S]/'],
        'regex.lint.alternation.duplicateDisjunction' => ['/foo|foo/', '/foo|bar/'],
        'regex.lint.alternation.empty' => ['/a|/', '/a|b/'],
        'regex.lint.alternation.overlap' => ['/(foo|foobar)+/', '/(foo|bar)+/'],
        'regex.lint.anchor.impossible.end' => ['/a$b/', '/ab$/'],
        'regex.lint.anchor.impossible.start' => ['/^a^/', '/^ab/'],
        'regex.lint.backref.undefined' => ['/(a)\2/', '/(a)\1/'],
        'regex.lint.backref.useless' => ['/(a\1)/', '/(a)\1/'],
        'regex.lint.charclass.backrefAsOctal' => ['/(a)[\1]/', '/(a)\1/'],
        'regex.lint.charclass.duplicateChars' => ['/[0-9\d]/', '/[0-9a-f]/'],
        'regex.lint.charclass.literalMetachar' => ['/[\w+]/', '/\w+/'],
        'regex.lint.charclass.redundant' => ['/[aa]/', '/[ab]/'],
        'regex.lint.charclass.suspiciousPipe' => ['/[foo|bar]/', '/foo|bar/'],
        'regex.lint.charclass.suspiciousRange' => ['/[a-Z]/', '/[a-z]/'],
        'regex.lint.dotstar.nested' => ['/(?:.*)*/', '/.*/'],
        'regex.lint.escape.suspicious' => ['/\x{110000}/u', '/\x{10FFFF}/u'],
        'regex.lint.flag.override' => ['/(?-i)x/i', '/(?i)x/'],
        'regex.lint.flag.redundant' => ['/(?i)(?i)x/i', '/(?i)x/'],
        'regex.lint.flag.useless.i' => ['/123/i', '/abc/i'],
        'regex.lint.flag.useless.m' => ['/abc/m', '/^abc/m'],
        'regex.lint.flag.useless.s' => ['/abc/s', '/a.c/s'],
        'regex.lint.group.quantifiedCapture' => ['/(a)*/', '/(?:a)*/'],
        'regex.lint.group.redundant' => ['/(?:a)/', '/(?:ab)+/'],
        'regex.lint.overlap.charset' => ['/([a-m]|[a-z])+/', '/([a-m]|[n-z])+/'],
        'regex.lint.quantifier.assertion' => ['/(?=a)?b/', '/(?=a)b/'],
        'regex.lint.quantifier.concatenation' => ['/.*.*x/', '/.*x/'],
        'regex.lint.quantifier.lazyEnd' => ['/a.*?/', '/a.*?b/'],
        'regex.lint.quantifier.nested' => ['/(a+)+$/', '/(?>a+)+$/'],
        'regex.lint.quantifier.useless' => ['/a{1}/', '/a{2}/'],
        'regex.lint.quantifier.zero' => ['/a{0}/', '/a{1,}/'],
        'regex.lint.range.useless' => ['/[a-a]/', '/[a-f]/'],
        'regex.lint.unicode.bracedHexWithoutU' => ['/\x{100}/', '/\x{100}/u'],
        'regex.lint.unicode.multibyteInClassWithoutU' => ['/[é]/', '/[é]/u'],
        'regex.lint.unicode.propertyWithoutU' => ['/\p{L}/', '/\p{L}/u'],
        'regex.lint.unicode.quantifiedMultibyteWithoutU' => ['/é+/', '/é+/u'],
        'regex.lint.unicode.shorthandWithoutU' => ['/\w/', '/\w/u'],
    ];

    /**
     * Rules the linter leaves off unless the configuration asks for them.
     */
    private const ENABLE_ALL = ['unicode.shorthandWithoutU' => true];

    #[Test]
    #[DataProvider('provideExamples')]
    public function test_a_rule_reports_the_pattern_it_is_meant_to_catch(string $ruleId, string $pattern): void
    {
        $this->assertContains(
            $ruleId,
            $this->lint($pattern),
            \sprintf('%s did not report %s.', $ruleId, $pattern),
        );
    }

    #[Test]
    #[DataProvider('provideCounterExamples')]
    public function test_a_rule_leaves_a_sound_pattern_alone(string $ruleId, string $pattern): void
    {
        $this->assertNotContains(
            $ruleId,
            $this->lint($pattern),
            \sprintf('%s reported %s, which it should not.', $ruleId, $pattern),
        );
    }

    #[Test]
    public function test_every_registered_rule_has_an_example(): void
    {
        $registered = [];
        foreach ((new LintRuleRegistry())->all() as $rule) {
            foreach ($rule->getRuleIds() as $id) {
                $registered[$id] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($registered), array_keys(self::EXAMPLES)));
        $extra = array_values(array_diff(array_keys(self::EXAMPLES), array_keys($registered)));

        $this->assertSame([], $missing, 'These rules have no example; add one to self::EXAMPLES.');
        $this->assertSame([], $extra, 'These examples name a rule that is no longer registered.');
    }

    /**
     * @return iterable<string, array{ruleId: string, pattern: string}>
     */
    public static function provideExamples(): iterable
    {
        foreach (self::EXAMPLES as $ruleId => [$reported]) {
            yield $ruleId => ['ruleId' => $ruleId, 'pattern' => $reported];
        }
    }

    /**
     * @return iterable<string, array{ruleId: string, pattern: string}>
     */
    public static function provideCounterExamples(): iterable
    {
        foreach (self::EXAMPLES as $ruleId => [, $quiet]) {
            yield $ruleId => ['ruleId' => $ruleId, 'pattern' => $quiet];
        }
    }

    /**
     * @return list<string>
     */
    private function lint(string $pattern): array
    {
        $visitor = new PatternLinter(self::ENABLE_ALL);
        Regex::create()->parse($pattern)->accept($visitor);

        return array_values(array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues()));
    }
}
