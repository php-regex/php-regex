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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\AbstractTraversingVisitor;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\ClassSetOperator;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A visitor that overrides one node type still sees that type wherever it
 * stands: the base descends into the children of every node, the node types
 * a minor release adds included.
 */
final class AbstractTraversingVisitorTest extends TestCase
{
    #[Test]
    public function test_it_is_a_node_visitor_that_returns_null_by_default(): void
    {
        $visitor = new BackrefCollector();
        $tree = $this->parse('/(a)\1/');

        $this->assertInstanceOf(AbstractNodeVisitor::class, $visitor);
        $this->assertNull($tree->accept($visitor));
        $this->assertSame(['\1'], $visitor->refs);
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideNestedBackrefs')]
    public function test_a_visitor_overriding_only_backrefs_finds_every_backref(string $pattern, array $expected): void
    {
        $this->assertNotFalse(@preg_match($pattern, 'aaa'), 'PCRE refuses '.$pattern);

        $tree = $this->parse($pattern);
        $visitor = new BackrefCollector();
        $tree->accept($visitor);

        $this->assertSame($expected, $visitor->refs, $pattern);
        $this->assertCount(\count(self::heldBackrefs($tree)), $visitor->refs, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<string>}>
     */
    public static function provideNestedBackrefs(): iterable
    {
        yield 'sequence' => ['pattern' => '/(a)(b)\1\2/', 'expected' => ['\1', '\2']];
        yield 'capturing group' => ['pattern' => '/(a)(\1)/', 'expected' => ['\1']];
        yield 'non-capturing group' => ['pattern' => '/(a)(?:\1)/', 'expected' => ['\1']];
        yield 'named group and named reference' => ['pattern' => '/(?<n>a)(?<m>\k<n>)/', 'expected' => ['\k<n>']];
        yield 'atomic group' => ['pattern' => '/(a)(?>\1)/', 'expected' => ['\1']];
        yield 'inline flags group' => ['pattern' => '/(a)(?i:\1)/', 'expected' => ['\1']];
        yield 'branch reset' => ['pattern' => '/(a)(?|\1|b)/', 'expected' => ['\1']];
        yield 'alternation' => ['pattern' => '/(a)(?:x|\1|y\1)/', 'expected' => ['\1', '\1']];
        yield 'quantifier' => ['pattern' => '/(a)(?:\1)+/', 'expected' => ['\1']];
        yield 'possessive quantifier over an alternation' => ['pattern' => '/(a)(?:\1|x)*+/', 'expected' => ['\1']];
        yield 'quantified backreference' => ['pattern' => '/(a)\1{2}/', 'expected' => ['\1']];
        yield 'lookahead' => ['pattern' => '/(a)(?=\1)/', 'expected' => ['\1']];
        yield 'negative lookahead' => ['pattern' => '/(a)(?!\1\1\1)/', 'expected' => ['\1', '\1', '\1']];
        yield 'lookbehind' => ['pattern' => '/(a)(?<=\1)/', 'expected' => ['\1']];
        yield 'conditional on a group number' => ['pattern' => '/(a)(?(1)\1|b)/', 'expected' => ['1', '\1']];
        yield 'conditional on a lookahead' => ['pattern' => '/(a)(?(?=\1)\1|\1)/', 'expected' => ['\1', '\1', '\1']];
        yield 'conditional on recursion' => ['pattern' => '/(a)(?(R1)\1)/', 'expected' => ['\1']];
        yield 'define' => ['pattern' => '/(a)(?(DEFINE)(?<d>\1))/', 'expected' => ['\1']];
        yield 'script run' => ['pattern' => '/(a)(*sr:\1)/', 'expected' => ['\1']];
        yield 'atomic script run' => ['pattern' => '/(a)(*atomic_script_run:(?:\1))/', 'expected' => ['\1']];
        yield 'deep nesting' => ['pattern' => '/(a)(?:(?=(?:x|(?>\1+))?)b)*/', 'expected' => ['\1']];
    }

    #[Test]
    public function test_backrefs_are_reached_in_pattern_order(): void
    {
        $visitor = new BackrefCollector();
        $this->parse('/(a)(b)(c)(?:\3|(?=\1))\2/')->accept($visitor);

        $this->assertSame(['\3', '\1', '\2'], $visitor->refs);
    }

    #[Test]
    public function test_an_override_that_calls_the_parent_keeps_descending(): void
    {
        $visitor = new GroupCountingBackrefCollector(prune: false);
        $this->parse('/(a)(?:x(?:\1))/')->accept($visitor);

        $this->assertSame(3, $visitor->groups);
        $this->assertSame(['\1'], $visitor->refs);
    }

    #[Test]
    public function test_an_override_that_does_not_call_the_parent_skips_the_subtree(): void
    {
        $visitor = new GroupCountingBackrefCollector(prune: true);
        $this->parse('/(a)(?:x(?:\1))\1/')->accept($visitor);

        $this->assertSame(2, $visitor->groups);
        $this->assertSame(['\1'], $visitor->refs);
    }

    #[Test]
    public function test_a_class_like_node_is_descended_too(): void
    {
        $visitor = new LiteralCountingVisitor();
        $this->parse('/[a-c\d[:alpha:]x]/')->accept($visitor);

        $this->assertSame(['a', 'c', 'x'], $visitor->characters);
    }

    #[Test]
    public function test_an_extended_class_is_descended_too(): void
    {
        // PCRE2 reads "(?[" from 10.45 on; the tree is the one it builds there.
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '>=')) {
            $this->assertSame(1, self::engineMatch('/(?[ [a-c] - [b] ])/', 'c'));
        }

        $visitor = new LiteralCountingVisitor();
        $this->parse('/(?[ [a-c] - [b] ])/')->accept($visitor);

        $this->assertSame(['a', 'c', 'b'], $visitor->characters);
    }

    /**
     * Every node class, with a marker in each place a child can stand: the
     * visitor reaches every marker the node holds, whatever holds it. A new
     * node type fails here until it is added, and then until the base
     * descends into it.
     */
    #[Test]
    #[DataProvider('provideEveryNodeClass')]
    public function test_every_node_class_has_its_children_visited(NodeInterface $node): void
    {
        $visitor = new BackrefCollector();
        $node->accept($visitor);

        $held = array_map(static fn (BackrefNode $ref): string => $ref->ref, self::heldBackrefs($node));
        sort($held);
        $reached = $visitor->refs;
        sort($reached);

        $this->assertSame($held, $reached, $node::class);
    }

    /**
     * @return iterable<string, array{NodeInterface}>
     */
    public static function provideEveryNodeClass(): iterable
    {
        foreach (self::everyNodeClass() as $index => $node) {
            yield $index.' '.$node::class => [$node];
        }
    }

    #[Test]
    public function test_every_concrete_node_class_is_exercised(): void
    {
        $covered = array_map(static fn (NodeInterface $node): string => $node::class, self::everyNodeClass());
        $concrete = [];
        foreach (glob(\dirname(__DIR__, 3).'/src/Parser/Node/*.php') ?: [] as $file) {
            $class = 'PhpRegex\Parser\Node\\'.basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, NodeInterface::class)) {
                continue;
            }
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $concrete[] = $class;
        }

        $this->assertNotEmpty($concrete);
        foreach ($concrete as $class) {
            $this->assertContains($class, $covered, 'Add an instance of '.$class.' with a marker in each child.');
        }
    }

    #[Test]
    public function test_every_visit_method_of_the_interface_is_overridden(): void
    {
        $traversing = new \ReflectionClass(AbstractTraversingVisitor::class);

        foreach ((new \ReflectionClass(AbstractNodeVisitor::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'visit')) {
                continue;
            }

            $this->assertSame(
                AbstractTraversingVisitor::class,
                $traversing->getMethod($method->getName())->getDeclaringClass()->getName(),
                $method->getName().' must descend into the node\'s children.',
            );
        }
    }

    /**
     * @return list<NodeInterface>
     */
    private static function everyNodeClass(): array
    {
        $counter = 0;
        $marker = static function () use (&$counter): BackrefNode {
            return new BackrefNode('m'.++$counter, 0, 1);
        };

        return [
            new AlternationNode([$marker(), $marker(), $marker()], 0, 5),
            new AnchorNode('^', 0, 1),
            new AssertionNode('b', 0, 2),
            $marker(),
            new CalloutNode(1, false, 0, 5),
            new CharClassNode($marker(), false, 0, 4),
            new CharLiteralNode('\x41', 0x41, CharLiteralType::Unicode, 0, 4),
            new CharTypeNode('d', 0, 2),
            new ClassSetOperationNode(ClassSetOperator::Union, $marker(), $marker(), '|', 0, 5),
            new ClassSetOperationNode(ClassSetOperator::Complement, null, $marker(), '!', 0, 3),
            new CommentNode('note', 0, 8),
            new ConditionalNode($marker(), $marker(), $marker(), 0, 9),
            new ControlCharNode('M', 13, 0, 3),
            new DefineNode($marker(), 0, 12),
            new DotNode(0, 1),
            new ExtendedCharClassNode($marker(), 0, 6),
            new GroupNode($marker(), GroupType::Capturing, null, null, 0, 4),
            new KeepNode(0, 2),
            new LimitMatchNode(10, 0, 15),
            new LiteralNode('a', 0, 1),
            new PcreVerbNode('FAIL', 0, 7),
            new PosixClassNode('alpha', 0, 9),
            new QuantifierNode($marker(), '+', QuantifierType::Greedy, 0, 3),
            new RangeNode($marker(), $marker(), 0, 3),
            new RegexNode($marker(), '', '/', 0, 4),
            new ScriptRunNode('sr', 0, 7, $marker()),
            new ScriptRunNode('sr', 0, 5),
            new SequenceNode([$marker(), $marker()], 0, 4),
            new SubroutineNode('1', '', 0, 4),
            new UnicodePropNode('L', true, 0, 5),
            new VersionConditionNode('>=', '10.4', 0, 16),
        ];
    }

    /**
     * The backreferences a node holds, read from its properties rather than
     * from getChildren(): a node that hides a child from both is caught.
     *
     * @return list<BackrefNode>
     */
    private static function heldBackrefs(NodeInterface $node): array
    {
        $found = $node instanceof BackrefNode ? [$node] : [];

        foreach (get_object_vars($node) as $value) {
            foreach (\is_array($value) ? $value : [$value] as $item) {
                if ($item instanceof NodeInterface) {
                    array_push($found, ...self::heldBackrefs($item));
                }
            }
        }

        return $found;
    }

    private function parse(string $pattern): RegexNode
    {
        return RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse($pattern);
    }

    /**
     * The running engine on a pattern a static analyser, running another
     * PCRE2, must not judge from the literal.
     */
    private static function engineMatch(string $pattern, string $subject): int|false
    {
        return preg_match($pattern, $subject);
    }
}

/**
 * @extends AbstractTraversingVisitor<null>
 */
final class BackrefCollector extends AbstractTraversingVisitor
{
    /**
     * @var list<string>
     */
    public array $refs = [];

    public function visitBackref(BackrefNode $node)
    {
        $this->refs[] = $node->ref;

        return parent::visitBackref($node);
    }
}

/**
 * @extends AbstractTraversingVisitor<null>
 */
final class GroupCountingBackrefCollector extends AbstractTraversingVisitor
{
    public int $groups = 0;

    /**
     * @var list<string>
     */
    public array $refs = [];

    public function __construct(private readonly bool $prune) {}

    public function visitGroup(GroupNode $node)
    {
        $this->groups++;

        if ($this->prune && GroupType::NonCapturing === $node->type) {
            return null;
        }

        return parent::visitGroup($node);
    }

    public function visitBackref(BackrefNode $node)
    {
        $this->refs[] = $node->ref;

        return null;
    }
}

/**
 * @extends AbstractTraversingVisitor<null>
 */
final class LiteralCountingVisitor extends AbstractTraversingVisitor
{
    /**
     * @var list<string>
     */
    public array $characters = [];

    public function visitLiteral(LiteralNode $node)
    {
        $this->characters[] = $node->value;

        return null;
    }
}
