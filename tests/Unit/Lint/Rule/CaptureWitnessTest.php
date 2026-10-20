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
use PHPRegex\Linter\Rule\AbstractLintRule;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Linter\Rule\Support\CaptureWitness;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether a capturing group can capture text is shown, when it can, by a
 * subject built from the pattern on which the running engine fills it:
 * then the pattern with the group's body removed writes other $matches,
 * and the automata need not be asked whether the group always stays empty.
 * No subject is not an answer: the automata are asked.
 */
final class CaptureWitnessTest extends TestCase
{
    use PcreGates;

    /**
     * @param list<bool> $expected one answer per capturing group, in order
     */
    #[Test]
    #[DataProvider('provideGroups')]
    public function test_a_group_the_engine_fills_on_a_subject_built_from_the_pattern(string $pattern, array $expected): void
    {
        $this->assertSame($expected, array_map(static fn (array $seen): bool => $seen['fills'], self::groupsOf($pattern)));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<bool>}>
     */
    public static function provideGroups(): iterable
    {
        yield 'two runs of digits' => ['pattern' => '/(\d+)-(\d+)/', 'expected' => [true, true]];
        yield 'the second alternative' => ['pattern' => '/(?:x|(y))z/', 'expected' => [true]];
        yield 'a class' => ['pattern' => '/^([a-z]+)\.([A-F0-9]{2,})$/', 'expected' => [true, true]];
        yield 'a negated class' => ['pattern' => '/([^a-z])([^\d\s]+)/', 'expected' => [true, true]];
        yield 'a named group' => ['pattern' => '/(?<year>\d{4})-(?<month>\d\d)/', 'expected' => [true, true]];
        yield 'an optional group' => ['pattern' => '/a(b)?c(d)*e/', 'expected' => [true, true]];
        yield 'a group in a repeated group' => ['pattern' => '/(?:k(v)=,?)+$/', 'expected' => [true]];
        yield 'characters of several bytes' => ['pattern' => '/(é+)(\x{20AC})/u', 'expected' => [true, true]];
        yield 'a byte pattern' => ['pattern' => "/(\xE9)b/", 'expected' => [true]];
        yield 'extended mode' => ['pattern' => "/(a b) # c\n (d)/x", 'expected' => [true, true]];
        yield 'nested groups' => ['pattern' => '/((a)(b(c)))/', 'expected' => [true, true, true, true]];
        yield 'a run that takes everything' => ['pattern' => '/a+(a*)b/', 'expected' => [false]];
        yield 'an earlier alternative that always wins' => ['pattern' => '/(?:a|(a))b/', 'expected' => [false]];
        yield 'a branch that can never match' => ['pattern' => '/[^\s\S](a)/', 'expected' => [false]];
        yield 'a group a reference reads' => ['pattern' => '/(a)\1/', 'expected' => [false]];
        yield 'a group in a lookahead' => ['pattern' => '/x(?=(a))/', 'expected' => [false]];
        yield 'a group in a condition' => ['pattern' => '/(?(?=x)x(a)|y)/', 'expected' => [false]];
        yield 'a code point without u' => ['pattern' => '/(\x41)/', 'expected' => [true]];
        yield 'types (\\D\\S\\H\\V\\N)' => ['pattern' => '/(\D\S\H\V\N)/', 'expected' => [true]];
        yield 'types (\\W)' => ['pattern' => '/(\W)/', 'expected' => [true]];
        yield 'types (\\v)' => ['pattern' => '/(\v)/', 'expected' => [true]];
        yield 'types (\\R)' => ['pattern' => '/(\R)/', 'expected' => [true]];
        yield 'class members ([\\d])' => ['pattern' => '/([\d])/u', 'expected' => [true]];
        yield 'class members ([[:alpha:]])' => ['pattern' => '/([[:alpha:]])/u', 'expected' => [true]];
        yield 'class members ([\\pL])' => ['pattern' => '/([\pL])/u', 'expected' => [true]];
        yield 'class members ([[:upper:]])' => ['pattern' => '/([[:upper:]])/u', 'expected' => [true]];
        yield 'class members ([[:punct:]])' => ['pattern' => '/([[:punct:]])/u', 'expected' => [true]];
        yield 'class members ([[:space:]])' => ['pattern' => '/([[:space:]])/u', 'expected' => [true]];
        yield 'class members ([[:alnum:]])' => ['pattern' => '/([[:alnum:]])/u', 'expected' => [true]];
        yield 'class members ([[:xdigit:]])' => ['pattern' => '/([[:xdigit:]])/u', 'expected' => [true]];
        yield 'a class with no member read ([\\p{Han}])' => ['pattern' => '/([\p{Han}])/u', 'expected' => [false]];
        yield 'a class with no member read ([[:cntrl:]])' => ['pattern' => '/([[:cntrl:]])/u', 'expected' => [false]];
        yield 'negated classes of characters ([^\\x41-\\x5A])' => ['pattern' => '/([^\x41-\x5A])/u', 'expected' => [true]];
        yield 'negated classes of characters ([^\\x{100}])' => ['pattern' => '/([^\x{100}])/u', 'expected' => [false]];
        yield 'negated classes of characters ([^ab])' => ['pattern' => '/([^ab])/u', 'expected' => [true]];
        yield 'negated classes of characters ([^\\p{Han}])' => ['pattern' => '/([^\p{Han}])/u', 'expected' => [false]];
        yield 'negated classes of types ([^\\d\\w\\s])' => ['pattern' => '/([^\d\w\s])/', 'expected' => [true]];
        yield 'negated classes of types ([^\\D])' => ['pattern' => '/([^\D])/', 'expected' => [true]];
        yield 'negated classes of types ([^\\W])' => ['pattern' => '/([^\W])/', 'expected' => [true]];
        yield 'negated classes of types ([^\\S])' => ['pattern' => '/([^\S])/', 'expected' => [true]];
        yield 'negated classes of types ([^\\h])' => ['pattern' => '/([^\h])/', 'expected' => [true]];
        yield 'negated classes of types ([^\\H])' => ['pattern' => '/([^\H])/', 'expected' => [true]];
        yield 'negated classes of types ([^\\v])' => ['pattern' => '/([^\v])/', 'expected' => [false]];
        yield 'negated classes of POSIX classes ([^[:alpha:]])' => ['pattern' => '/([^[:alpha:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:^digit:]])' => ['pattern' => '/([^[:^digit:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:punct:][:space:]])' => ['pattern' => '/([^[:punct:][:space:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:word:]])' => ['pattern' => '/([^[:word:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:xdigit:]])' => ['pattern' => '/([^[:xdigit:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:graph:]])' => ['pattern' => '/([^[:graph:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:print:]])' => ['pattern' => '/([^[:print:]])/', 'expected' => [false]];
        yield 'negated classes of POSIX classes ([^[:ascii:]])' => ['pattern' => '/([^[:ascii:]])/', 'expected' => [false]];
        yield 'negated classes of POSIX classes ([^[:alnum:]])' => ['pattern' => '/([^[:alnum:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:blank:]])' => ['pattern' => '/([^[:blank:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:lower:]])' => ['pattern' => '/([^[:lower:]])/', 'expected' => [true]];
        yield 'negated classes of POSIX classes ([^[:cntrl:]])' => ['pattern' => '/([^[:cntrl:]])/', 'expected' => [false]];
        yield 'properties (\\p{Lu})' => ['pattern' => '/(\p{Lu})/u', 'expected' => [true]];
        yield 'properties (\\p{Ll})' => ['pattern' => '/(\p{Ll})/u', 'expected' => [true]];
        yield 'properties (\\p{Nd})' => ['pattern' => '/(\p{Nd})/u', 'expected' => [true]];
        yield 'properties (\\p{Zs})' => ['pattern' => '/(\p{Zs})/u', 'expected' => [true]];
        yield 'properties (\\p{Any})' => ['pattern' => '/(\p{Any})/u', 'expected' => [true]];
        yield 'properties (\\P{L})' => ['pattern' => '/(\P{L})/u', 'expected' => [true]];
        yield 'properties (\\p{Latin})' => ['pattern' => '/(\p{Latin})/u', 'expected' => [true]];
        yield 'properties (\\p{Han})' => ['pattern' => '/(\p{Han})/u', 'expected' => [false]];
        yield 'negated classes of properties ([^\\p{Lu}])' => ['pattern' => '/([^\p{Lu}])/u', 'expected' => [true]];
        yield 'negated classes of properties ([^\\p{N}])' => ['pattern' => '/([^\p{N}])/u', 'expected' => [true]];
        yield 'bytes in a negated class ([^\\xE9-\\xFF])' => ['pattern' => "/([^\xE9-\xFF])/", 'expected' => [false]];
        yield 'bytes in a negated class ([^\\xE9])' => ['pattern' => "/([^\xE9])/", 'expected' => [false]];
        yield 'an alternative with no string first' => ['pattern' => '/(\1|a)b/', 'expected' => [true]];
        yield 'an empty alternative first' => ['pattern' => '/x(|a)y/', 'expected' => [true]];
        yield 'alternatives that read nothing' => ['pattern' => '/x(|\1)y/', 'expected' => [false]];
        yield 'nothing repeated before a starved group' => ['pattern' => '/a{0}\d+(\d*)/', 'expected' => [false]];
        yield 'a group repeated no time' => ['pattern' => '/(?:(a)){0}b/', 'expected' => [false]];
        yield 'a subject too long' => ['pattern' => '/a{5000}(b)/', 'expected' => [false]];
        yield 'a lookahead before the group' => ['pattern' => '/(?=a)(a)/', 'expected' => [true]];
        yield 'a group in a branch reset' => ['pattern' => '/(?|(a)|b)/', 'expected' => [false]];
    }

    #[Test]
    public function test_a_group_that_captures_nothing_is_never_shown_filled(): void
    {
        $this->assertSame([false, true], array_map(static fn (array $seen): bool => $seen['fills'], self::groupsOf('/(?:a)(b)/', true)));
        // "(?n)" on every PHP: PCRE2 reads it from 10.30 (php:8.1-cli, 10.39).
        // The setting is a group of its own, and fills nothing either.
        $this->assertSame([false, false], array_map(static fn (array $seen): bool => $seen['fills'], self::groupsOf('/(?n)(a)/', true)));
    }

    /**
     * The witness runs the pattern on the running PHP, which reads the n
     * modifier from 8.2.
     */
    #[Test]
    public function test_a_group_the_n_modifier_keeps_from_capturing_is_never_shown_filled(): void
    {
        $this->requiresPhpReading('/(a)/n');

        $this->assertSame([false], array_map(static fn (array $seen): bool => $seen['fills'], self::groupsOf('/(a)/n', true)));
    }

    /**
     * Wherever a subject shows the group filled, the automata do not prove
     * it always empty: each starved group below is proven so, and none of
     * them has a subject.
     */
    #[Test]
    public function test_no_group_the_automata_prove_empty_is_shown_filled(): void
    {
        mt_srand(20261019);
        $before = ['a+', '\w+', '[a-z]*', '\d{2,}', '(?:a|b)+', '.*', 'x', '', '^', '[a-c]+', 'a+?', '\s*'];
        $bodies = ['a*', '\d*', '[a-z]?', 'a', 'b*', '\w*', '[ab]*', 'x?', '.*', 'a|b'];
        $after = ['', 'b', '$', 'x', '\d', '(?:y|z)', 'a?', '\z'];
        $flags = ['', 'U', 'i', 'u', 's'];
        $proven = 0;
        $shown = 0;

        for ($i = 0; $i < 400; $i++) {
            $pattern = '/'.$before[mt_rand(0, \count($before) - 1)].'('.$bodies[mt_rand(0, \count($bodies) - 1)].')'.$after[mt_rand(0, \count($after) - 1)].'/'.$flags[mt_rand(0, \count($flags) - 1)];
            foreach (self::groupsOf($pattern) as $seen) {
                if ($seen['fills']) {
                    $shown++;
                    $this->assertNotTrue($seen['alwaysEmpty'], $pattern.' is shown filled, yet proven empty.');
                } elseif (true === $seen['alwaysEmpty']) {
                    $proven++;
                }
            }
        }

        $this->assertGreaterThan(20, $proven);
        $this->assertGreaterThan(200, $shown);
    }

    /**
     * What the witness and the automata say of each capturing group of the
     * pattern, or of each group, in order.
     *
     * @return list<array{fills: bool, alwaysEmpty: ?bool}>
     */
    private static function groupsOf(string $pattern, bool $everyGroup = false): array
    {
        $rule = new class($everyGroup) extends AbstractLintRule {
            public function __construct(private readonly bool $everyGroup) {}

            public function getRuleIds(): array
            {
                return ['test.witness'];
            }

            public function getNodeTypes(): array
            {
                return [GroupNode::class];
            }

            public function check(NodeInterface $node, LintContext $context): array
            {
                if (!$node instanceof GroupNode || (!$this->everyGroup && !\in_array($node->type, [GroupType::Capturing, GroupType::Named], true))) {
                    return [];
                }

                $fills = CaptureWitness::fills($context, $node);
                $same = LanguageQuestions::matchTheSameFromAnyOffset($context, $node->child->getStartPosition(), $node->child->getEndPosition(), '');

                return [new RuleViolation('test.witness', json_encode([$fills, $same], \JSON_THROW_ON_ERROR))];
            }
        };
        $registry = new LintRuleRegistry();
        $registry->register($rule);

        $linter = PatternLinter::withRegistry([], $registry);
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $seen = [];
        foreach ($linter->getIssues() as $issue) {
            if ('test.witness' === $issue->id) {
                /** @var array{bool, ?bool} $answers */
                $answers = json_decode($issue->message, true, 512, \JSON_THROW_ON_ERROR);
                $seen[] = ['fills' => $answers[0], 'alwaysEmpty' => $answers[1]];
            }
        }

        return $seen;
    }
}
