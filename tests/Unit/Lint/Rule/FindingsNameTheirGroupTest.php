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

use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\NestedQuantifierRule;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\QuantifiedCapturingGroupRule;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two findings of one rule on one pattern read apart: a quantified capture
 * and a nested quantifier name the group they are about by its number or
 * its name, or, for a group with neither, by where it starts in the
 * pattern, and quote it as written: on one line, without the layout and the
 * comments, on thirty columns at most.
 */
final class FindingsNameTheirGroupTest extends TestCase
{
    use PcreGates;

    /**
     * A list of names, each with "-" suffixes, as phan reads its suppression
     * comments: three repeated captures, two of them with the same source.
     */
    private const NAME_LIST = '/@phan-(suppress-(next(?:-next)?|current|previous)-line|file-suppress)\s+(([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(-[a-zA-Z0-9_\x7f-\xff]+)*)(,\s*([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(-[a-zA-Z0-9_\x7f-\xff]+)*))*)/';

    /**
     * Oracle, PHP 8.4.27 / PCRE2 10.49: each repeated group keeps its last
     * iteration, under the number PCRE gives it.
     */
    #[Test]
    public function test_the_engine_keeps_the_last_iteration_of_each_repeated_group(): void
    {
        preg_match(self::NAME_LIST, '@phan-file-suppress A-b-c, D-e-f, G-h-i', $matches);
        $this->assertSame('-c', $matches[5] ?? null);
        $this->assertSame(', G-h-i', $matches[6] ?? null);
        $this->assertSame('-i', $matches[8] ?? null);

        // A branch reset gives both alternatives the same number.
        preg_match('/(a)(?|(b)+|(c)+)(d)+/', 'acdd', $matches);
        $this->assertSame(['acdd', 'a', 'c', 'd'], $matches);
    }

    /**
     * Oracle, PHP 8.4.27 / PCRE2 10.49: under the n modifier, which PHP
     * reads from 8.2, an unnamed group does not capture.
     */
    #[Test]
    public function test_the_engine_numbers_no_unnamed_group_under_the_n_modifier(): void
    {
        $this->requiresPhpReading('/(a+)+b/n');

        preg_match('/(a+)+b/n', 'aab', $matches);
        $this->assertSame(['aab'], $matches);
    }

    /**
     * @param list<string> $messages
     */
    #[Test]
    #[DataProvider('provideQuantifiedCaptures')]
    public function test_a_quantified_capture_names_its_group(string $pattern, array $messages): void
    {
        $this->requiresPhpReading($pattern);
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        $this->assertSame($messages, self::messages($pattern, QuantifiedCapturingGroupRule::ID));
    }

    /**
     * @return iterable<string, array{pattern: string, messages: list<string>}>
     */
    public static function provideQuantifiedCaptures(): iterable
    {
        yield 'three repeated groups, two with the same source' => [
            'pattern' => self::NAME_LIST,
            'messages' => [
                'Quantified capturing group 5 "(-[a-zA-Z0-9_\x7f-\xff]+)" with "*": only the last iteration\'s capture is retained.',
                'Quantified capturing group 6 "(,\s*([a-zA-Z_\x7f-\xff][a-zA-…" with "*": only the last iteration\'s capture is retained.',
                'Quantified capturing group 8 "(-[a-zA-Z0-9_\x7f-\xff]+)" with "*": only the last iteration\'s capture is retained.',
            ],
        ];

        yield 'the number counts the groups before' => [
            'pattern' => '/(a)(b)+/',
            'messages' => ['Quantified capturing group 2 "(b)" with "+": only the last iteration\'s capture is retained.'],
        ];

        yield 'a branch reset shares its numbers' => [
            'pattern' => '/(a)(?|(b)+|(c)+)(d)+/',
            'messages' => [
                'Quantified capturing group 2 "(b)" with "+": only the last iteration\'s capture is retained.',
                'Quantified capturing group 2 "(c)" with "+": only the last iteration\'s capture is retained.',
                'Quantified capturing group 3 "(d)" with "+": only the last iteration\'s capture is retained.',
            ],
        ];

        yield 'a named group keeps its name' => [
            'pattern' => '/(?<word>\w)+/',
            'messages' => ['Quantified named group "(?<word>\w)" with "+": only the last iteration\'s capture is retained.'],
        ];

        yield 'a multibyte letter before the group' => [
            'pattern' => '/é(a){2,}/u',
            'messages' => ['Quantified capturing group 1 "(a)" with "{2,}": only the last iteration\'s capture is retained.'],
        ];

        yield 'a multibyte letter in the group' => [
            'pattern' => '/x(é)+/u',
            'messages' => ['Quantified capturing group 1 "(é)" with "+": only the last iteration\'s capture is retained.'],
        ];

        yield 'a group laid out under x, with comments' => [
            'pattern' => "/( [a-z]+ # letters\n \\d+ (?#digits) )+ x/x",
            'messages' => ['Quantified capturing group 1 "([a-z]+\d+)" with "+": only the last iteration\'s capture is retained.'],
        ];

        yield 'no capture under the n modifier' => [
            'pattern' => '/(a+)+b/n',
            'messages' => [],
        ];
    }

    /**
     * @param list<string> $messages
     */
    #[Test]
    #[DataProvider('provideNestedQuantifiers')]
    public function test_a_nested_quantifier_names_its_group(string $pattern, array $messages): void
    {
        $this->requiresPhpReading($pattern);
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
        $this->assertSame($messages, self::messages($pattern, NestedQuantifierRule::ID));
    }

    /**
     * @return iterable<string, array{pattern: string, messages: list<string>}>
     */
    public static function provideNestedQuantifiers(): iterable
    {
        yield 'two loops with the same source' => [
            'pattern' => self::NAME_LIST,
            'messages' => [
                'Nested quantifiers in capturing group 5 "(-[a-zA-Z0-9_\x7f-\xff]+)" can cause catastrophic backtracking.',
                'Nested quantifiers in capturing group 8 "(-[a-zA-Z0-9_\x7f-\xff]+)" can cause catastrophic backtracking.',
            ],
        ];

        yield 'a capturing group' => [
            'pattern' => '/(a+)+b/',
            'messages' => ['Nested quantifiers in capturing group 1 "(a+)" can cause catastrophic backtracking.'],
        ];

        yield 'a capturing group laid out under x' => [
            'pattern' => "/( a+ # run\n )+ b/x",
            'messages' => ['Nested quantifiers in capturing group 1 "(a+)" can cause catastrophic backtracking.'],
        ];

        yield 'a named group' => [
            'pattern' => '/(?<run>a+)+b/',
            'messages' => ['Nested quantifiers in named group "(?<run>a+)" can cause catastrophic backtracking.'],
        ];

        yield 'a non-capturing group at the start' => [
            'pattern' => '/(?:a+)+b/',
            'messages' => ['Nested quantifiers in "(?:a+)" at offset 0 can cause catastrophic backtracking.'],
        ];

        yield 'a non-capturing group further on' => [
            'pattern' => '/x(?:a+)+b/',
            'messages' => ['Nested quantifiers in "(?:a+)" at offset 1 can cause catastrophic backtracking.'],
        ];

        yield 'the offset counts bytes' => [
            'pattern' => '/é(?:a+)+b/u',
            'messages' => ['Nested quantifiers in "(?:a+)" at offset 2 can cause catastrophic backtracking.'],
        ];

        yield 'a repeated conditional is no group' => [
            'pattern' => '/(a)?(?(1)a+|b)+c/',
            'messages' => ['Nested quantifiers in "(?(1)a+|b)" at offset 4 can cause catastrophic backtracking.'],
        ];

        yield 'an unnamed group under the n modifier does not capture' => [
            'pattern' => '/(a+)+b/n',
            'messages' => ['Nested quantifiers in "(a+)" at offset 0 can cause catastrophic backtracking.'],
        ];

        yield 'a loop found past a fixed count' => [
            'pattern' => '/^(?:\d{4}|[a-z]+)+$/',
            'messages' => ['Nested quantifiers in "(?:\d{4}|[a-z]+)" at offset 1 can cause catastrophic backtracking.'],
        ];

        yield 'a loop found behind one that may match nothing' => [
            'pattern' => '/^(?:b?c+)+$/',
            'messages' => ['Nested quantifiers in "(?:b?c+)" at offset 1 can cause catastrophic backtracking.'],
        ];
    }

    #[Test]
    public function test_a_quantified_capture_without_group_numbers_reads_its_offset(): void
    {
        $tree = Regex::create(['cache' => null])->parse('/x(a)+/');
        $this->assertInstanceOf(SequenceNode::class, $tree->pattern);
        $quantifier = $tree->pattern->children[1];
        $this->assertInstanceOf(QuantifierNode::class, $quantifier);

        $context = new LintContext(
            new PatternInfo($tree->flags, $tree->delimiter, 'x(a)+', false),
            new GroupIndex(0, [], [], [], false),
            new CharSetAnalyzer($tree->flags),
        );

        $violations = (new QuantifiedCapturingGroupRule())->check($quantifier, $context);

        $this->assertCount(1, $violations);
        $this->assertSame('Quantified capturing group "(a)" at offset 1 with "+": only the last iteration\'s capture is retained.', $violations[0]->message);
    }

    /**
     * @return list<string>
     */
    private static function messages(string $pattern, string $id): array
    {
        $violations = array_filter(
            Regex::create(['cache' => null])->lint($pattern),
            static fn (RuleViolation $violation): bool => $id === $violation->id,
        );

        return array_values(array_map(static fn (RuleViolation $violation): string => $violation->message, $violations));
    }
}
