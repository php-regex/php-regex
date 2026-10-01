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

use PhpRegex\Explain\AsciiTreeRenderer;
use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Explain\Highlighter\HtmlHighlighter;
use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Explain\MermaidRenderer;
use PhpRegex\Explain\RailroadSvgRenderer;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Generator\SampleGenerationException;
use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Generator\TestCaseGenerator;
use PhpRegex\Linter\PatternLinter;
use PhpRegex\Optimizer\Modernizer;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\Analysis\LiteralExtractor;
use PhpRegex\Parser\Analysis\MetricsCollector;
use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Internal\ExtendedClassReader;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\ClassSetOperator;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Printer\NodeDumper;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Parser\Syntax\TokenParser;
use PhpRegex\Parser\Token\Token;
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Parser\Validation\ValidationErrorCategory;
use PhpRegex\Redos\RedosProfiler;
use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Toolkit\Regex;
use PhpRegex\Transpiler\Target\JavaScript\JavaScriptPrinter;
use PhpRegex\Transpiler\TranspileContext;
use PhpRegex\Transpiler\TranspileException;
use PhpRegex\Transpiler\TranspileOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?[ \p{L} - [aeiou] ])", PCRE2 10.45: a set expression over classes,
 * types, properties and escaped characters. Before 10.45 "(?[" is refused on
 * its "[". Every pattern and offset below is from testinput1, 2 and 4 of the
 * PCRE2 suite, run through pcre2test 10.44, 10.45 and 10.49.
 */
final class ExtendedCharClassTest extends TestCase
{
    #[Test]
    #[DataProvider('provideClasses')]
    public function test_the_class_is_read_from_pcre2_10_45(string $pattern, int $offsetBefore): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.49']);
        $result = $regex->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, $result->error));
        $this->assertSame($pattern, $regex->parse($pattern)->accept(new PatternPrinter()), $pattern);

        $before = Regex::create(['cache' => null, 'pcre_version' => '10.44'])->validate($pattern);
        $this->assertFalse($before->isValid, $pattern);
        $this->assertSame($offsetBefore, $before->offset, $pattern);
    }

    #[Test]
    #[DataProvider('provideRefused')]
    public function test_what_pcre2_refuses_is_refused_where_it_stops(string $pattern, int $offsetBefore, int $offset1045, int $offset1049): void
    {
        foreach (['10.44' => $offsetBefore, '10.45' => $offset1045, '10.49' => $offset1049] as $release => $offset) {
            $result = Regex::create(['cache' => null, 'pcre_version' => (string) $release])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s on %s', $pattern, $release));
            $this->assertSame($offset, $result->offset, \sprintf('%s on %s', $pattern, $release));
        }
    }

    #[Test]
    public function test_the_tree_follows_the_precedence_of_the_operators(): void
    {
        // "!" binds tightest, then "&", then "+", "|", "-" and "^" left to right.
        $class = Regex::create(['cache' => null, 'pcre_version' => '10.45'])->parse('/(?[ \\d - [3] & ![:alpha:] + [a] ])/')->pattern;

        $this->assertInstanceOf(ExtendedCharClassNode::class, $class);
        $union = $class->expression;
        $this->assertInstanceOf(ClassSetOperationNode::class, $union);
        $this->assertSame(ClassSetOperator::Union, $union->operator);
        $this->assertInstanceOf(CharClassNode::class, $union->right);

        $difference = $union->left;
        $this->assertInstanceOf(ClassSetOperationNode::class, $difference);
        $this->assertSame(ClassSetOperator::Difference, $difference->operator);
        $this->assertInstanceOf(CharTypeNode::class, $difference->left);

        $intersection = $difference->right;
        $this->assertInstanceOf(ClassSetOperationNode::class, $intersection);
        $this->assertSame(ClassSetOperator::Intersection, $intersection->operator);
        $this->assertInstanceOf(CharClassNode::class, $intersection->left);

        $complement = $intersection->right;
        $this->assertInstanceOf(ClassSetOperationNode::class, $complement);
        $this->assertSame(ClassSetOperator::Complement, $complement->operator);
        $this->assertNull($complement->left);
        $this->assertInstanceOf(PosixClassNode::class, $complement->right);
    }

    #[Test]
    public function test_the_class_matches_one_character(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/^(?[ \\p{L} - [aeiou] ])+$/u';

        $this->assertSame([1, null], $regex->parse($pattern)->accept(new LengthRangeCalculator()));
        if (false !== @preg_match($pattern, '')) {
            $sample = $regex->generate($pattern);
            $this->assertSame(1, preg_match($pattern, $sample), $sample);
        }
    }

    #[Test]
    public function test_the_explanation_reads_the_expression(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $explained = $regex->parse('/(?[ \\d - [3] & ![a] ^ \\n | \\t ])/')->accept(new TextExplainer());

        $this->assertStringContainsString('Extended character class: one character of', $explained);
        $this->assertStringContainsString(' but not (', $explained);
        $this->assertStringContainsString(' and not ', $explained);
        $this->assertStringContainsString(' or else ', $explained);
        $this->assertStringContainsString(' or ', $explained);
    }

    #[Test]
    public function test_every_visitor_reads_the_class(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/(?[ \\d - ( [3] & ![:alpha:] ) ^ \\x61 |\\Q\\E\\p{Lu} ])+/';
        $ast = $regex->parse($pattern);
        $quantified = $ast->pattern;
        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertInstanceOf(ExtendedCharClassNode::class, $quantified->node);
        $this->assertSame('(?[ \\d - ( [3] & ![:alpha:] ) ^ \\x61 |\\Q\\E\\p{Lu} ])', $quantified->node->text);
        // Without the text, the highlighter writes each operation in parentheses.
        $unwritten = new ExtendedCharClassNode($quantified->node->expression, 0, 0);
        $this->assertSame('(?[(((\\d-([3]&![:alpha:]))^\\x61)|\\p{Lu})])', html_entity_decode(strip_tags($unwritten->accept(new HtmlHighlighter()))));

        $body = substr($pattern, 1, -1);
        $this->assertSame($body, preg_replace('/\e\[[\d;]*+m/', '', $ast->accept(new ConsoleHighlighter())));
        $this->assertSame($body, html_entity_decode(strip_tags($ast->accept(new HtmlHighlighter()))));
        $this->assertStringContainsString('ExtendedCharClass', $ast->accept(new NodeDumper()));
        $this->assertStringContainsString('Extended', $ast->accept(new AsciiTreeRenderer()));
        $this->assertStringContainsString('Extended', $ast->accept(new HtmlExplainer()));
        $this->assertStringContainsString('Extended', $ast->accept(new MermaidRenderer()));
        $svg = $ast->accept(new RailroadSvgRenderer());
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', (string) $svg);
        $this->assertGreaterThan(0, $ast->accept(new ComplexityScorer()));
        $this->assertNotEmpty($ast->accept(new MetricsCollector()));
        $this->assertNotEmpty($ast->accept(new RedosProfiler()));
        $this->assertNotEmpty($ast->accept(new TestCaseGenerator()));
        $this->assertNotNull($ast->accept(new LiteralExtractor()));
        $this->assertSame($pattern, $ast->accept(new Rewriter())->accept(new PatternPrinter()));
        // The modernized tree has no source: each operation comes back in parentheses.
        $this->assertSame('/(?[(((\\d-([3]&![:alpha:]))^\\x61)|\\p{Lu})])+/', $ast->accept(new Modernizer())->accept(new PatternPrinter()));
        $ast->accept(new PatternLinter());
        $this->assertStringContainsString('but not (', $ast->accept(new HtmlExplainer()));

        // Each visitor answers for an operation on its own, too.
        $operation = $quantified->node;
        $this->assertInstanceOf(ExtendedCharClassNode::class, $operation);
        $operation = $operation->expression;
        $this->assertInstanceOf(ClassSetOperationNode::class, $operation);
        $complement = new ClassSetOperationNode(ClassSetOperator::Complement, null, new CharTypeNode('d', 0, 2), '!', 0, 3);
        $this->assertSame(['matching' => [], 'non_matching' => ['0']], $complement->accept(new TestCaseGenerator()));
        $this->assertSame([1, 1], $operation->accept(new LengthRangeCalculator()));
        $this->assertTrue($operation->accept(new LiteralExtractor())->isVoid());
        $this->assertSame($operation, $operation->accept(new Rewriter()));
        $this->assertSame($operation, $operation->accept(new Modernizer()));
        $this->assertSame($operation, $operation->accept(new PatternLinter()));
        $this->assertSame(RedosSeverity::Safe, $operation->accept(new RedosProfiler()));
        $this->assertNull($operation->accept(new class extends AbstractNodeVisitor {}));
        $this->assertNull($quantified->node->accept(new class extends AbstractNodeVisitor {}));
    }

    #[Test]
    public function test_a_class_or_posix_form_that_never_closes_ends_with_the_pattern(): void
    {
        $this->assertSame(4, ExtendedClassReader::endOfClass('[:ab', 0));
        $this->assertSame(6, ExtendedClassReader::endOfClass('[a\\Qb]', 0));
        $this->assertSame(5, ExtendedClassReader::endOfClass('[:a:]', 0));
    }

    #[Test]
    public function test_a_member_that_cannot_stand_alone_is_passed_over(): void
    {
        // Only a member a class reads alone is judged alone.
        $parser = new TokenParser();
        (new \ReflectionProperty($parser, 'pattern'))->setValue($parser, '(?[[)\\N]])');
        $members = [new Token(TokenType::GroupClose, ')', 4), new Token(TokenType::CharType, 'N', 5, 2)];

        $error = (new \ReflectionMethod($parser, 'firstMemberErrorBefore'))->invoke($parser, $members, 8);
        $this->assertInstanceOf(ParserException::class, $error);
        $this->assertSame(7, $error->getPosition());
    }

    #[Test]
    public function test_the_messages_name_what_is_wrong(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.49']);

        $this->assertStringStartsWith('Malformed \\p sequence', (string) $regex->validate('/(?[ \\p{L ])/')->error);
        $this->assertStringStartsWith('Malformed \\P sequence', (string) $regex->validate('/(?[ \\P{L ])/')->error);
        $this->assertStringStartsWith('Unknown POSIX class "<" at position 10.', (string) $regex->validate('/(?[ [[:<:]] ])/')->error);
        $this->assertStringStartsWith('Unknown POSIX class ">" at position 10.', (string) $regex->validate('/(?[ [[:>:]] ])/')->error);
    }

    #[Test]
    public function test_the_length_of_the_class_is_one_character(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);

        $this->assertSame([1, 1], $regex->parse('/(?[ \\d - [3] ])/')->accept(new LengthRangeCalculator()));
    }

    #[Test]
    public function test_a_character_is_written_back_as_a_byte_escape_without_utf(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);

        $this->assertSame('/(?[\\x38])/', $regex->parse('/(?[ \\8 ])/')->accept(new PatternPrinter(true)));
        $this->assertSame('/[a b]/', $regex->parse('/[a b]/')->accept(new PatternPrinter(true)));
    }

    #[Test]
    public function test_overlaps_follow_each_set_operation(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);

        // Each set leaves out [5-9], so the branches never overlap.
        foreach (['(?[ [0-4] & [0-9] ])', '(?[ [0-9] ^ [5-9] ])', '(?[ [0-9] - [5-9] ])'] as $set) {
            $this->assertNotSame(RedosSeverity::Critical, $regex->redos('/^('.$set.'|[5-9])+$/')->severity, $set);
        }

        $this->assertSame(RedosSeverity::Critical, $regex->redos('/^((?[ [0-4] + [5-6] ])|[5-9])+$/')->severity);
    }

    #[Test]
    public function test_an_escape_is_a_character_of_the_set(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $class = $regex->parse('/(?[ \\b + \\1 ])/')->pattern;
        $this->assertInstanceOf(ExtendedCharClassNode::class, $class);
        $union = $class->expression;
        $this->assertInstanceOf(ClassSetOperationNode::class, $union);

        $this->assertNotInstanceOf(AssertionNode::class, $union->left);
        $this->assertNotInstanceOf(BackrefNode::class, $union->right);
        $this->assertContains($regex->generate('/^(?[ \\b + \\1 ])$/'), ["\x08", "\x01"]);
    }

    #[Test]
    public function test_punctuation_is_written_back_escaped(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/^(?[ \\! + \\. + \\  + \\# + \\( + \\& + [a] ])$/';

        $written = $regex->parse($pattern)->accept(new PatternPrinter(true));
        $this->assertIsString($written);
        $this->assertTrue($regex->validate($written)->isValid, $written);
        if (false !== @preg_match($pattern, '')) {
            foreach (['!', '.', ' ', '#', '(', '&', 'a', 'b'] as $subject) {
                $this->assertSame(preg_match($pattern, $subject), preg_match($written, $subject), $subject);
            }
        }
    }

    #[Test]
    public function test_a_class_in_an_assertion_body_is_written_back(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.49']);

        $this->assertSame('/(?=(?[ \\d - [3] ]))\\d/', $regex->parse('/(*pla:(?[ \\d - [3] ]))\\d/')->accept(new PatternPrinter()));
        $this->assertSame('/(a)(*scs:(1)(?[ \\d ]))/', $regex->parse('/(a)(*scs:(1)(?[ \\d ]))/')->accept(new PatternPrinter()));
        $this->assertSame('/(?[ \\w ])(?=(?[ \\d ]))/', $regex->parse('/(?[ \\w ])(*pla:(?[ \\d ]))/')->accept(new PatternPrinter()));
    }

    #[Test]
    public function test_a_quoted_blank_in_a_nested_class_is_a_member(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.49']);
        $pattern = '/^(?[ [\\Qa b\\E] ])$/';

        $written = $regex->parse($pattern)->accept(new PatternPrinter(true));
        $this->assertIsString($written);
        if (false !== @preg_match($pattern, '')) {
            foreach ([' ', 'a', 'b', 'c'] as $subject) {
                $this->assertSame(preg_match($pattern, $subject), preg_match($written, $subject), $subject);
            }
        }
    }

    #[Test]
    public function test_characters_are_written_back_as_escapes_under_utf(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/^(?[ \\é + \\8 + \\! ])$/u';

        $written = $regex->parse($pattern)->accept(new PatternPrinter(true));
        $this->assertSame('/^(?[((\\x{e9}+\\x{38})+\\!)])$/u', $written);
    }

    #[Test]
    public function test_no_test_cases_come_from_a_set_the_engine_cannot_read(): void
    {
        $unreadable = new ExtendedCharClassNode(new DotNode(3, 4), 0, 6);

        $this->assertSame(['matching' => [], 'non_matching' => []], $unreadable->accept(new TestCaseGenerator()));
    }

    #[Test]
    public function test_a_long_run_of_complements_stops_at_the_recursion_limit(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);

        $this->assertTrue($regex->validate('/(?['.str_repeat('!', 1000).'\\d])/')->isValid);
        $result = $regex->validate('/(?['.str_repeat('!', 20000).'\\d])/');
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Recursion limit', (string) $result->error);
    }

    #[Test]
    public function test_the_test_cases_are_members_and_non_members(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        foreach (['/^(?[ [ab] - [a] ])$/', '/^(?[ [ab] & [b] ])$/', '/^(?[ [ab] ^ [a] ])$/', '/^(?[ ![a] ])$/'] as $pattern) {
            $cases = $regex->parse($pattern)->accept(new TestCaseGenerator());
            $this->assertIsArray($cases);
            if (false === @preg_match($pattern, '')) {
                continue;
            }

            $this->assertNotSame([], $cases['matching'], $pattern);
            foreach ($cases['matching'] as $subject) {
                $this->assertSame(1, preg_match($pattern, $subject), $pattern.' '.$subject);
            }

            foreach ($cases['non_matching'] as $subject) {
                $this->assertSame(0, preg_match($pattern, $subject), $pattern.' '.$subject);
            }
        }
    }

    #[Test]
    public function test_overlapping_sets_under_a_repeat_are_found(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);

        $this->assertSame(
            $regex->redos('/^(\\d|\\d)+$/')->severity,
            $regex->redos('/^((?[\\d])|(?[ \\d - [5] ]))+$/')->severity,
        );
        $this->assertSame(RedosSeverity::Critical, $regex->redos('/^((?[ [0-9] & [0-4] ])|(?[ [0-9] ^ [5-9] ]))+$/')->severity);
        $this->assertNotSame(RedosSeverity::Critical, $regex->redos('/^((?[ [0-9] - [5-9] ])|(?[ [5-9] ]))+$/')->severity);
        $this->assertSame(RedosSeverity::Critical, $regex->redos('/^((?[ ![a] ])|b)+$/')->severity);
        $this->assertNotSame(RedosSeverity::Critical, $regex->redos('/^((?[ ![a] ])|a)+$/')->severity);
        // A property is no set of known characters: the overlap is assumed.
        $this->assertSame(RedosSeverity::Critical, $regex->redos('/^((?[ \\p{L} & [a] ])|a)+$/')->severity);
    }

    #[Test]
    public function test_an_operand_pcre_refuses_keeps_its_error_code(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.49']);

        $result = $regex->validate('/(?[\\j\\t])/');
        $this->assertSame(ErrorCode::EscapeUnrecognized, $result->errorCode);
        $this->assertSame(ValidationErrorCategory::Semantic, $result->category);
        $this->assertSame(5, $result->offset);

        // Reading alone, the error is a parse error, as every other one.
        $this->expectException(ParserException::class);
        $regex->parse('/(?[\\j\\t])/');
    }

    #[Test]
    public function test_the_transpilers_refuse_the_class(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);

        try {
            $regex->transpile('/a(?[ \\d - [3] ])/', 'javascript');
            self::fail('The class was transpiled.');
        } catch (TranspileException $e) {
            $this->assertStringContainsString('(?[...])', $e->getMessage());
        }

        $class = $regex->parse('/(?[ \\d - [3] ])/')->pattern;
        $this->assertInstanceOf(ExtendedCharClassNode::class, $class);
        $visitor = new JavaScriptPrinter(new TranspileContext('(?[ \\d - [3] ])', '', new TranspileOptions()), false, '/');
        $this->expectException(TranspileException::class);
        $class->expression->accept($visitor);
    }

    #[Test]
    public function test_a_class_with_no_member_yields_a_guess(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/(?[ \\d & [a] ])/';

        // The visitor guesses from the left operand; generate() finds no
        // sample the engine matches, and says so.
        $this->assertSame(1, preg_match('/^\\d$/', $regex->parse($pattern)->accept(new SampleGenerator())));
        if (false !== @preg_match($pattern, '')) {
            $this->expectException(SampleGenerationException::class);
            $regex->generate($pattern);
        }
    }

    #[Test]
    public function test_a_caseless_class_yields_a_member_under_its_flags(): void
    {
        $pattern = '/^(?[ [\\p{Lu}1] ^ \\p{Ll} ])$/iu';
        if (false === @preg_match($pattern, '')) {
            $this->assertIsString(Regex::create(['cache' => null, 'pcre_version' => '10.45'])->parse($pattern)->accept(new SampleGenerator()));

            return;
        }

        $ast = Regex::create(['cache' => null, 'pcre_version' => '10.45'])->parse($pattern);
        $generator = new SampleGenerator();
        for ($seed = 0; $seed < 8; $seed++) {
            $generator->setSeed($seed);
            $this->assertSame(1, preg_match($pattern, $ast->accept($generator)));
        }
    }

    #[Test]
    public function test_a_slash_in_the_class_still_yields_a_member(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '#^(?[ [/a] - [a] ])$#';

        $sample = $regex->generate($pattern);
        if (false !== @preg_match($pattern, '')) {
            $this->assertSame('/', $sample);
        } else {
            // An engine without "(?[" cannot tell: a member of the left operand.
            $this->assertContains($sample, ['/', 'a']);
        }
    }

    #[Test]
    public function test_without_the_engine_a_member_of_the_left_operand_is_guessed(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $class = $regex->parse('/(?[ [b] - [a] ])/')->pattern;
        $this->assertInstanceOf(ExtendedCharClassNode::class, $class);
        $generator = new SampleGenerator();

        $this->assertSame('b', $class->expression->accept($generator));
        $complement = new ClassSetOperationNode(ClassSetOperator::Complement, null, $class->expression, '!', 0, 0);
        $this->assertSame('!', $complement->accept($generator));
    }

    #[Test]
    public function test_the_class_is_written_back_without_its_source(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/(?[ \\d - [3] & ![:alpha:] + [a] ])/';

        $written = $regex->parse($pattern)->accept(new PatternPrinter(true));
        $this->assertIsString($written);
        $this->assertStringContainsString('(?[', $written);
        if (false !== @preg_match($pattern, '')) {
            foreach (['1', '3', 'a', 'b', '!'] as $subject) {
                $this->assertSame(preg_match($pattern, $subject), preg_match($written, $subject), $subject);
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offsetBefore: int}>
     */
    public static function provideClasses(): iterable
    {
        yield 'testinput1:6852' => ['pattern' => '/(?[\\n])/', 'offsetBefore' => 2];
        yield 'testinput1:6860' => ['pattern' => '/^(?[\\x61])b/', 'offsetBefore' => 3];
        yield 'testinput1:6866' => ['pattern' => '/^(?[\\x61])+b/', 'offsetBefore' => 3];
        yield 'testinput1:6874' => ['pattern' => '/(?[ [[:graph:]] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6879' => ['pattern' => '/(?[ [:graph:] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6884' => ['pattern' => '/(?[ [[:graph:]\\x02] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6890' => ['pattern' => '/(?[\\E\\n])/', 'offsetBefore' => 2];
        yield 'testinput1:6896' => ['pattern' => '/(?[\\n \\Q\\E])/', 'offsetBefore' => 2];
        yield 'testinput1:6902' => ['pattern' => '/(?[ ( \\x02 + [:graph:] ) | [ \\x02 [:graph:] ] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6908' => ['pattern' => '/(?[ \\d ])/', 'offsetBefore' => 2];
        yield 'testinput1:6913' => ['pattern' => '/(?[[1]])/', 'offsetBefore' => 2];
        yield 'testinput1:6918' => ['pattern' => '/(?[[a]])/', 'offsetBefore' => 2];
        yield 'testinput1:6923' => ['pattern' => '/(?[[a-c]])/', 'offsetBefore' => 2];
        yield 'testinput1:6930' => ['pattern' => '/(?[ [\\t] + [\\n] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6938' => ['pattern' => '/(?[ \\t + \\n ])/', 'offsetBefore' => 2];
        yield 'testinput1:6946' => ['pattern' => '/(?[ [()] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6952' => ['pattern' => '/(?[ ( [()] ) ])/', 'offsetBefore' => 2];
        yield 'testinput1:6958' => ['pattern' => '/(?[ (( [\\n\\t] )) ])/', 'offsetBefore' => 2];
        yield 'testinput1:6968' => ['pattern' => '/(?[ !\\n ])/', 'offsetBefore' => 2];
        yield 'testinput1:6973' => ['pattern' => '/(?[ !\\d ])/', 'offsetBefore' => 2];
        yield 'testinput1:6978' => ['pattern' => '/(?[ ![:alpha:] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6983' => ['pattern' => '/(?[ ![\\n] ])/', 'offsetBefore' => 2];
        yield 'testinput1:6988' => ['pattern' => '/(?[ !(\\n) ])/', 'offsetBefore' => 2];
        yield 'testinput1:6993' => ['pattern' => '/(?[ !!\\n ])/', 'offsetBefore' => 2];
        yield 'testinput1:7000' => ['pattern' => '/(?[ (\\n) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7005' => ['pattern' => '/(?[ (\\d) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7010' => ['pattern' => '/(?[ ([:alpha:]) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7015' => ['pattern' => '/(?[ ([\\n]) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7020' => ['pattern' => '/(?[ ((\\n)) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7025' => ['pattern' => '/(?[ (!\\n) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7030' => ['pattern' => '/(?[ (\\n + \\t) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7038' => ['pattern' => '/(?[ \\n & [\\n\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7043' => ['pattern' => '/(?[ \\d & [\\d\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7048' => ['pattern' => '/(?[ [:alpha:] & [a-z\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7054' => ['pattern' => '/(?[ [\\n] & [\\n\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7059' => ['pattern' => '/(?[ (\\n) & [\\n\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7064' => ['pattern' => '/(?[ !\\n & [^\\n\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7070' => ['pattern' => '/(?[ \\n & [\\n\\t] + [\\d] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7079' => ['pattern' => '/(?[ [\\n\\t] & \\n ])/', 'offsetBefore' => 2];
        yield 'testinput1:7084' => ['pattern' => '/(?[ [\\d\\t] & \\d ])/', 'offsetBefore' => 2];
        yield 'testinput1:7089' => ['pattern' => '/(?[ [a-z\\t] & [:alpha:] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7095' => ['pattern' => '/(?[ [\\n\\t] & [\\n] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7100' => ['pattern' => '/(?[ [\\n\\t] & (\\n) ])/', 'offsetBefore' => 2];
        yield 'testinput1:7105' => ['pattern' => '/(?[ [^\\n\\t] & !\\n ])/', 'offsetBefore' => 2];
        yield 'testinput1:7111' => ['pattern' => '/(?[ [\\d] + \\n & [\\n\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7118' => ['pattern' => '/(?[ [\\d] + \\n + [\\t] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7127' => ['pattern' => '/(?[ \\d + \\n ])/', 'offsetBefore' => 2];
        yield 'testinput1:7133' => ['pattern' => '/(?[ \\d | \\n ])/', 'offsetBefore' => 2];
        yield 'testinput1:7139' => ['pattern' => '/(?[ \\d - [2] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7145' => ['pattern' => '/(?[ [AC] ^ [BC] ])/', 'offsetBefore' => 2];
        yield 'testinput1:7152' => ['pattern' => '/(?[	(	[	^	z	]	) ])/', 'offsetBefore' => 2];
        // An escape is read as a class reads it: "\1" and "\8" are characters, "\b" a backspace, "\k" the letter.
        yield 'octal digit' => ['pattern' => '/(?[\\1])/', 'offsetBefore' => 2];
        yield 'escaped 8' => ['pattern' => '/(?[\\8])/', 'offsetBefore' => 2];
        yield 'escaped k' => ['pattern' => '/(?[\\k])/', 'offsetBefore' => 2];
        yield 'backspace' => ['pattern' => '/(?[\\b])/', 'offsetBefore' => 2];
        yield 'escaped punctuation' => ['pattern' => '/(?[ \\! + \\. ])/', 'offsetBefore' => 2];
        yield 'escaped multibyte character' => ['pattern' => '/(?[ \\é ])/u', 'offsetBefore' => 2];
        yield 'control "]" in a nested class' => ['pattern' => '/(?[[\\c]]])/', 'offsetBefore' => 2];
        yield 'control "]" after a member' => ['pattern' => '/(?[[a\\c]b]])/', 'offsetBefore' => 2];
        // A nested class is read under "xx": blanks before a first "]" leave it a member.
        yield 'blank before a first "]"' => ['pattern' => '/(?[[ ]a]])/', 'offsetBefore' => 2];
        yield 'blanks around "^" before a first "]"' => ['pattern' => '/(?[[ ^ ]a]])/', 'offsetBefore' => 2];
        yield 'class that looks like a POSIX form, then a class ending in ":"' => ['pattern' => '/(?[ [:a] + \\d - [b:] ])/', 'offsetBefore' => 2];
        yield 'quote after a lone end of quote' => ['pattern' => '/(?[[a\\E\\Qb]c\\E]])/', 'offsetBefore' => 2];
        yield 'empty quote after a member' => ['pattern' => '/(?[[a\\Q\\E]])/', 'offsetBefore' => 2];
        yield 'empty quote before a first "]"' => ['pattern' => '/(?[[\\Q\\E]a]])/', 'offsetBefore' => 2];
        yield 'lone end of quote before a first "]"' => ['pattern' => '/(?[[^\\E]a]])/', 'offsetBefore' => 2];
        yield 'quoted "]" in a nested class' => ['pattern' => '/(?[[\\Qa]\\E]])/', 'offsetBefore' => 2];
        yield 'testinput2:7532' => ['pattern' => '/(?[\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n&\\n))))))))))))))])/', 'offsetBefore' => 2];
        yield 'testinput2:7544' => ['pattern' => '/(?[\\d-[1]]).(?<!x)/', 'offsetBefore' => 2];
        yield 'testinput4:3003' => ['pattern' => '/(?[\\p{L} - \\p{Lu}])/', 'offsetBefore' => 2];
        yield 'testinput4:3009' => ['pattern' => '/(?[\\p{L} & \\p{Lu}])/', 'offsetBefore' => 2];
        yield 'testinput4:3015' => ['pattern' => '/(?[[\\p{Lu}z] ^ [\\p{Ll}G]])/', 'offsetBefore' => 2];
        yield 'testinput4:3023' => ['pattern' => '/(?[\\p{Ll} | \\p{Nd}])/', 'offsetBefore' => 2];
        yield 'testinput4:3029' => ['pattern' => '/(?[\\p{Ll} + [\\p{Nd}]])/', 'offsetBefore' => 2];
        yield 'testinput4:3035' => ['pattern' => '/(?[ ![\\p{Nd}z] ])/', 'offsetBefore' => 2];
        yield 'testinput4:3042' => ['pattern' => '/(?[ \\P{Nd} + [2] ])/', 'offsetBefore' => 2];
        yield 'testinput4:3050' => ['pattern' => '/(?[ ![\\P{Nd}] ])/', 'offsetBefore' => 2];
        yield 'testinput4:3059' => ['pattern' => '/(?[ \\p{Lu} ^ \\p{Ll} ])/', 'offsetBefore' => 2];
        yield 'testinput4:3066' => ['pattern' => '/(?[ [\\p{Lu}1] ^ \\p{Ll} ])/i', 'offsetBefore' => 2];
        yield 'testinput4:3073' => ['pattern' => '/(?[ [\\p{Lu}1] & [\\p{Ll}1] ])/', 'offsetBefore' => 2];
        yield 'testinput4:3081' => ['pattern' => '/(?[ [\\p{Lu}1] & [\\p{Ll}1] ])/i', 'offsetBefore' => 2];
        yield 'testinput4:3089' => ['pattern' => '/(?[ \\p{Lu} + \\p{Ll} & [a-z] ])/u', 'offsetBefore' => 2];
        yield 'testinput4:3096' => ['pattern' => '/(?[ (\\p{Lu} + \\p{Ll}) & [a-z] ])/u', 'offsetBefore' => 2];
        yield 'testinput4:3103' => ['pattern' => '/(?[ [a-z] & \\p{Lu} + \\p{Ll} ])/u', 'offsetBefore' => 2];
        yield 'testinput4:3110' => ['pattern' => '/(?[ [a-z] & (\\p{Lu} + \\p{Ll}) ])/u', 'offsetBefore' => 2];
    }

    /**
     * @return iterable<string, array{pattern: string, offsetBefore: int, offset1045: int, offset1049: int}>
     */
    public static function provideRefused(): iterable
    {
        // An operand PCRE refuses is reported before what follows it.
        yield 'unknown escape, then an operand' => ['pattern' => '/(?[\\j\\t])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'escape no class takes, then an operator' => ['pattern' => '/(?[\\R|])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'unknown POSIX class, then an operand' => ['pattern' => '/(?[[:foo:]\\N])/', 'offsetBefore' => 2, 'offset1045' => 10, 'offset1049' => 10];
        yield 'code point without UTF, then an operand' => ['pattern' => '/(?[\\N{U+41}\\e])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 11];
        yield 'unknown escape as a second operand' => ['pattern' => '/(?[\\d \\j])/', 'offsetBefore' => 2, 'offset1045' => 7, 'offset1049' => 8];
        yield 'escape no class takes as a second operand' => ['pattern' => '/(?[\\d \\R])/', 'offsetBefore' => 2, 'offset1045' => 7, 'offset1049' => 8];
        yield 'code point too big as a second operand' => ['pattern' => '/(?[\\d \\x{110000}])/', 'offsetBefore' => 2, 'offset1045' => 15, 'offset1049' => 15];
        yield 'control escape of a non-ASCII character' => ['pattern' => '/(?[\\cé])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 6];
        yield 'start anchor' => ['pattern' => '/(?[\\A])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'match start reset' => ['pattern' => '/(?[\\K])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'escaped g, then a digit' => ['pattern' => '/(?[\\g1])/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'escaped g, then a brace' => ['pattern' => '/(?[\\g{1}])/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'non-hex character in braces' => ['pattern' => '/abcdefgh(?[ \\x{41 ])/', 'offsetBefore' => 10, 'offset1045' => 18, 'offset1049' => 19];
        yield 'multibyte character under UTF' => ['pattern' => '/(?[ é ])/u', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'multibyte character as a second operand under UTF' => ['pattern' => '/(?[ \\d é ])/u', 'offsetBefore' => 2, 'offset1045' => 9, 'offset1049' => 9];
        yield 'unknown escape, then a control escape of a non-ASCII character' => ['pattern' => '/(?[\\j\\cé])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'quoted text after an operand' => ['pattern' => '/(?[\\t\\Qab])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'quote at the end after an operand' => ['pattern' => '/(?[\\d\\Q/', 'offsetBefore' => 2, 'offset1045' => 7, 'offset1049' => 7];
        yield 'backslash at the end, after a control escape' => ['pattern' => '/(?[\\c\\\\/', 'offsetBefore' => 2, 'offset1045' => 7, 'offset1049' => 7];
        yield 'control escape at the end' => ['pattern' => '/(?[\\c/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 5];
        yield 'control escape at the end, after an operand' => ['pattern' => '/(?[\\d\\c/', 'offsetBefore' => 2, 'offset1045' => 7, 'offset1049' => 7];
        yield 'control "]" leaves a nested class open' => ['pattern' => '/(?[[\\c])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        // PCRE reads "[:", "[." and "[=" up to ":]", ".]" or "=]" as a POSIX form.
        yield 'collating element' => ['pattern' => '/(?[ [.a.] ])/', 'offsetBefore' => 2, 'offset1045' => 9, 'offset1049' => 9];
        yield 'equivalence class' => ['pattern' => '/(?[ [=a=] ])/', 'offsetBefore' => 2, 'offset1045' => 9, 'offset1049' => 9];
        yield 'collating element after an operator' => ['pattern' => '/(?[ [:alpha:] - [.a.] ])/', 'offsetBefore' => 2, 'offset1045' => 21, 'offset1049' => 21];
        yield 'collating element as a second operand' => ['pattern' => '/(?[ \\d [.a.] ])/', 'offsetBefore' => 2, 'offset1045' => 12, 'offset1049' => 12];
        yield 'POSIX name with a dash' => ['pattern' => '/(?[ [:a-b:] ])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'POSIX name after a space' => ['pattern' => '/(?[ [: alpha:] ])/', 'offsetBefore' => 2, 'offset1045' => 14, 'offset1049' => 14];
        yield 'POSIX name with an escaped "]"' => ['pattern' => '/(?[ [:alpha\\]:] ])/', 'offsetBefore' => 2, 'offset1045' => 15, 'offset1049' => 15];
        yield 'escaped backslash in a POSIX name' => ['pattern' => '/(?[ [:a\\\\:] ])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'POSIX form inside a POSIX name' => ['pattern' => '/(?[ [:a[:b:] ])/', 'offsetBefore' => 2, 'offset1045' => 12, 'offset1049' => 12];
        yield 'POSIX form never ended' => ['pattern' => '/(?[ [:ab/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'POSIX form never ended, read as a class' => ['pattern' => '/(?[ [:ab ])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'word start as a POSIX form' => ['pattern' => '/(?[ [:<:] ])/', 'offsetBefore' => 2, 'offset1045' => 9, 'offset1049' => 9];
        yield 'word start class' => ['pattern' => '/(?[ [[:<:]] ])/', 'offsetBefore' => 2, 'offset1045' => 10, 'offset1049' => 10];
        yield 'property never closed' => ['pattern' => '/(?[ \\p{L ])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'empty property never closed' => ['pattern' => '/(?[ \\p{ ])/', 'offsetBefore' => 2, 'offset1045' => 10, 'offset1049' => 10];
        // "\Q" quotes up to "\E", or to the end of the pattern.
        yield 'quote at the end' => ['pattern' => '/(?[\\Q/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 5];
        yield 'quoted character after an operand' => ['pattern' => '/(?[\\t\\Q])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'lone end of quote before a first "]"' => ['pattern' => '/(?[[\\E])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'quote never ended in a nested class' => ['pattern' => '/(?[[\\v\\h\\Q])/', 'offsetBefore' => 2, 'offset1045' => 12, 'offset1049' => 12];
        yield 'quoted "]" in a nested class' => ['pattern' => '/(?[ [\\Q]] ])/', 'offsetBefore' => 2, 'offset1045' => 12, 'offset1049' => 12];
        yield 'escape no class takes, in a class never closed' => ['pattern' => '/(?[[\\X(/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 6];
        yield 'blank before a first "]", never closed' => ['pattern' => '/(?[[ ])x/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'escape no class takes, then a range no class takes' => ['pattern' => '/(?[\\X|[\\d-z]\\d])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'complemented unknown escape, then a range no class takes' => ['pattern' => '/(?[!\\j+[\\d-z]])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 6];
        // PCRE reads left to right: the first fault it meets is the one reported.
        yield 'escape no class takes, then a parenthesis unmatched' => ['pattern' => '/(?[\\N])])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 5];
        yield 'unknown escape, then a group never closed' => ['pattern' => '/(?[\\j])(/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'escape no class takes, then a range no class takes, in one class' => ['pattern' => '/(?[[\\X[\\d-z]])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 6];
        yield 'unknown escape as a range start' => ['pattern' => '/(?[[\\j-\\v])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 6];
        yield 'escape no class takes, then a quote never ended' => ['pattern' => '/(?[[\\N\\Q])/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'unknown POSIX class, then a range no class takes' => ['pattern' => '/(?[[[:foo:][\\d-z]][:foo:]])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'unknown escape, then a class never closed holding a fault' => ['pattern' => '/(?[\\j + [\\x61\\w-x/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 5];
        yield 'escape no class takes as a range start' => ['pattern' => '/(?[[\\X-\\d])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 6];
        yield 'escape no class takes as a range start after a member' => ['pattern' => '/(?[[a\\X-\\d])/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 7];
        yield 'range no class takes in a class never closed' => ['pattern' => '/(?[[\\x61\\w-x/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'nested class never closed' => ['pattern' => '/(?[ [a/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'complemented class never closed' => ['pattern' => '/(?[ ![a/', 'offsetBefore' => 2, 'offset1045' => 7, 'offset1049' => 7];
        yield 'quoted character' => ['pattern' => '/(?[ \\Qa ])/', 'offsetBefore' => 2, 'offset1045' => 7, 'offset1049' => 7];
        yield 'complemented quoted character' => ['pattern' => '/(?[ !\\Qa ])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'unclosed POSIX class' => ['pattern' => '/(?[ ( [:alpha: ])/', 'offsetBefore' => 2, 'offset1045' => 17, 'offset1049' => 17];
        yield 'testinput2:7445' => ['pattern' => '/(?[ \\j ])/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 6];
        yield 'testinput2:7466' => ['pattern' => '/(?[])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7468' => ['pattern' => '/(?[/', 'offsetBefore' => 2, 'offset1045' => 3, 'offset1049' => 3];
        yield 'testinput2:7470' => ['pattern' => '/(?[]/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7472' => ['pattern' => '/(?[\\n/', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 5];
        yield 'testinput2:7474' => ['pattern' => '/(?[\\n]/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'testinput2:7476' => ['pattern' => '/(?[\\n]z)/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'testinput2:7478' => ['pattern' => '/(?[\\n] )/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'testinput2:7480' => ['pattern' => '/(?[(/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7482' => ['pattern' => '/(?[( /', 'offsetBefore' => 2, 'offset1045' => 5, 'offset1049' => 5];
        yield 'testinput2:7484' => ['pattern' => '/(?[(\\n/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'testinput2:7486' => ['pattern' => '/(?[ \\n + () ])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'testinput2:7488' => ['pattern' => '/(?[1])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7490' => ['pattern' => '/(?[a])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7492' => ['pattern' => '/(?[a-c])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7494' => ['pattern' => '/(?[(])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7496' => ['pattern' => '/(?[(\\n])/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'testinput2:7498' => ['pattern' => '/(?[\\n)])/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'testinput2:7500' => ['pattern' => '/(?[^\\n])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7502' => ['pattern' => '/(?[ \\n \\t ])/', 'offsetBefore' => 2, 'offset1045' => 9, 'offset1049' => 9];
        yield 'testinput2:7504' => ['pattern' => '/(?[ \\d \\t ])/', 'offsetBefore' => 2, 'offset1045' => 9, 'offset1049' => 9];
        yield 'testinput2:7506' => ['pattern' => '/(?[ [\\n] \\t ])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'testinput2:7508' => ['pattern' => '/(?[ (\\n) \\t ])/', 'offsetBefore' => 2, 'offset1045' => 11, 'offset1049' => 11];
        yield 'testinput2:7510' => ['pattern' => '/(?[ [:alpha:] \\t ])/', 'offsetBefore' => 2, 'offset1045' => 16, 'offset1049' => 16];
        yield 'testinput2:7512' => ['pattern' => '/(?[ \\n + \\t \\d ])/', 'offsetBefore' => 2, 'offset1045' => 14, 'offset1049' => 14];
        yield 'testinput2:7514' => ['pattern' => '/(?[ !\\n \\t ])/', 'offsetBefore' => 2, 'offset1045' => 10, 'offset1049' => 10];
        yield 'testinput2:7516' => ['pattern' => '/(?[ \\n [:alpha:] ])/', 'offsetBefore' => 2, 'offset1045' => 16, 'offset1049' => 16];
        yield 'testinput2:7518' => ['pattern' => '/(?[ \\n [\\d] ])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'testinput2:7520' => ['pattern' => '/(?[ \\n (\\t) ])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'testinput2:7522' => ['pattern' => '/(?[ \\n !\\t ])/', 'offsetBefore' => 2, 'offset1045' => 8, 'offset1049' => 8];
        yield 'testinput2:7524' => ['pattern' => '/(?[ \\n \\t ])/', 'offsetBefore' => 2, 'offset1045' => 9, 'offset1049' => 9];
        yield 'testinput2:7526' => ['pattern' => '/(?[:graph:])/', 'offsetBefore' => 2, 'offset1045' => 4, 'offset1049' => 4];
        yield 'testinput2:7528' => ['pattern' => '/(?[\\Qn\\E])/', 'offsetBefore' => 2, 'offset1045' => 6, 'offset1049' => 6];
        yield 'testinput2:7538' => ['pattern' => '/(?[\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+([\\n]&\\n))))))))))))))])/', 'offsetBefore' => 2, 'offset1045' => 158, 'offset1049' => 157];
        yield 'testinput2:7540' => ['pattern' => '/(?[\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n&[\\n]))))))))))))))])/', 'offsetBefore' => 2, 'offset1045' => 161, 'offset1049' => 160];
        yield 'testinput2:7542' => ['pattern' => '/(?[\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+(\\n+[a]&\\n+((\\n)&\\n))))))))))))))])/', 'offsetBefore' => 2, 'offset1045' => 158, 'offset1049' => 157];
    }
}
