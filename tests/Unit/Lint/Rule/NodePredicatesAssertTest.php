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

use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The predicates that answer "is this node an X?" with a yes that means the
 * node IS an X: their docblocks owe callers an @phpstan-assert-if-true, the
 * half of the contract that lets the lint rules' instanceof branches narrow
 * for real — the true arm reads ->flags, ->type, ->value without re-asserting
 * the class. The Rewriter row is the same contract for an array of nodes:
 * when canAlternationBeCharClass() says yes, every alternative is a
 * LiteralNode, and the sole caller's ignored inline @var becomes real
 * narrowing.
 */
final class NodePredicatesAssertTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAssertingPredicates')]
    public function test_predicate_asserts_the_node_type_it_proved(string $method, string $expected): void
    {
        $assert = self::assertTag(NodePredicates::class, $method);

        $this->assertNotNull(
            $assert,
            sprintf(
                'NodePredicates::%s() must carry @phpstan-assert-if-true on $node: every return path that answers'
                .' true has instanceof-narrowed the node first, so the true arm of each caller — ImpossibleAnchorRule,'
                .' AlternationPrecedenceRule, LintContext among them — may read the node as the narrow type instead'
                .' of re-asserting it.',
                $method,
            ),
        );

        [$type, $parameter] = $assert;
        $this->assertSame('$node', $parameter);
        $this->assertSame(
            $expected,
            self::normalizeType($type, ...self::fileImports(NodePredicates::class)),
            sprintf('The @phpstan-assert-if-true of NodePredicates::%s() must narrow to %s.', $method, $expected),
        );
    }

    /**
     * @return iterable<string, array{method: string, expected: string}>
     */
    public static function provideAssertingPredicates(): iterable
    {
        yield 'standalone inline flags group' => [
            'method' => 'isStandaloneInlineFlagsGroup',
            'expected' => GroupNode::class,
        ];

        yield 'start anchor' => [
            'method' => 'isStartAnchorNode',
            'expected' => 'PHPRegex\Parser\Node\AnchorNode|PHPRegex\Parser\Node\AssertionNode',
        ];

        yield 'end anchor' => [
            'method' => 'isEndAnchorNode',
            'expected' => 'PHPRegex\Parser\Node\AnchorNode|PHPRegex\Parser\Node\AssertionNode',
        ];
    }

    #[Test]
    public function test_rewriter_char_class_check_asserts_literal_alternatives(): void
    {
        $assert = self::assertTag(Rewriter::class, 'canAlternationBeCharClass');

        $this->assertNotNull(
            $assert,
            'Rewriter::canAlternationBeCharClass() must carry @phpstan-assert-if-true on $alternatives: a true'
            .' answer means every alternative passed the LiteralNode branch of its loop, so the caller\'s inline'
            .' `@var array<Node\LiteralNode>` becomes real narrowing and can go.',
        );

        [$type, $parameter] = $assert;
        $this->assertSame('$alternatives', $parameter);
        $this->assertSame(
            1,
            preg_match('/^array<([\w\\\\]+)>$/', $type, $member),
            sprintf('The assert must name the array of nodes it proved, array<...>; it says %s.', $type),
        );

        [$useMap, $namespace] = self::fileImports(Rewriter::class);
        $this->assertSame(
            LiteralNode::class,
            self::resolveTypeName($member[1], $useMap, $namespace),
            'A true answer means every alternative is a single-character LiteralNode.',
        );
    }

    /**
     * The @phpstan-assert-if-true tag of a method, its type and parameter, or
     * null when the method declares none.
     *
     * @param class-string $class
     *
     * @return array{0: string, 1: string}|null
     */
    private static function assertTag(string $class, string $method): ?array
    {
        $docComment = (string) (new \ReflectionMethod($class, $method))->getDocComment();
        if (1 !== preg_match('/@phpstan-assert-if-true\s+(\S+)\s+(\$\w+)/', $docComment, $match)) {
            return null;
        }

        return [$match[1], $match[2]];
    }

    /**
     * @param array<string, string> $useMap
     */
    private static function normalizeType(string $type, array $useMap, string $namespace): string
    {
        $members = [];
        foreach (explode('|', $type) as $member) {
            $members[] = self::resolveTypeName($member, $useMap, $namespace);
        }

        $members = array_values(array_unique($members));
        sort($members);

        return implode('|', $members);
    }

    /**
     * A qualified name resolves through the first segment's import when there
     * is one ("Node\LiteralNode" through `use PHPRegex\Parser\Node;`),
     * through the namespace otherwise.
     *
     * @param array<string, string> $useMap
     */
    private static function resolveTypeName(string $name, array $useMap, string $namespace): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (str_contains($name, '\\')) {
            $first = explode('\\', $name)[0];
            if (isset($useMap[$first])) {
                return $useMap[$first].substr($name, \strlen($first));
            }

            return '' === $namespace ? $name : $namespace.'\\'.$name;
        }

        return $useMap[$name] ?? ('' === $namespace ? $name : $namespace.'\\'.$name);
    }

    /**
     * @param class-string $className
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function fileImports(string $className): array
    {
        $reflection = new \ReflectionClass($className);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $match)) {
            $namespace = $match[1];
        }

        $useMap = [];
        if (preg_match_all('/^use\s+([^;]+);/m', $source, $statements, \PREG_SET_ORDER)) {
            foreach ($statements as $statement) {
                foreach (explode(',', $statement[1]) as $import) {
                    $import = trim($import);
                    if ('' === $import || preg_match('/^(function|const)\s+/', $import)) {
                        continue;
                    }
                    if (preg_match('/^([\w\\\\]+)(?:\s+as\s+(\w+))?$/', $import, $parts)) {
                        $pos = strrpos($parts[1], '\\');
                        $short = false === $pos ? $parts[1] : substr($parts[1], $pos + 1);
                        $useMap[$parts[2] ?? $short] = ltrim($parts[1], '\\');
                    }
                }
            }
        }

        return [$useMap, $namespace];
    }
}
