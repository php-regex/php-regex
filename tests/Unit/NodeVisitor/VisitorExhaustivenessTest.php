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

use PhpRegex\Parser\Exception\RegexException;
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
use PhpRegex\Parser\NodeVisitorInterface;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards against visitor/interface drift: every concrete visitor must handle
 * every node type the parser can produce without hitting the null-returning
 * default of AbstractNodeVisitor (which explodes as a TypeError in visitors
 * with typed returns).
 */
final class VisitorExhaustivenessTest extends TestCase
{
    /**
     * A release that reads every construct the corpus holds, "(?[" included.
     */
    private const TARGET = ['pcre_version' => '10.49'];

    /**
     * Patterns chosen so that, together, they produce every node type the
     * parser can emit.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provide_node_covering_patterns(): iterable
    {
        $patterns = [
            '/ab/',
            '/a|b/',
            '/(a)+?/',
            '/[a-z]/',
            '/\d./',
            '/^a$/',
            '/\ba\K/',
            '/(a)\1/',
            '/[a&&b]/',
            '/\cA/',
            '/(*sr:\p{Greek}+)/u',
            '/(?(VERSION>=10.4)a|b)/',
            '/\p{L}/u',
            '/[[:alpha:]]/',
            '/(?#c)a/',
            '/(?(1)a|b)(x)/',
            '/(a)(?1)/',
            '/(*FAIL)/',
            '/(?(DEFINE)(?<d>\d))(?&d)/',
            '/(*LIMIT_MATCH=10)a/',
            '/(?C1)a/',
            '/\x{1F600}/u',
            '/(?[ \d - ([3] & ![:alpha:]) ])/',
        ];

        foreach ($patterns as $pattern) {
            yield $pattern => ['pattern' => $pattern];
        }
    }

    #[Test]
    #[DataProvider('provide_node_covering_patterns')]
    public function test_every_visitor_handles_every_parser_producible_node(string $pattern): void
    {
        $ast = Regex::create(self::TARGET)->parse($pattern);

        $visited = 0;
        foreach (self::instantiableVisitors() as $class => $visitor) {
            try {
                $ast->accept($visitor);
            } catch (RegexException) {
                // Domain errors (e.g. semantic validation) are acceptable here;
                // this test only guards against engine-level crashes.
            } catch (\Error $e) {
                $this->fail(\sprintf(
                    '%s crashed on %s: %s',
                    $class,
                    $pattern,
                    $e->getMessage(),
                ));
            }
            $visited++;
        }

        $this->assertGreaterThan(0, $visited);
    }

    /**
     * Constructs one instance of EVERY concrete node type and runs every
     * visitor over it, so a new node type cannot silently fall through to the
     * null default of AbstractNodeVisitor in any typed visitor.
     */
    #[Test]
    public function test_every_visitor_handles_synthetic_instances_of_all_node_types(): void
    {
        $literal = new LiteralNode('a', 0, 1);
        $nodes = [
            new AlternationNode([$literal], 0, 1),
            new AnchorNode('^', 0, 1),
            new AssertionNode('b', 0, 2),
            new BackrefNode('\\1', 0, 2),
            new CalloutNode(1, false, 0, 5),
            new CharClassNode($literal, false, 0, 3),
            new CharLiteralNode('\\x41', 65, CharLiteralType::Unicode, 0, 4),
            new CharTypeNode('d', 0, 2),
            new ClassSetOperationNode(ClassSetOperator::Difference, new CharTypeNode('d', 0, 2), $literal, '-', 0, 4),
            new ClassSetOperationNode(ClassSetOperator::Complement, null, new CharTypeNode('d', 1, 3), '!', 0, 3),
            new CommentNode('c', 0, 5),
            new ConditionalNode($literal, $literal, $literal, 0, 9),
            new ControlCharNode('A', 1, 0, 3),
            new DefineNode($literal, 0, 12),
            new DotNode(0, 1),
            new ExtendedCharClassNode(new CharTypeNode('d', 3, 5), 0, 7),
            new GroupNode($literal, GroupType::Capturing, null, null, 0, 3),
            new KeepNode(0, 2),
            new LimitMatchNode(10, 0, 16),
            $literal,
            new PcreVerbNode('FAIL', 0, 7),
            new PosixClassNode('alpha', 0, 9),
            new QuantifierNode($literal, '+', QuantifierType::Greedy, 0, 2),
            new RangeNode($literal, new LiteralNode('z', 2, 3), 0, 3),
            new ScriptRunNode('Greek', 0, 12),
            new SequenceNode([$literal], 0, 1),
            new SubroutineNode('1', 'g', 0, 5),
            new UnicodePropNode('L', false, 0, 3),
            new VersionConditionNode('>=', '10.4', 0, 16),
        ];
        $nodes[] = new RegexNode($literal, '', '/', 0, 3);

        // Every concrete node type must appear above.
        $covered = array_map(static fn (NodeInterface $n): string => $n::class, $nodes);
        foreach (glob(__DIR__.'/../../../src/Parser/Node/*Node.php') ?: [] as $file) {
            $class = 'PhpRegex\Parser\Node\\'.basename($file, '.php');
            if (!is_subclass_of($class, NodeInterface::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $this->assertContains($class, $covered, 'Add a synthetic instance for '.$class);
        }

        foreach (self::instantiableVisitors() as $class => $visitor) {
            foreach ($nodes as $node) {
                try {
                    $node->accept($visitor);
                } catch (RegexException|\LogicException|\RuntimeException) {
                    // Domain errors are fine; we only guard against engine-level crashes.
                } catch (\Error $e) {
                    $this->fail(\sprintf('%s crashed on %s: %s', $class, $node::class, $e->getMessage()));
                }
            }
        }
    }

    #[Test]
    public function test_pattern_corpus_covers_all_parser_producible_node_types(): void
    {
        $seen = [];
        $regex = Regex::create(self::TARGET);

        foreach (self::provide_node_covering_patterns() as ['pattern' => $pattern]) {
            $this->collectNodeTypes($regex->parse($pattern), $seen);
        }

        $missing = [];
        $deprecatedSeen = [];
        foreach (glob(__DIR__.'/../../../src/Parser/Node/*Node.php') ?: [] as $file) {
            $class = 'PhpRegex\Parser\Node\\'.basename($file, '.php');
            if (!is_subclass_of($class, NodeInterface::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            // A deprecated node is kept for compatibility and the parser no
            // longer builds it: it must not show up at all.
            if (str_contains((string) (new \ReflectionClass($class))->getDocComment(), '@deprecated')) {
                if (isset($seen[$class])) {
                    $deprecatedSeen[] = $class;
                }

                continue;
            }
            if (!isset($seen[$class])) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'Corpus does not cover these node types; extend the pattern list.');
        $this->assertSame([], $deprecatedSeen, 'The parser built a deprecated node type.');
    }

    /**
     * @return iterable<class-string, \PhpRegex\Parser\NodeVisitorInterface<mixed>>
     */
    private static function instantiableVisitors(): iterable
    {
        $src = \dirname(__DIR__, 3).'/src/';
        $files = [];
        foreach (['Parser', 'Explain', 'Optimizer', 'Generator', 'Redos', 'Linter', 'Transpiler'] as $package) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src.$package, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        foreach ($files as $file) {
            $class = 'PhpRegex\\'.str_replace('/', '\\', substr($file, \strlen($src), -4));
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(NodeVisitorInterface::class)) {
                continue;
            }
            if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                continue;
            }

            $visitor = $reflection->newInstance();
            Assert::assertInstanceOf(NodeVisitorInterface::class, $visitor);

            yield $class => $visitor;
        }
    }

    /**
     * @param array<class-string, true> $seen
     */
    private function collectNodeTypes(NodeInterface $node, array &$seen): void
    {
        $seen[$node::class] = true;

        foreach ((array) $node as $value) {
            if ($value instanceof NodeInterface) {
                $this->collectNodeTypes($value, $seen);
            } elseif (\is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof NodeInterface) {
                        $this->collectNodeTypes($item, $seen);
                    }
                }
            }
        }
    }
}
