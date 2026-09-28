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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Internal\GroupNameReader;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Parser;
use RegexParser\Regex;
use RegexParser\Token;
use RegexParser\TokenStream;
use RegexParser\TokenType;

/**
 * What the parser refuses, and what it does when a guess turns out wrong.
 *
 * The constructs it accepts are covered by ParserConstructsTest; this is the
 * other half — the syntax errors it must report, and the places where it
 * reads ahead and has to rewind.
 */
final class ParserRejectionsTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    /**
     * Test Python-style backref.
     */
    public function test_python_backref_not_supported(): void
    {
        $ast = $this->regexService->parse('/(?P<name>test)(?P=name)/');
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $backref = $ast->pattern->children[1];
        $this->assertInstanceOf(BackrefNode::class, $backref);
        $this->assertSame('\k<name>', $backref->ref);
    }

    public function test_pcre_verb_script_run_shortcut(): void
    {
        $ast = $this->regexService->parse('/(*sr:payload)/');

        $this->assertTrue($this->containsNode($ast->pattern, ScriptRunNode::class));
    }

    public function test_python_group_name_rejects_non_literal_token(): void
    {
        $this->expectException(ParserException::class);
        // Same message as every other way of spelling a group name.
        $this->expectExceptionMessage('Unexpected token "\\d" in group name');

        // "(?P'name'...)" is refused before its name is read (preg_match() on
        // PCRE2 10.48: "unrecognized character after (?P at offset 4"), so
        // the Python spelling that PHP takes carries the name here.
        $this->regexService->parse('/(?P<foo\\d>test)/');
    }

    public function test_conditional_version_with_invalid_number_resets_state(): void
    {
        $this->expectException(ParserException::class);

        $this->regexService->parse('/(?(VERSION>=1.a)yes|no)/');
    }

    public function test_conditional_recursion_with_minus_rewinds(): void
    {
        $this->expectException(ParserException::class);

        $this->regexService->parse('/(?(R-)yes|no)/');
    }

    public function test_conditional_bare_name_rewinds_when_not_closed(): void
    {
        $this->expectException(ParserException::class);

        $this->regexService->parse('/(?(foo+)yes|no)/');
    }

    public function test_numeric_subroutine_rewinds_on_invalid_suffix(): void
    {
        $this->expectException(ParserException::class);

        $this->regexService->parse('/(?1a)/');
    }

    public function test_invalid_group_modifier_syntax(): void
    {
        // Couvre le "Invalid group modifier syntax" à la fin de parseGroupModifier
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid group modifier syntax');
        $this->regexService->parse('/(??)/'); // Syntaxe invalide (??)
    }

    public function test_invalid_syntax_after_p(): void
    {
        // Couvre "Invalid syntax after (?P"
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid syntax after (?P');
        $this->regexService->parse('/(?Px)/'); // P suivi de x n'est pas valide
    }

    public function test_quantifier_without_target(): void
    {
        // Couvre "Quantifier without target" au début de parseQuantifiedAtom
        // (Cas : littéral vide généré par quelque chose d'autre, ou bug interne)
        $this->expectException(ParserException::class);
        $this->regexService->parse('/+/'); // + sans rien avant
    }

    public function test_quantifier_on_anchor(): void
    {
        // Couvre l'interdiction de quantifier une ancre
        $this->expectException(ParserException::class);
        $this->regexService->parse('/^* /');
    }

    public function test_missing_closing_delimiter(): void
    {
        // Couvre "No closing delimiter found"
        $this->expectException(ParserException::class);
        $this->regexService->parse('/abc');
    }

    public function test_unknown_flag(): void
    {
        // Couvre "Unknown regex flag"
        $this->expectException(ParserException::class);
        $this->regexService->parse('/abc/Z');
    }

    public function test_invalid_conditional_condition(): void
    {
        // Couvre le dernier else de parseConditionalCondition
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid conditional condition');
        // (?(?...) où le ? n'est ni = ni ! ni <
        $this->regexService->parse('/(?(?~a)b)/');
    }

    public function test_empty_character_class_creates_empty_literal(): void
    {
        $this->expectException(LexerException::class);

        $this->regexService->parse('/[]/');
    }

    public function test_conditional_condition_parses_lookaround_literal_question(): void
    {
        $parser = new Parser();
        $tokens = [
            new Token(TokenType::T_LITERAL, '?', 0),
            new Token(TokenType::T_LITERAL, '=', 1),
            new Token(TokenType::T_LITERAL, 'a', 2),
            new Token(TokenType::T_GROUP_CLOSE, ')', 3),
            new Token(TokenType::T_EOF, '', 4),
        ];
        $stream = new TokenStream($tokens, '?=a)');

        $ref = new \ReflectionClass($parser);
        $streamProp = $ref->getProperty('stream');
        $streamProp->setValue($parser, $stream);
        $patternProp = $ref->getProperty('pattern');
        $patternProp->setValue($parser, '?=a)');
        // parse() builds the name reader alongside the stream.
        $ref->getProperty('groupNames')->setValue($parser, new GroupNameReader($stream));

        $method = $ref->getMethod('parseConditionalCondition');
        $node = $method->invoke($parser);

        $this->assertInstanceOf(GroupNode::class, $node);
        $this->assertSame(GroupType::T_GROUP_LOOKAHEAD_POSITIVE, $node->type);
    }

    #[Test]
    public function test_a_conditional_condition_must_be_a_condition(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid conditional condition');

        $this->regexService->parse('/(?(?x)yes|no)/');
    }

    #[Test]
    public function test_a_bare_g_reference_is_refused(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid \\g reference syntax');

        $this->regexService->parse('/\\g/');
    }

    #[Test]
    public function test_a_python_group_name_must_not_be_empty(): void
    {
        $this->expectException(ParserException::class);

        $this->regexService->parse("/(?P''test)/");
    }

    #[Test]
    public function test_a_python_group_name_must_be_closed(): void
    {
        $this->expectException(ParserException::class);

        $this->regexService->parse("/(?P'name test)/");
    }

    #[Test]
    public function test_a_subroutine_name_must_not_be_empty(): void
    {
        $this->expectException(ParserException::class);

        $this->regexService->parse('/(?P>)/');
    }

    #[Test]
    public function test_a_quantifier_cannot_apply_to_an_assertion(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Quantifier "+" cannot be applied to assertion');

        $this->regexService->parse('/\\b+/');
    }

    #[Test]
    public function test_a_python_backreference_reads_as_a_named_backreference(): void
    {
        $ast = $this->regexService->parse('/(?<foo>a)(?P=foo)/');

        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $backref = $ast->pattern->children[1];
        $this->assertInstanceOf(BackrefNode::class, $backref);
        $this->assertSame('\\k<foo>', $backref->ref);
    }

    private function containsNode(mixed $node, string $class): bool
    {
        if ($node instanceof $class) {
            return true;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($this->containsNode($child, $class)) {
                    return true;
                }
            }
        }

        if (\is_object($node) && property_exists($node, 'child') && $this->containsNode($node->child, $class)) {
            return true;
        }

        if (\is_object($node) && property_exists($node, 'expression') && $this->containsNode($node->expression, $class)) {
            return true;
        }

        return false;
    }
}
