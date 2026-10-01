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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\TextExplainer;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComplexityScoreVisitorTest extends TestCase
{
    private Regex $regex;

    private ComplexityScorer $visitor;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->visitor = new ComplexityScorer();
    }

    public function test_simple_regex_score(): void
    {
        // abc = 1 (seq) + 1 + 1 + 1 = 4 (or close, depends on your exact base logic)
        $score = $this->getScore('/abc/');
        $this->assertLessThan(10, $score);
    }

    public function test_high_complexity_score(): void
    {
        // Classic ReDoS: (a+)+
        // Your logic should multiply the score exponentially for nested quantifiers
        $score = $this->getScore('/(a+)+/');

        $this->assertGreaterThan(20, $score, 'Nested quantifiers should yield a high complexity score');
    }

    public function test_lookarounds_increase_score(): void
    {
        $simple = $this->getScore('/foo/');
        $complex = $this->getScore('/(?=foo)foo/');

        $this->assertGreaterThan($simple, $complex);
    }

    public static function data_provider_quantifier_explanations(): \Iterator
    {
        yield 'greedy_star' => ['/a*/', 'zero or more times'];
        yield 'lazy_plus' => ['/a+?/', 'one or more times (as few as possible)'];
        yield 'possessive_optional' => ['/a?+/', 'once or not at all (and do not backtrack)'];
        yield 'fixed_exact' => ['/a{5}/', 'exactly 5 times'];
        yield 'fixed_range_unbounded' => ['/a{5,}/', 'at least 5 times'];
        yield 'fixed_range_bounded_lazy' => ['/a{1,2}?/', 'at least 1 but not more than 2 times (as few as possible)'];
    }

    #[DataProvider('data_provider_quantifier_explanations')]
    public function test_quantifier_explanations(string $pattern, string $expectedQuantifierText): void
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new TextExplainer();
        $output = $ast->accept($visitor);
        $this->assertStringContainsString($expectedQuantifierText, $output);
    }

    public function test_group_types_explanations(): void
    {
        $regex = Regex::create();

        $this->assertStringContainsString(
            'Positive lookbehind',
            $regex->parse('/(?<=a)/')->accept(new TextExplainer()),
        );
        $this->assertStringContainsString(
            'Atomic group (no backtracking)',
            $regex->parse('/(?>a)/')->accept(new TextExplainer()),
        );
        $this->assertStringContainsString(
            "Capturing group (named: 'id')",
            $regex->parse('/(?<id>a)/')->accept(new TextExplainer()),
        );
    }

    public function test_literal_special_character_explanations(): void
    {
        $regex = Regex::create();
        $visitor = new TextExplainer();

        $this->assertStringContainsString("' ' (space)", $regex->parse('/ /')->accept($visitor));
        $this->assertStringContainsString("'\\t' (tab)", $regex->parse('/\\t/')->accept($visitor));
        $this->assertStringContainsString("'\\n' (newline)", $regex->parse('/\\n/')->accept($visitor));
        // Non-printable characters are rendered using a hex-based explanation.
        $this->assertStringContainsString('Character with hexadecimal value 0x01', $regex->parse('/\\x01/')->accept($visitor));
    }

    public function test_score_nested_unbounded_quantifiers_redo_penalty(): void
    {
        // Classic ReDoS: (a*)*. Score should be exponentially high.
        // Outer quantifier depth=1. Inner quantifier depth=2.
        // Expected: (Base Score + Quantified Node Score * (NESTING_MULTIPLIER * depth))
        $score = $this->getScore('/(a*)*/');

        $this->assertGreaterThan(30, $score, 'Nested unbounded quantifiers must incur high penalty (ReDoS).');

        // Triple nested: ((a*)*)*
        $tripleScore = $this->getScore('/((a*)*)*/');
        $this->assertGreaterThan($score, $tripleScore);
    }

    public function test_score_complex_constructs(): void
    {
        // Conditional (COMPLEX_CONSTRUCT_SCORE * 2) + children
        $conditionalScore = $this->getScore('/(?(1)a|b)/');
        $this->assertGreaterThan(10, $conditionalScore, 'Conditional must be highly complex.');

        // Subroutine (COMPLEX_CONSTRUCT_SCORE * 2)
        $subroutineScore = $this->getScore('/(?R)/');
        $this->assertSame(10, $subroutineScore, 'Subroutine must be highly complex (10).');

        // PcreVerb (COMPLEX_CONSTRUCT_SCORE)
        $pcreVerbScore = $this->getScore('/(*FAIL)/');
        $this->assertSame(5, $pcreVerbScore, 'PcreVerb must be complex (5).');
    }

    public function test_score_version_define_and_limit_match_nodes(): void
    {
        $visitor = new ComplexityScorer();
        $ref = new \ReflectionClass(ComplexityScorer::class);
        $complexScore = $ref->getConstant('COMPLEX_CONSTRUCT_SCORE');
        $baseScore = $ref->getConstant('BASE_SCORE');
        $this->assertIsInt($complexScore);
        $this->assertIsInt($baseScore);

        $version = new VersionConditionNode('>=', '10.0', 0, 0);
        $this->assertSame($complexScore, $version->accept($visitor));

        $define = new DefineNode(new LiteralNode('a', 0, 0), 0, 0);
        $this->assertSame($complexScore + $baseScore, $define->accept($visitor));

        $limitMatch = new LimitMatchNode(5, 0, 0);
        $this->assertSame($complexScore, $limitMatch->accept($visitor));
    }

    public function test_score_complex_group_lookbehinds(): void
    {
        // Negative lookbehind (?<!a) is COMPLEX_CONSTRUCT_SCORE
        $score = $this->getScore('/a(?<!b)/'); // Sequence(Literal(a), Group(b))
        $this->assertGreaterThan(5, $score);
        $this->assertLessThan(10, $score);
    }

    public function test_nested_quantifiers_exponential_score(): void
    {
        // Hits the logic: if ($this->quantifierDepth > 0) { $score *= ... }
        // (a+)+
        $regex = '/(a+)+/';
        $ast = $this->regex->parse($regex);
        $score = $ast->accept($this->visitor);

        // Base(1) + Quantifier(10) + Group(1) + Quantifier(10 * 2) = ~32
        $this->assertGreaterThan(30, $score);
    }

    public function test_complex_constructs_scores(): void
    {
        // Conditionals
        $this->assertGreaterThan(10, $this->getScore('/(?(1)a|b)/'));

        // Subroutines
        $this->assertGreaterThanOrEqual(10, $this->getScore('/(?R)/'));

        // PCRE Verbs
        $this->assertGreaterThan(1, $this->getScore('/(*FAIL)/'));

        // Backreferences
        $this->assertGreaterThanOrEqual(5, $this->getScore('/\1/'));
    }

    public function test_lookarounds_score(): void
    {
        // Hits logic in visitGroup checking for LOOKAHEAD/LOOKBEHIND types
        $scoreNormal = $this->getScore('/(a)/');
        $scoreLookahead = $this->getScore('/(?=a)/');

        $this->assertGreaterThan($scoreNormal, $scoreLookahead);
    }

    public function test_char_class_score(): void
    {
        // Score is sum of parts
        $score = $this->getScore('/[abc]/');
        // Base(1) + Literal(1)*3 = 4
        $this->assertSame(4, $score);
    }

    private function getScore(string $regex): int
    {
        $ast = $this->regex->parse($regex);

        return $ast->accept($this->visitor);
    }
}
