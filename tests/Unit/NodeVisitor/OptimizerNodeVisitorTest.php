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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptimizerNodeVisitorTest extends TestCase
{
    private Regex $regex;

    private Rewriter $optimizer;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->optimizer = new Rewriter();
    }

    public function test_merge_adjacent_literals(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/abc/');
        $optimizer = new Rewriter();

        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $newAst);
        $this->assertInstanceOf(LiteralNode::class, $newAst->pattern);
        $this->assertSame('abc', $newAst->pattern->value);
    }

    public function test_flatten_alternations(): void
    {
        // We use long strings ("beta", "gamma") to prevent
        // the optimizer from converting (b|c) into [bc] (CharClass).
        // We specifically want to test the merging of AlternationNode.

        $nestedAlt = new AlternationNode([
            new LiteralNode('beta', 0, 0),
            new LiteralNode('gamma', 0, 0),
        ], 0, 0);

        $rootAlt = new AlternationNode([
            new LiteralNode('alpha', 0, 0),
            $nestedAlt,
            new LiteralNode('delta', 0, 0),
        ], 0, 0);

        $optimizer = new Rewriter();

        /** @var AlternationNode $newAst */
        $newAst = $rootAlt->accept($optimizer);

        // The optimizer should have "lifted" beta and gamma to the root level -> 4 alternatives
        $this->assertCount(4, $newAst->alternatives);
        $this->assertInstanceOf(LiteralNode::class, $newAst->alternatives[1]);
        $this->assertSame('beta', $newAst->alternatives[1]->value);
    }

    public function test_alternation_to_char_class_optimization(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/a|b|c/');
        $optimizer = new Rewriter();

        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $newAst);
        $this->assertInstanceOf(CharClassNode::class, $newAst->pattern);
        $this->assertInstanceOf(AlternationNode::class, $newAst->pattern->expression);
        $this->assertCount(3, $newAst->pattern->expression->alternatives);
    }

    public function test_digit_optimization(): void
    {
        // Logic: [0-9] -> \d
        $ast = $this->regex->parse('/[0-9]/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharTypeNode::class, $optimized->pattern);
        $this->assertSame('d', $optimized->pattern->value);
    }

    public function test_remove_useless_non_capturing_group(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?:abc)/');
        $optimizer = new Rewriter();

        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $newAst);
        $this->assertInstanceOf(LiteralNode::class, $newAst->pattern);
        $this->assertSame('abc', $newAst->pattern->value);
    }

    public function test_quantifier_optimization(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?:a)*/');
        $optimizer = new Rewriter();

        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $newAst);
        $this->assertInstanceOf(QuantifierNode::class, $newAst->pattern);
        $this->assertInstanceOf(LiteralNode::class, $newAst->pattern->node);
    }

    public function test_optimization_does_not_break_semantics_with_hyphen(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/a|-|z/');
        $optimizer = new Rewriter();

        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $newAst);
        $this->assertInstanceOf(AlternationNode::class, $newAst->pattern);
        $this->assertCount(3, $newAst->pattern->alternatives);
    }

    public function test_merge_adjacent_literals_and_sequences(): void
    {
        // Pattern: /a(b)(c)(d(e)f)/
        // Should be optimized to: /a b c d e f/ (LiteralNode)

        $optimizer = new Rewriter();

        // Simulate a more complex AST to test the merging of adjacent LiteralNodes
        $rawAst = new RegexNode(
            new SequenceNode([
                new LiteralNode('a', 0, 1),
                new LiteralNode('b', 1, 2),
                new SequenceNode([ // Nested sequence
                    new LiteralNode('c', 2, 3),
                    new LiteralNode('d', 3, 4),
                ], 2, 4),
                new LiteralNode('e', 4, 5),
            ], 0, 5),
            '', '/', 0, 5,
        );

        $optimizedAst = $rawAst->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimizedAst);
        $this->assertInstanceOf(LiteralNode::class, $optimizedAst->pattern);
        $this->assertSame(
            'abcde',
            $optimizedAst->pattern->value,
            'Adjacent literals and flattened sequence should merge.',
        );
    }

    public function test_remove_empty_literal_from_sequence(): void
    {
        // Sequence containing an empty node (which could come from an empty group)
        $rawAst = new RegexNode(
            new SequenceNode([
                new LiteralNode('x', 0, 1),
                new LiteralNode('', 1, 1), // Empty node to remove
                new LiteralNode('y', 1, 2),
            ], 0, 2),
            '', '/', 0, 2,
        );

        $optimizer = new Rewriter();
        $optimizedAst = $rawAst->accept($optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimizedAst);
        $this->assertInstanceOf(LiteralNode::class, $optimizedAst->pattern);
        $this->assertSame('xy', $optimizedAst->pattern->value, 'Empty literal should be removed and remaining merged.');
    }

    public function test_alternation_to_char_class_with_hyphen_as_literal(): void
    {
        // a|-|z should NOT become [a-z] because the '-' is a literal, not part of a range.
        $regex = Regex::create();
        $ast = $regex->parse('/a|-|z/');
        $optimizer = new Rewriter();

        $newAst = $ast->accept($optimizer);

        // The result should remain an AlternationNode if optimization to CharClass fails
        $this->assertInstanceOf(RegexNode::class, $newAst);
        $this->assertInstanceOf(AlternationNode::class, $newAst->pattern);
        $this->assertCount(3, $newAst->pattern->alternatives);
    }

    public function test_char_class_deduplicates_literals_and_merges_ranges(): void
    {
        $ast = $this->regex->parse('/[aabbccdd]/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern);
        /** @var CharClassNode $charClass */
        $charClass = $optimized->pattern;
        $this->assertInstanceOf(RangeNode::class, $charClass->expression);
        $range = $charClass->expression;
        $this->assertInstanceOf(LiteralNode::class, $range->start);
        $this->assertInstanceOf(LiteralNode::class, $range->end);
        $this->assertSame('a', $range->start->value);
        $this->assertSame('d', $range->end->value);
    }

    public function test_char_class_merges_touching_ranges_and_literals(): void
    {
        $ast = $this->regex->parse('/[a-cd-fh]/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern);
        /** @var CharClassNode $charClass */
        $charClass = $optimized->pattern;
        $this->assertInstanceOf(AlternationNode::class, $charClass->expression);
        $this->assertCount(2, $charClass->expression->alternatives);

        $range1 = $charClass->expression->alternatives[0];
        $this->assertInstanceOf(RangeNode::class, $range1);
        $this->assertInstanceOf(LiteralNode::class, $range1->start);
        $this->assertInstanceOf(LiteralNode::class, $range1->end);
        $this->assertSame('a', $range1->start->value);
        $this->assertSame('f', $range1->end->value);

        $last = $charClass->expression->alternatives[1];
        $this->assertInstanceOf(LiteralNode::class, $last);
        $this->assertSame('h', $last->value);
    }

    // ... (tests existants: test_merge_adjacent_literals, test_flatten_alternations, test_alternation_to_char_class_optimization, test_digit_optimization, test_remove_useless_non_capturing_group, test_quantifier_optimization, test_optimization_does_not_break_semantics_with_hyphen)

    public function test_return_original_node_if_no_change_in_regex_node(): void
    {
        $pattern = new LiteralNode('a', 0, 1);
        $originalAst = new RegexNode($pattern, 'i', '/', 0, 3);
        $optimizer = new Rewriter();

        // Le pattern est un LiteralNode simple, l'optimizer ne fait rien dessus.
        $optimizedAst = $originalAst->accept($optimizer);

        $this->assertSame(
            $originalAst,
            $optimizedAst,
            'Should return the same object instance if pattern is unchanged.',
        );
    }

    public function test_char_class_word_optimization_unicode_flag_present(): void
    {
        // [a-zA-Z0-9_] -> \w, but NOT if the 'u' flag is present
        $regex = Regex::create();
        $ast = $regex->parse('/[a-zA-Z0-9_]+/u'); // 'u' flag is present
        $optimizer = new Rewriter();

        $optimizedAst = $ast->accept($optimizer);

        // Le pattern devrait rester CharClassNode, pas CharTypeNode('\w')
        $this->assertInstanceOf(RegexNode::class, $optimizedAst);
        $this->assertInstanceOf(QuantifierNode::class, $optimizedAst->pattern);
        $this->assertInstanceOf(
            CharClassNode::class,
            $optimizedAst->pattern->node,
            'Should not optimize to \w due to /u flag.',
        );
    }

    public function test_full_word_optimization(): void
    {
        // [a-zA-Z0-9_] -> \w
        $regex = Regex::create();
        $ast = $regex->parse('/[a-zA-Z0-9_]/');
        $optimizer = new Rewriter();

        /** @var RegexNode $newAst */
        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(CharTypeNode::class, $newAst->pattern);
        $this->assertSame('w', $newAst->pattern->value);
    }

    public function test_does_not_optimize_word_if_flag_u_present(): void
    {
        // [a-zA-Z0-9_] -> NOT \w if /u is present (semantics change in PCRE)
        $regex = Regex::create();
        $ast = $regex->parse('/[a-zA-Z0-9_]/u');
        $optimizer = new Rewriter();

        /** @var RegexNode $newAst */
        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(CharClassNode::class, $newAst->pattern);
    }

    public function test_merges_nested_sequences(): void
    {
        // Sequence(Sequence(a, b), c) -> Sequence(a, b, c) -> Literal(abc)
        $inner = new SequenceNode([
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0)
        ], 0, 0);

        $outer = new SequenceNode([
            $inner,
            new LiteralNode('c', 0, 0)
        ], 0, 0);

        $optimizer = new Rewriter();
        $result = $outer->accept($optimizer);

        $this->assertInstanceOf(LiteralNode::class, $result);
        $this->assertSame('abc', $result->value);
    }

    public function test_alternation_single_char_optimization(): void
    {
        // a|b|c -> [abc]
        $regex = Regex::create();
        $ast = $regex->parse('/a|b|c/');
        $optimizer = new Rewriter();

        /** @var RegexNode $newAst */
        $newAst = $ast->accept($optimizer);

        $this->assertInstanceOf(CharClassNode::class, $newAst->pattern);
    }

    public function test_alternation_optimization_skips_meta_chars(): void
    {
        // a|^|c -> Should NOT become [a^c] because ^ is meta in char class start
        // Ideally the optimizer is smart enough to know ^ is safe in middle,
        // but your logic strictly avoids meta chars.
        $regex = Regex::create();
        $ast = $regex->parse('/a|^|c/'); // ^ is anchor here
        $optimizer = new Rewriter();

        /** @var RegexNode $newAst */
        $newAst = $ast->accept($optimizer);

        // Should remain alternation because ^ was an AnchorNode in parse,
        // or if parsed as literals, the optimizer check prevents it.
        // Wait, parse('/a|^|c/') -> Alternation(Literal(a), Anchor(^), Literal(c)).
        // canAlternationBeCharClass checks for LiteralNode. AnchorNode returns false.
        // Correct behavior: No optimization.
        $this->assertNotInstanceOf(CharClassNode::class, $newAst->pattern);
    }

    public function test_optimize_non_capturing_group_with_char_class(): void
    {
        // Logic: (?:[abc]) -> [abc]
        $ast = $this->regex->parse('/(?:[abc])/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        // Should unwrap the group and return just the CharClassNode
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern);
    }

    public function test_full_word_class_optimization_success(): void
    {
        // Logic: [a-zA-Z0-9_] -> \w
        $ast = $this->regex->parse('/[a-zA-Z0-9_]/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharTypeNode::class, $optimized->pattern);
        $this->assertSame('w', $optimized->pattern->value);
    }

    public function test_full_word_class_optimization_missing_underscore(): void
    {
        // Logic: [a-zA-Z0-9] (missing _) -> Should NOT optimize to \w
        $ast = $this->regex->parse('/[a-zA-Z0-9]/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern);
    }

    public function test_full_word_class_optimization_missing_range(): void
    {
        // Logic: [a-z0-9_] (missing A-Z) -> Should NOT optimize to \w
        $ast = $this->regex->parse('/[a-z0-9_]/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern);
    }

    public function test_digit_optimization_fails_on_wrong_range(): void
    {
        // Logic: [1-9] -> NOT \d
        $ast = $this->regex->parse('/[1-9]/');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern);
    }

    public function test_digit_optimization_unicode_flag(): void
    {
        // [0-9] with u flag should NOT optimize to \d, remain as CharClass
        $ast = $this->regex->parse('/[0-9]/u');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern);
    }

    public function test_digit_optimization_unicode_flag_complex(): void
    {
        // [0-9]+ with u flag should remain as is, not optimize [0-9] to \d
        $ast = $this->regex->parse('/[0-9]+/u');
        $optimized = $ast->accept($this->optimizer);

        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(QuantifierNode::class, $optimized->pattern);
        // The quantifier should have a CharClassNode as node
        $this->assertInstanceOf(CharClassNode::class, $optimized->pattern->node);
    }

    public function test_alternation_flattening(): void
    {
        // Logic: (a|b)|c -> a|b|c
        // Note: parser naturally produces flat alternations for a|b|c,
        // so we force structure via groups: (a|b)|c
        // Optimizing once might just remove the group.
        // We are testing the logic inside visitAlternation checking instanceof AlternationNode

        // Manually construct nested alternation if needed, but parser usually handles it.
        // Let's try:
        $ast = $this->regex->parse('/(?:a|b)|c/');
        $optimized = $ast->accept($this->optimizer);

        // The optimizer keeps (?:a|b) as a group because it contains an alternation.
        // Non-capturing groups are only unwrapped for simple nodes, not alternations.
        // Result: Group(a|b) | c = 2 alternatives
        $this->assertInstanceOf(RegexNode::class, $optimized);
        $this->assertInstanceOf(AlternationNode::class, $optimized->pattern);
        $this->assertCount(2, $optimized->pattern->alternatives);
    }

    #[DataProvider('optimizationProvider')]
    public function test_optimizations_with_safety_checks(string $input, string $expected): void
    {
        $ast = $this->regex->parse($input);
        $optimized = $ast->accept($this->optimizer);

        // We compare the string representation to verify semantic equivalence
        // Note: The AST structure checks are implicit via the string output
        $compiler = new PatternPrinter();
        $this->assertSame($expected, $optimized->accept($compiler));
    }

    public static function optimizationProvider(): \Iterator
    {
        // --- CASE 1: The Matomo Bug (Sequence + Quantifier) ---
        // Unwrapping here changes semantics: (a then b)? vs a then (b?)
        yield 'Matomo Case' => ['/(?:, )?/', '/(?:, )?/'];
        yield 'Sequence with Quantifier' => ['/(?:abc)?/', '/(?:abc)?/'];
        // --- CASE 2: Valid Optimization (Sequence NO Quantifier) ---
        // Unwrapping here is SAFE and DESIRED
        yield 'Sequence No Quantifier' => ['/(?:abc)/', '/abc/'];
        yield 'Sequence No Quantifier 2' => ['/(?:, )/', '/, /'];
        // --- CASE 3: Alternation ---
        // Unwrapping safe if no quantifier, but optimizer turns to char class
        yield 'Alternation No Quantifier' => ['/(?:a|b)/', '/[ab]/'];
        // Unwrapping UNSAFE if quantifier
        yield 'Alternation With Quantifier' => ['/(?:a|b)+/', '/(?:[ab])+/'];
        // --- CASE 4: Atomic Nodes (Always Safe) ---
        // Single char/class can always be unwrapped, even with quantifier
        yield 'Atomic Char With Quantifier' => ['/(?:a)?/', '/a?/'];
        yield 'Atomic Class With Quantifier' => ['/(?:[a-z])*/', '/[a-z]*/'];
        yield 'Atomic Dot With Quantifier' => ['/(?:.)+/', '/.+/'];
        // --- CASE 5: Nested Groups ---
        // Inner group stays because in quantified context
        yield 'Nested Sequence' => ['/(?:(?:abc))?/', '/(?:(?:abc))?/'];
    }

    public function test_optimizations_can_be_disabled(): void
    {
        $regex = Regex::create();
        $compiler = new PatternPrinter();

        // Test disabling digits optimization
        $ast = $regex->parse('/[0-9]/');
        $optimizerDisabled = new Rewriter(optimizeDigits: false);
        $optimizedDisabled = $ast->accept($optimizerDisabled);
        $resultDisabled = $optimizedDisabled->accept($compiler);
        $this->assertSame('/[0-9]/', $resultDisabled, 'Digits optimization should be disabled');

        $optimizerEnabled = new Rewriter(optimizeDigits: true);
        $optimizedEnabled = $ast->accept($optimizerEnabled);
        $resultEnabled = $optimizedEnabled->accept($compiler);
        $this->assertSame('/\d/', $resultEnabled, 'Digits optimization should work when enabled');

        // Test disabling word optimization
        $ast2 = $regex->parse('/[a-zA-Z0-9_]/');
        $optimizerWordDisabled = new Rewriter(optimizeWord: false);
        $optimizedWordDisabled = $ast2->accept($optimizerWordDisabled);
        $resultWordDisabled = $optimizedWordDisabled->accept($compiler);
        $this->assertNotSame('/\w/', $resultWordDisabled, 'Word optimization should be disabled');
        $this->assertStringStartsWith('/[', $resultWordDisabled, 'Should remain as char class');

        $optimizerWordEnabled = new Rewriter(optimizeWord: true);
        $optimizedWordEnabled = $ast2->accept($optimizerWordEnabled);
        $resultWordEnabled = $optimizedWordEnabled->accept($compiler);
        $this->assertSame('/\w/', $resultWordEnabled, 'Word optimization should work when enabled');
    }

    public function test_strict_ranges_option(): void
    {
        $regex = Regex::create();
        $compiler = new PatternPrinter();

        // Test strict ranges (default): prevent merging different categories
        $ast = $regex->parse('/[0-9:]/');
        $optimizerStrict = new Rewriter(ranges: true);
        $optimizedStrict = $ast->accept($optimizerStrict);
        $resultStrict = $optimizedStrict->accept($compiler);
        // Should remain [0-9:] or equivalent, not [0-: ]
        $this->assertStringStartsWith('/[', $resultStrict);
        $this->assertStringEndsWith(']/', $resultStrict);
        $this->assertNotSame('/[0-:]/', $resultStrict, 'Strict ranges should not merge digits and symbols');

        // Test loose ranges: allow merging different categories
        $optimizerLoose = new Rewriter(ranges: false);
        $optimizedLoose = $ast->accept($optimizerLoose);
        $resultLoose = $optimizedLoose->accept($compiler);
        $this->assertSame('/[0-:]/', $resultLoose, 'Loose ranges should merge digits and symbols');
    }

    public function test_optimizer_does_not_fill_gaps(): void
    {
        $regex = Regex::create();
        $compiler = new PatternPrinter();

        // Test that gaps are not filled: [!#] should not become [!-\#]
        $ast = $regex->parse('/[!#]/');
        $optimizer = new Rewriter();
        $optimized = $ast->accept($optimizer);
        $result = $optimized->accept($compiler);
        $this->assertSame('/[!#]/', $result, 'Should not create ranges that fill gaps');

        // Test valid contiguous range is preserved
        $ast2 = $regex->parse('/[A-C]/');
        $optimized2 = $ast2->accept($optimizer);
        $result2 = $optimized2->accept($compiler);
        $this->assertSame('/[A-C]/', $result2, 'Valid contiguous ranges should be preserved');

        // Test non-contiguous characters remain separate
        $ast3 = $regex->parse('/[ABD]/');
        $optimized3 = $ast3->accept($optimizer);
        $result3 = $optimized3->accept($compiler);
        $this->assertSame('/[ABD]/', $result3, 'Non-contiguous characters should not be merged into ranges');
    }

    public function test_optimizer_handles_short_hex_escapes(): void
    {
        $regex = Regex::create();
        $compiler = new PatternPrinter();

        // Test short hex escapes in character class
        $ast = $regex->parse('/[\x9\xA\xD]/');
        $optimizer = new Rewriter();
        $optimized = $ast->accept($optimizer);
        $result = $optimized->accept($compiler);

        // Should represent tab, LF, CR - not '9ADx'
        // The output format may vary (\t\n\r or \x09\x0A\x0D), but should not be literals
        $this->assertStringStartsWith('/[', $result);
        $this->assertStringEndsWith(']/', $result);
        $this->assertNotSame('/[9ADx]/', $result, 'Should not output as literal characters');
        $this->assertStringNotContainsString('9ADx', $result, 'Should not contain the literal string 9ADx');
    }

    public function test_optimizer_handles_alternation_with_empty_branch(): void
    {
        $regex = Regex::create();
        $compiler = new PatternPrinter();

        // Test that alternation with empty branch is not merged into char class
        $ast = $regex->parse('/^(\+|)\d+$/');
        $optimizer = new Rewriter();
        $optimized = $ast->accept($optimizer);
        $result = $optimized->accept($compiler);

        // The alternation (\+|) should not be merged into ([+]) because of the empty branch
        // It should preserve the optional nature
        $this->assertStringContainsString('(\+|)', $result, 'Should preserve the alternation with empty branch');

        // Test that the regex still matches correctly
        $testRegex = '/^(\+|)\d+$/';
        $this->assertMatchesRegularExpression($testRegex, '123');    // no plus
        $this->assertMatchesRegularExpression($testRegex, '+123');   // with plus
        $this->assertDoesNotMatchRegularExpression($testRegex, '++123'); // multiple plus
    }

    public function test_basic_adjacent_char_class_merging(): void
    {
        // Test that [a-z]|[0-9] becomes [a-z0-9] when digit optimization is disabled
        $regex = Regex::create();
        $ast = $regex->parse('/[a-z]|[0-9]/');
        $optimizer = new Rewriter(optimizeDigits: false);

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[a-z0-9]/', $result);
    }

    public function test_no_merging_with_negated_classes(): void
    {
        // Test that [a-z]|[^0-9] does not merge, even if [^0-9] becomes \D
        $regex = Regex::create();
        $ast = $regex->parse('/[a-z]|[^0-9]/');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[a-z]|\D/', $result);
    }

    #[DataProvider('digitOptimizationProvider')]
    public function test_digit_optimization_with_flags(string $pattern, ?string $expectedCharType, string $description): void
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $optimizer = new Rewriter();

        /** @var RegexNode $optimized */
        $optimized = $ast->accept($optimizer);

        if (null !== $expectedCharType) {
            // For quantified patterns like [0-9]+, the pattern is QuantifierNode with CharTypeNode inside
            if ($optimized->pattern instanceof QuantifierNode) {
                $this->assertInstanceOf(CharTypeNode::class, $optimized->pattern->node, $description);
                $this->assertSame($expectedCharType, $optimized->pattern->node->value, $description);
            } else {
                $this->assertInstanceOf(CharTypeNode::class, $optimized->pattern, $description);
                $this->assertSame($expectedCharType, $optimized->pattern->value, $description);
            }
        } else {
            // For non-optimized cases, check the inner pattern type
            if ($optimized->pattern instanceof QuantifierNode) {
                $this->assertInstanceOf(CharClassNode::class, $optimized->pattern->node, $description);
            } else {
                $this->assertInstanceOf(CharClassNode::class, $optimized->pattern, $description);
            }
        }
    }

    public static function digitOptimizationProvider(): \Iterator
    {
        yield 'no u flag - should optimize' => ['/[0-9]/', 'd', '[0-9] without u flag should optimize to \d'];
        yield 'with u flag - should not optimize' => ['/[0-9]/u', null, '[0-9] with u flag should remain as CharClass'];
        yield 'negated class - should optimize' => ['/[^0-9]/', 'D', '[^0-9] negated class should optimize to \D'];
        yield 'multiple parts - should not optimize' => ['/[0-9a]/', null, '[0-9a] with multiple parts should not optimize'];
        yield 'quantified - should optimize' => ['/[0-9]+/', 'd', '[0-9]+ should optimize to \d+'];
        yield 'quantified with u flag - should not optimize' => ['/[0-9]+/u', null, '[0-9]+ with u flag should remain as CharClass'];
    }

    public function test_negated_word_class_optimizes_to_uppercase_w(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/[^a-zA-Z0-9_]+/');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/\W+/', $result);
    }

    public function test_negated_word_class_not_optimized_in_unicode_mode(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/[^a-zA-Z0-9_]+/u');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[^0-9A-Z_a-z]+/u', $result);
    }

    public function test_negated_digit_class_not_optimized_in_unicode_mode(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/[^0-9]+/u');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[^0-9]+/u', $result);
    }

    public function test_multiline_flag_removed_when_unused(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/abc/m');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/abc/', $result);
    }

    public function test_multiline_flag_preserved_when_anchors_present(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/^abc$/m');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/^abc$/m', $result);
    }

    public function test_auto_possessify_skips_nullable_subpattern(): void
    {
        $optimizer = new Rewriter(autoPossessify: true);
        $regex = Regex::create();
        $ast = $regex->parse('/(?:a?)+b/');

        $optimized = $ast->pattern->accept($optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);

        $this->assertStringContainsString('(?:a?)+b', $optimizedPattern);
        $this->assertStringNotContainsString('++', $optimizedPattern);
    }

    public function test_auto_possessify_skips_capture_sensitive_group(): void
    {
        $optimizer = new Rewriter(autoPossessify: true);
        $regex = Regex::create();
        $ast = $regex->parse('/(a+)+b/');

        $optimized = $ast->pattern->accept($optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);

        $this->assertStringContainsString('(a+)+b', $optimizedPattern);
        $this->assertStringNotContainsString('++', $optimizedPattern);
    }

    public function test_auto_possessify_applies_to_star_and_range(): void
    {
        $optimizer = new Rewriter(autoPossessify: true);
        $regex = Regex::create();

        $ast = $regex->parse('/a*b/');
        $optimized = $ast->pattern->accept($optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);
        $this->assertStringContainsString('a*+b', $optimizedPattern);

        $ast = $regex->parse('/a{2,}b/');
        $optimized = $ast->pattern->accept($optimizer);
        $optimizedPattern = $optimized->accept($compiler);
        $this->assertStringContainsString('a{2,}+', $optimizedPattern);
    }

    public function test_suffix_factoring(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/abcde|xyzde/');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/abcde|xyzde/', $result, 'Should not factor common suffix "de" by default');
    }

    public function test_suffix_factoring_no_common_suffix(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/abc|def/');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/abc|def/', $result, 'Should not factor when no common suffix');
    }

    public function test_suffix_factoring_single_char_suffix(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/alpha|beta|gamma|delta/');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/alpha|beta|gamma|delta/', $result, 'Should not factor single character suffix');
    }

    public function test_char_type_n_h_v_preserved_in_optimization(): void
    {
        // Test that \N, \H, \V are preserved correctly during optimization
        $regex = Regex::create();

        // Test \N (any char except newline)
        $ast = $regex->parse('/\N+/');
        $optimizer = new Rewriter();
        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);
        $this->assertSame('/\N+/', $result, '\N should be preserved');

        // Test \H (not horizontal whitespace)
        $ast = $regex->parse('/\H+/');
        $optimized = $ast->accept($optimizer);
        $result = $optimized->accept($compiler);
        $this->assertSame('/\H+/', $result, '\H should be preserved');

        // Test \V (not vertical whitespace)
        $ast = $regex->parse('/\V+/');
        $optimized = $ast->accept($optimizer);
        $result = $optimized->accept($compiler);
        $this->assertSame('/\V+/', $result, '\V should be preserved');
    }

    public function test_backspace_in_char_class_preserved(): void
    {
        // Test that [\b] (backspace) is preserved correctly during optimization
        $regex = Regex::create();
        $ast = $regex->parse('/[\b]/');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        $this->assertSame('/[\b]/', $result, '[\b] (backspace) should be preserved');
    }

    public function test_char_type_n_and_backspace_in_alternation(): void
    {
        // Test that \N|[\b] pattern is not corrupted during optimization
        $regex = Regex::create();
        $ast = $regex->parse('/\N|[\b]/');
        $optimizer = new Rewriter();

        $optimized = $ast->accept($optimizer);
        $compiler = new PatternPrinter();
        $result = $optimized->accept($compiler);

        // The pattern should preserve \N as a char type and [\b] as backspace in char class
        $this->assertStringContainsString('\N', $result, '\N should be preserved in alternation');
        $this->assertStringContainsString('[\b]', $result, '[\b] should be preserved in alternation');
    }

    /**
     * Test that optimizer does not merge adjacent capturing groups that would break semantics.
     * Original issue: ~^a\.b(c(\d+)(\d+)(\s+))?d~ should not become ~^a\.b(c(\d+){2}(\s+))?d~
     * because the two (\d+) groups capture independently.
     */
    public function test_optimizer_does_not_merge_adjacent_capturing_groups(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(a)(a)/');

        $optimized = $ast->pattern->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);

        // Should not merge to /(a){2}/ as that changes capture semantics
        $this->assertStringContainsString('(a)(a)', $optimizedPattern);
        $this->assertStringNotContainsString('(a){2}', $optimizedPattern);
    }

    /**
     * Test that optimizer does not merge named capturing groups.
     */
    public function test_optimizer_does_not_merge_named_capturing_groups(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?<name1>a)(?<name2>a)/');

        $optimized = $ast->pattern->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);

        $this->assertStringContainsString('(?<name1>a)(?<name2>a)', $optimizedPattern);
    }

    /**
     * Test that optimizer does not merge branch reset groups.
     */
    public function test_optimizer_does_not_merge_branch_reset_groups(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?|(a)|(b))(?|(a)|(b))/');

        $optimized = $ast->pattern->accept($this->optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);

        // Branch reset groups should not be merged
        $this->assertStringContainsString('(?|(a)|(b))(?|(a)|(b))', $optimizedPattern);
    }

    /**
     * Test auto-possessify checks disjointness against suffix, not just next node.
     * Original issue: ( ?\d{4}){1,} ?\d{1,4} should not possessify if suffix can match digits.
     */
    public function test_auto_possessify_checks_suffix_disjointness(): void
    {
        $optimizer = new Rewriter(autoPossessify: true);
        $regex = Regex::create();
        $ast = $regex->parse('/( ?\d{4}){1,} ?\d{1,4}/'); // IBAN-like pattern with {1,}

        $optimized = $ast->pattern->accept($optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);

        // The ( ?\d{4})+ should not be possessified because suffix ?\d{1,4} can match digits
        $this->assertStringContainsString('( ?\d{4})+', $optimizedPattern);
        $this->assertStringNotContainsString('( ?\d{4})++', $optimizedPattern);
    }

    /**
     * Test auto-possessify can still work when suffix is disjoint.
     */
    public function test_auto_possessify_works_when_safe(): void
    {
        $optimizer = new Rewriter(autoPossessify: true);
        $regex = Regex::create();
        $ast = $regex->parse('/\d+[a-z]/'); // \d+ followed by [a-z], disjoint

        $optimized = $ast->pattern->accept($optimizer);
        $compiler = new PatternPrinter();
        $optimizedPattern = $optimized->accept($compiler);

        // Should possessify \d+ since [a-z] is disjoint from digits
        $this->assertStringContainsString('\d++', $optimizedPattern);
    }
}
