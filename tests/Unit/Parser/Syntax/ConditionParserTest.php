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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\ConditionParser;
use PHPRegex\Parser\Syntax\GroupNameReader;
use PHPRegex\Parser\Syntax\ParseState;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a conditional, "(?(condition)yes|no)", from its condition on.
 *
 * The branches, the lookarounds and the verbs are read by the token parser,
 * which the reader calls back; here they are read by a small stand-in that
 * takes literals only.
 */
final class ConditionParserTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $recursionTests = [];

    #[Test]
    public function test_conditional_reads_a_group_number_and_both_branches(): void
    {
        $this->assertEquals(
            new ConditionalNode(new BackrefNode('1', 3, 4), new LiteralNode('a', 5, 6), new LiteralNode('b', 7, 8), 0, 9),
            $this->conditional('(?(1)a|b)', 1),
        );
    }

    #[Test]
    public function test_conditional_gives_an_empty_no_branch_where_the_yes_branch_ends(): void
    {
        $this->assertEquals(
            new ConditionalNode(new BackrefNode('1', 3, 4), new LiteralNode('a', 5, 6), new LiteralNode('', 6, 6), 0, 7),
            $this->conditional('(?(1)a)', 1),
        );
    }

    #[Test]
    public function test_conditional_keeps_further_branches_as_the_no_branch(): void
    {
        $node = $this->conditional('(?(1)a|b|c)', 1);

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new AlternationNode([new LiteralNode('b', 7, 8), new LiteralNode('c', 9, 10)], 7, 10), $node->no);
    }

    #[Test]
    public function test_define_is_no_condition_but_a_definition(): void
    {
        $this->assertEquals(new DefineNode(new LiteralNode('a', 10, 11), 0, 12), $this->conditional('(?(DEFINE)a)'));
    }

    #[Test]
    public function test_a_named_condition_in_angle_brackets_or_quotes(): void
    {
        $angle = $this->conditional('(?(<n>)a)');
        $quoted = $this->conditional("(?('n')a)");

        $this->assertInstanceOf(ConditionalNode::class, $angle);
        $this->assertInstanceOf(ConditionalNode::class, $quoted);
        $this->assertEquals(new BackrefNode('n', 3, 6), $angle->condition);
        $this->assertEquals(new BackrefNode('n', 3, 6), $quoted->condition);
    }

    #[Test]
    public function test_a_bare_name_condition(): void
    {
        $node = $this->conditional('(?(name)a)');

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new BackrefNode('name', 3, 7), $node->condition);
    }

    #[Test]
    public function test_a_version_condition(): void
    {
        $node = $this->conditional('(?(VERSION>=10.4)a)');

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new VersionConditionNode('>=', '10.4', 3, 15), $node->condition);
    }

    #[Test]
    public function test_a_recursion_test_is_reported_for_the_second_reading(): void
    {
        $node = $this->conditional('(?(R2)a)');

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new SubroutineNode('R2', '', 3, 4), $node->condition);
        $this->assertSame(['R2'], $this->recursionTests);
    }

    #[Test]
    public function test_a_group_named_like_a_recursion_test_is_the_group(): void
    {
        $node = $this->conditional('(?(R2)a)', 0, ['R2']);

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new BackrefNode('R2', 3, 5), $node->condition);
        $this->assertSame([], $this->recursionTests);
    }

    #[Test]
    public function test_a_recursion_into_a_named_group(): void
    {
        $node = $this->conditional('(?(R&n)a)');

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new SubroutineNode('R&n', '', 3, 5), $node->condition);
        $this->assertSame([], $this->recursionTests);
    }

    #[Test]
    public function test_a_lookaround_condition_is_read_by_the_token_parser(): void
    {
        $node = $this->conditional('(?(?=a)b)', 0, [], true);

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new GroupNode(new LiteralNode('a', 5, 6), GroupType::LookaheadPositive, null, null, 2, 7), $node->condition);
    }

    #[Test]
    public function test_a_lookbehind_condition_is_read_by_the_token_parser(): void
    {
        $node = $this->conditional('(?(?<!a)b)', 0, [], true);

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new GroupNode(new LiteralNode('a', 6, 7), GroupType::LookbehindNegative, null, null, 2, 8), $node->condition);
    }

    #[Test]
    public function test_a_callout_before_the_assertion_is_part_of_the_condition(): void
    {
        $pattern = '(?(?C1)(?=a)b)';
        [$reader, $stream] = $this->reader($pattern);
        $stream->advance();
        $stream->advance();

        $node = $reader->calloutConditional(0);

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(
            new SequenceNode([new CalloutNode(1, false, 2, 7), new GroupNode(new LiteralNode('a', 10, 11), GroupType::LookaheadPositive, null, null, 7, 12)], 2, 12),
            $node->condition,
        );
    }

    #[Test]
    public function test_a_comment_before_the_assertion_is_skipped(): void
    {
        [$reader, $stream] = $this->reader('(?(?#c)(?=a)b)');
        $stream->advance();

        $node = $reader->commentedConditional(0);

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new GroupNode(new LiteralNode('a', 10, 11), GroupType::LookaheadPositive, null, null, 7, 12), $node->condition);
    }

    #[Test]
    public function test_a_lookaround_spelled_as_a_verb_is_read_by_the_token_parser(): void
    {
        [$reader, $stream] = $this->reader('(?(*pla:a)b)');
        $stream->advance();
        $stream->advance();

        $node = $reader->verbConditional(0, $stream->previous());

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new PcreVerbNode('pla:a', 2, 10), $node->condition);
    }

    /**
     * Where PCRE refuses a condition, read from the running engine.
     */
    #[Test]
    #[DataProvider('provideRefusedConditions')]
    public function test_a_refused_condition_is_refused_where_pcre_refuses_it(string $pattern, string $entry, ErrorCode $code): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf('/'.$pattern.'/') ?? 'compiles');
        [$reader, $stream] = $this->reader($pattern, PcreTarget::runtime());

        try {
            match ($entry) {
                'condition' => (static function () use ($reader, $stream): void {
                    $stream->advance();
                    $stream->advance();
                    $reader->conditional(0, false, 0);
                })(),
                'callout' => (static function () use ($reader, $stream): void {
                    $stream->advance();
                    $stream->advance();
                    $reader->calloutConditional(0);
                })(),
                'comment' => (static function () use ($reader, $stream): void {
                    $stream->advance();
                    $reader->commentedConditional(0);
                })(),
                default => (static function () use ($reader, $stream): void {
                    $stream->advance();
                    $stream->advance();
                    $reader->verbConditional(0, $stream->previous());
                })(),
            };
            $this->fail('No error for '.$pattern);
        } catch (ParserException $error) {
            $this->assertSame([$code, $pcre['offset']], [$error->errorCode, $error->position], 'Oracle: '.$pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, entry: string, code: ErrorCode}>
     */
    public static function provideRefusedConditions(): iterable
    {
        // PCRE2 10.49: "/(?(65536)a)/" at offset 8, "/(?(-1)a)/" at 5,
        // "/(?(ab!)x)/" at 5, "/(?(R&n x)a)/" at 6, "/(?(.)a)/" at 3,
        // "/(?(VERSION=10z)a)/" at 14, "/(?(?C1)a)/" at 7,
        // "/(?(?C256)(?=a)b)/" at 8, "/(?(?#c)a)/" at 8 and
        // "/(?(*MARK:x)a)/" at 3.
        yield 'a number too big' => ['pattern' => '(?(65536)a)', 'entry' => 'condition', 'code' => ErrorCode::GroupNumberTooBig];
        yield 'counting back past the groups' => ['pattern' => '(?(-1)a)', 'entry' => 'condition', 'code' => ErrorCode::BackrefRelative];
        yield 'a name nothing closes' => ['pattern' => '(?(ab!)x)', 'entry' => 'condition', 'code' => ErrorCode::GroupNameUnterminated];
        yield 'a recursion name nothing closes' => ['pattern' => '(?(R&n x)a)', 'entry' => 'condition', 'code' => ErrorCode::GroupNameUnterminated];
        yield 'no condition' => ['pattern' => '(?(.)a)', 'entry' => 'condition', 'code' => ErrorCode::ConditionalInvalid];
        // Before PCRE2 10.47 what follows the major number leaves the
        // condition open.
        yield 'a version PCRE cannot read' => [
            'pattern' => '(?(VERSION=10z)a)',
            'entry' => 'condition',
            'code' => PcreTarget::runtime()->supports(PcreFeature::VersionConditionLeftOpenIsVersionError) ? ErrorCode::ConditionalVersionSyntax : ErrorCode::ConditionalUnclosed,
        ];
        yield 'no assertion after a callout' => ['pattern' => '(?(?C1)a)', 'entry' => 'callout', 'code' => ErrorCode::ConditionalAssertionExpected];
        yield 'a callout number past 255' => ['pattern' => '(?(?C256)(?=a)b)', 'entry' => 'callout', 'code' => ErrorCode::CalloutOutOfRange];
        yield 'no assertion after a comment' => ['pattern' => '(?(?#c)a)', 'entry' => 'comment', 'code' => ErrorCode::ConditionalAssertionExpected];
        yield 'a verb that is no lookaround' => ['pattern' => '(?(*MARK:x)a)', 'entry' => 'verb', 'code' => ErrorCode::ConditionalAssertionExpected];
    }

    #[Test]
    public function test_a_relative_condition_counts_the_groups_opened_before(): void
    {
        $node = $this->conditional('(?(-1)a)', 1);

        $this->assertInstanceOf(ConditionalNode::class, $node);
        $this->assertEquals(new BackrefNode('-1', 3, 5), $node->condition);
    }

    #[Test]
    public function test_a_forward_condition_past_65535_groups_in_all_is_refused(): void
    {
        try {
            $this->conditional('(?(+2)a)', 65534);
            $this->fail('No error.');
        } catch (ParserException $error) {
            $this->assertSame([ErrorCode::GroupNumberTooBig, 5], [$error->errorCode, $error->position]);
        }
    }

    #[Test]
    public function test_define_reads_an_assertion_node(): void
    {
        $node = $this->conditional('(?(DEFINE))');

        $this->assertInstanceOf(DefineNode::class, $node);
        $this->assertEquals(new LiteralNode('', 10, 10), $node->content);
        $this->assertNotInstanceOf(AssertionNode::class, $node->content);
    }

    /**
     * @param list<string> $patternNames
     */
    private function conditional(string $pattern, int $groupsBefore = 0, array $patternNames = [], bool $lookaround = false): NodeInterface
    {
        [$reader, $stream] = $this->reader($pattern, null, $patternNames);
        $stream->advance();
        $stream->advance();

        return $reader->conditional(0, $lookaround, $groupsBefore);
    }

    /**
     * @param list<string> $patternNames
     *
     * @return array{ConditionParser, TokenStream}
     */
    private function reader(string $pattern, ?PcreTarget $target = null, array $patternNames = []): array
    {
        $target ??= new PcreTarget(80500, '10.49');
        $stream = (new Lexer($target))->tokenize($pattern);
        $state = new ParseState($stream, $pattern, $target, false, false);
        $groupNames = new GroupNameReader($stream);
        $branches = static function () use ($stream): NodeInterface {
            $branches = [];
            $start = $stream->current()->position;
            $text = '';
            while (!$stream->check(TokenType::GroupClose) && !$stream->isAtEnd()) {
                if ($stream->match(TokenType::Alternation)) {
                    $branches[] = '' === $text ? new LiteralNode('', $start, $start) : new LiteralNode($text, $start, $start + \strlen($text));
                    $start = $stream->current()->position;
                    $text = '';

                    continue;
                }

                $text .= $stream->current()->value;
                $stream->advance();
            }
            $branches[] = '' === $text ? new LiteralNode('', $start, $start) : new LiteralNode($text, $start, $start + \strlen($text));

            return 1 === \count($branches) ? $branches[0] : new AlternationNode($branches, $branches[0]->getStartPosition(), $branches[\count($branches) - 1]->getEndPosition());
        };
        $lookaround = static function (int $start, bool $behind) use ($stream, $branches): ?GroupNode {
            $kinds = $behind
                ? ['=' => GroupType::LookbehindPositive, '!' => GroupType::LookbehindNegative]
                : ['=' => GroupType::LookaheadPositive, '!' => GroupType::LookaheadNegative];
            foreach ($kinds as $literal => $type) {
                if ($stream->matchLiteral($literal)) {
                    $body = $branches();
                    $end = $stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

                    return new GroupNode($body, $type, null, null, $start, $end->position + 1);
                }
            }

            return null;
        };

        $reader = new ConditionParser(
            $state,
            $groupNames,
            $patternNames,
            $branches,
            $lookaround,
            static fn (Token $token, int $start): NodeInterface => new PcreVerbNode($token->value, $start, $token->end()),
            static fn (): int => 0,
            function (string $name): void {
                $this->recursionTests[] = $name;
            },
        );

        return [$reader, $stream];
    }
}
