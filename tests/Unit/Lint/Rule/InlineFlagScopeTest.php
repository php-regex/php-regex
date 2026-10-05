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
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An inline flag set in one alternative stays set in the alternatives after
 * it, up to the end of the group that holds them, as PCRE compiles it: the
 * rules read the same flags at each node.
 */
final class InlineFlagScopeTest extends TestCase
{
    /**
     * Each row: the pattern, a subject, whether the engine matches it, the
     * flag, and whether the rules see that flag at the dot or "$".
     *
     * @return iterable<string, array{pattern: string, subject: string, matches: int, flag: string, active: bool}>
     */
    public static function provideFlagScopes(): iterable
    {
        yield 's set in an earlier alternative' => ['pattern' => '/x(?s)|a.b/', 'subject' => "a\nb", 'matches' => 1, 'flag' => 's', 'active' => true];
        yield 'm set in an earlier alternative' => ['pattern' => '/x(?m)|a$/', 'subject' => "a\nb", 'matches' => 1, 'flag' => 'm', 'active' => true];
        yield 's restored after the group' => ['pattern' => '/(?:x(?s)|y)a.b/', 'subject' => "ya\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 's restored after the group, its own alternative taken' => ['pattern' => '/(?:x(?s)|y)a.b/', 'subject' => "xa\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 'm restored after the group' => ['pattern' => '/(?:x(?m)|y)a$/', 'subject' => "ya\nb", 'matches' => 0, 'flag' => 'm', 'active' => false];
        // A flag turned off in a later alternative stays off in the next.
        yield 's turned off in a middle alternative' => ['pattern' => '/a(?s)b|c(?-s)d|e.f/', 'subject' => "e\nf", 'matches' => 0, 'flag' => 's', 'active' => false];
        // A conditional's yes branch carries into its no branch.
        yield 's set in the yes branch of a conditional' => ['pattern' => '/(?(?=x)x(?s)|a.b)/', 'subject' => "a\nb", 'matches' => 1, 'flag' => 's', 'active' => true];
        // A branch reset and an atomic group carry like any group.
        yield 's set in a branch reset alternative' => ['pattern' => '/(?|x(?s)|a.b)/', 'subject' => "a\nb", 'matches' => 1, 'flag' => 's', 'active' => true];
        yield 's set in an atomic group alternative' => ['pattern' => '/(?>x(?s)|a.b)/', 'subject' => "a\nb", 'matches' => 1, 'flag' => 's', 'active' => true];
        // The flags come back at the end of every group kind.
        yield 's restored after a branch reset' => ['pattern' => '/(?|x(?s)|y)a.b/', 'subject' => "ya\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 's restored after an atomic group' => ['pattern' => '/(?>x(?s)|y)a.b/', 'subject' => "ya\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 's restored after DEFINE' => ['pattern' => '/(?(DEFINE)(?<d>(?s)x))a.b/', 'subject' => "a\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 's restored after a script run' => ['pattern' => '/(*sr:(?s)x)a.b/', 'subject' => "xa\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 's restored after a lookahead' => ['pattern' => '/(?=(?s)a)a.b/', 'subject' => "a\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        // A flag set inside a conditional, or directly in a DEFINE body,
        // ends with it.
        yield 's restored after a conditional' => ['pattern' => '/(?(?=x)x(?s)|y)a.b/', 'subject' => "ya\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 's restored after a DEFINE body holding only the flag' => ['pattern' => '/(?(DEFINE)(?s))a.b/', 'subject' => "a\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 's restored after a DEFINE body ending with the flag' => ['pattern' => '/(?(DEFINE)x(?s))a.b/', 'subject' => "a\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        // An empty scoped group sets its flags inside itself
        // only, where nothing follows; the standalone form sets them up to
        // the end of the enclosing group.
        yield 'empty scoped (?s:) leaves s off after it' => ['pattern' => '/(?s:)a.b/', 'subject' => "a\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 'standalone (?s) turns s on after it' => ['pattern' => '/(?s)a.b/', 'subject' => "a\nb", 'matches' => 1, 'flag' => 's', 'active' => true];
        yield 'empty scoped (?-s:) leaves s on after it' => ['pattern' => '/(?-s:)a.b/s', 'subject' => "a\nb", 'matches' => 1, 'flag' => 's', 'active' => true];
        yield 'empty scoped (?s:) in an earlier alternative' => ['pattern' => '/x(?s:)|a.b/', 'subject' => "a\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        // "(?^)" resets i, m, n, s and x; the u and D of the
        // modifiers stay: "\x{e9}" still reads U+00E9 under u (without u it
        // is the byte 0xE9, which "é" does not hold), "$" still refuses the
        // final "\n" under D.
        yield 's reset by (?^)' => ['pattern' => '/(?^)a.b/s', 'subject' => "a\nb", 'matches' => 0, 'flag' => 's', 'active' => false];
        yield 'u kept by (?^)' => ['pattern' => '/(?^)\x{e9}$/u', 'subject' => 'é', 'matches' => 1, 'flag' => 'u', 'active' => true];
        yield 'D kept by (?^)' => ['pattern' => '/(?^)a$/D', 'subject' => "a\n", 'matches' => 0, 'flag' => 'D', 'active' => true];
    }

    /**
     * "(?^)" keeps the U of the modifiers. The quantifier
     * stays lazy after it: "a+." takes "aa" of "aaa", where greedy it would
     * take all three.
     */
    #[Test]
    public function test_the_ungreedy_modifier_survives_a_caret_reset(): void
    {
        $this->assertSame(1, preg_match('/(?^)a+./U', 'aaa', $matches));
        $this->assertSame('aa', $matches[0]);
        $this->assertSame(1, preg_match('/(?^)a+./', 'aaa', $matches));
        $this->assertSame('aaa', $matches[0]);

        $seen = self::flagsSeenAtDotsAndAnchors('/(?^)a+./U');
        $this->assertNotSame([], $seen);

        foreach ($seen as $flags) {
            $this->assertStringContainsString('U', $flags);
        }
    }

    /**
     * An i already carried in from an earlier alternative makes a second
     * (?i) redundant: "/x(?i)|yZ/" matches "yz". Without the first one, or
     * with it scoped to its own group, the second is needed.
     *
     * @return iterable<string, array{pattern: string, withoutSecond: string, redundant: bool}>
     */
    public static function provideSecondCaselessFlags(): iterable
    {
        yield 'i carried from an earlier alternative' => ['pattern' => '/x(?i)|y(?i)z/', 'withoutSecond' => '/x(?i)|yZ/', 'redundant' => true];
        yield 'no i before' => ['pattern' => '/x|y(?i)z/', 'withoutSecond' => '/x|yZ/', 'redundant' => false];
        yield 'i scoped to its own group' => ['pattern' => '/(?:x(?i))|y(?i)z/', 'withoutSecond' => '/(?:x(?i))|yZ/', 'redundant' => false];
    }

    #[Test]
    #[DataProvider('provideSecondCaselessFlags')]
    public function test_a_flag_carried_from_an_earlier_alternative_is_redundant(string $pattern, string $withoutSecond, bool $redundant): void
    {
        $this->assertSame($redundant ? 1 : 0, preg_match($withoutSecond, 'yz'));

        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($redundant, \in_array('regex.lint.flag.redundant', $ids, true), $pattern);
    }

    #[Test]
    #[DataProvider('provideFlagScopes')]
    public function test_flags_carry_across_alternatives_up_to_the_group_end(string $pattern, string $subject, int $matches, string $flag, bool $active): void
    {
        $this->assertSame($matches, preg_match($pattern, $subject));

        $seen = self::flagsSeenAtDotsAndAnchors($pattern);
        $this->assertNotSame([], $seen);

        foreach ($seen as $flags) {
            $this->assertSame($active, str_contains($flags, $flag), \sprintf('%s: flags "%s".', $pattern, $flags));
        }
    }

    /**
     * @return list<string>
     */
    private static function flagsSeenAtDotsAndAnchors(string $pattern): array
    {
        $rule = new class extends AbstractLintRule {
            public function getRuleIds(): array
            {
                return ['test.flags'];
            }

            public function getNodeTypes(): array
            {
                return [DotNode::class, AnchorNode::class];
            }

            public function check(NodeInterface $node, LintContext $context): array
            {
                return [new RuleViolation('test.flags', $context->activeFlags())];
            }
        };
        $registry = new LintRuleRegistry();
        $registry->register($rule);

        $linter = new PatternLinter([], $registry);
        Regex::create()->parse($pattern)->accept($linter);

        return array_values(array_map(
            static fn ($issue): string => $issue->message,
            array_filter($linter->getIssues(), static fn ($issue): bool => 'test.flags' === $issue->id),
        ));
    }
}
