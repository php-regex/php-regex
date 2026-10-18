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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\VersionCondition;
use PHPRegex\Tests\TestUtils\JsonSchemaSubsetValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tree as data: one versioned document, node kinds under stable names
 * rather than class names, byte offsets into the pattern, and the shipped
 * schema every export conforms to.
 */
final class JsonDumperTest extends TestCase
{
    /**
     * Every comparison the parser reads after VERSION.
     */
    private const VERSION_OPERATORS = ['=', '>=', '>', '<=', '<', '==', '!='];

    private const SCHEMA = __DIR__.'/../../../../src/Parser/ast.schema.json';

    #[Test]
    public function test_the_document_carries_its_version_and_the_pattern(): void
    {
        $this->assertSame([
            'version' => 1,
            'type' => 'regex',
            'start' => 0,
            'end' => 9,
            'delimiter' => '/',
            'flags' => 'i',
            'source' => 'a{2,3}?\d',
            'pattern' => [
                'type' => 'sequence',
                'start' => 0,
                'end' => 9,
                'children' => [
                    [
                        'type' => 'quantifier',
                        'start' => 0,
                        'end' => 7,
                        'min' => 2,
                        'max' => 3,
                        'mode' => 'lazy',
                        'child' => ['type' => 'literal', 'start' => 0, 'end' => 1, 'value' => 'a'],
                    ],
                    ['type' => 'char_type', 'start' => 7, 'end' => 9, 'value' => 'd'],
                ],
            ],
        ], self::export('/a{2,3}?\d/i'));
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideNodes')]
    public function test_each_kind_of_node_is_exported(string $pattern, array $expected): void
    {
        $this->assertSame($expected, self::export($pattern)['pattern']);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: array<string, mixed>}>
     */
    public static function provideNodes(): iterable
    {
        yield 'dot' => ['pattern' => '/./', 'expected' => ['type' => 'dot', 'start' => 0, 'end' => 1]];
        yield 'anchor' => ['pattern' => '/^/', 'expected' => ['type' => 'anchor', 'start' => 0, 'end' => 1, 'value' => '^']];
        yield 'assertion' => ['pattern' => '/\b/', 'expected' => ['type' => 'assertion', 'start' => 0, 'end' => 2, 'value' => 'b']];
        yield 'keep' => ['pattern' => '/\K/', 'expected' => ['type' => 'keep', 'start' => 0, 'end' => 2]];
        yield 'character type' => ['pattern' => '/\R/', 'expected' => ['type' => 'char_type', 'start' => 0, 'end' => 2, 'value' => 'R']];
        yield 'quoted literal' => ['pattern' => '/\Qa.b\E/', 'expected' => ['type' => 'literal', 'start' => 2, 'end' => 5, 'value' => 'a.b']];
        yield 'literal bytes that are not UTF-8' => [
            'pattern' => "/\xFF/",
            'expected' => ['type' => 'literal', 'start' => 0, 'end' => 1, 'value' => "\u{FFFD}", 'bytes' => 'ff'],
        ];
        yield 'possessive quantifier' => [
            'pattern' => '/a*+/',
            'expected' => [
                'type' => 'quantifier', 'start' => 0, 'end' => 3, 'min' => 0, 'max' => null, 'mode' => 'possessive',
                'child' => ['type' => 'literal', 'start' => 0, 'end' => 1, 'value' => 'a'],
            ],
        ];
        yield 'alternation' => [
            'pattern' => '/a|b/',
            'expected' => [
                'type' => 'alternation', 'start' => 0, 'end' => 3,
                'alternatives' => [
                    ['type' => 'literal', 'start' => 0, 'end' => 1, 'value' => 'a'],
                    ['type' => 'literal', 'start' => 2, 'end' => 3, 'value' => 'b'],
                ],
            ],
        ];
        yield 'class, range and property' => [
            'pattern' => '/[^a-z\pL]/',
            'expected' => [
                'type' => 'char_class', 'start' => 0, 'end' => 9, 'negated' => true,
                'members' => [
                    [
                        'type' => 'range', 'start' => 2, 'end' => 5,
                        'from' => ['type' => 'literal', 'start' => 2, 'end' => 3, 'value' => 'a'],
                        'to' => ['type' => 'literal', 'start' => 4, 'end' => 5, 'value' => 'z'],
                    ],
                    ['type' => 'unicode_property', 'start' => 5, 'end' => 8, 'name' => 'L', 'negated' => false],
                ],
            ],
        ];
        yield 'negated POSIX class' => [
            'pattern' => '/[[:^alpha:]]/',
            'expected' => [
                'type' => 'char_class', 'start' => 0, 'end' => 12, 'negated' => false,
                'members' => [['type' => 'posix_class', 'start' => 1, 'end' => 11, 'name' => 'alpha', 'negated' => true]],
            ],
        ];
        yield 'negated properties, however written' => [
            'pattern' => '/\PL\P{^L}/',
            'expected' => [
                'type' => 'sequence', 'start' => 0, 'end' => 9,
                'children' => [
                    ['type' => 'unicode_property', 'start' => 0, 'end' => 3, 'name' => 'L', 'negated' => true],
                    ['type' => 'unicode_property', 'start' => 3, 'end' => 9, 'name' => 'L', 'negated' => false],
                ],
            ],
        ];
        yield 'escaped characters' => [
            'pattern' => '/\x{41}\101\cA/',
            'expected' => [
                'type' => 'sequence', 'start' => 0, 'end' => 13,
                'children' => [
                    ['type' => 'char_literal', 'start' => 0, 'end' => 6, 'code_point' => 65, 'kind' => 'unicode'],
                    ['type' => 'char_literal', 'start' => 6, 'end' => 10, 'code_point' => 65, 'kind' => 'octal_legacy'],
                    ['type' => 'control_char', 'start' => 10, 'end' => 13, 'char' => 'A', 'code_point' => 1],
                ],
            ],
        ];
        yield 'comment' => ['pattern' => '/(?#c)/', 'expected' => ['type' => 'comment', 'start' => 0, 'end' => 5, 'text' => 'c', 'extended' => false]];
        yield 'verbs and callouts' => [
            'pattern' => '/a(*ACCEPT)(*LIMIT_MATCH=10)(?C1)(?C"s")/',
            'expected' => [
                'type' => 'sequence', 'start' => 0, 'end' => 39,
                'children' => [
                    ['type' => 'literal', 'start' => 0, 'end' => 1, 'value' => 'a'],
                    ['type' => 'pcre_verb', 'start' => 1, 'end' => 10, 'verb' => 'ACCEPT'],
                    ['type' => 'limit_match', 'start' => 10, 'end' => 27, 'limit' => 10],
                    ['type' => 'callout', 'start' => 27, 'end' => 32, 'identifier' => 1],
                    ['type' => 'callout', 'start' => 32, 'end' => 39, 'identifier' => 's'],
                ],
            ],
        ];
        yield 'groups, numbered, references resolved' => [
            'pattern' => '/(?|(a)|(b))(?<n>c)\k<n>\1(?1)(?&n)/',
            'expected' => [
                'type' => 'sequence', 'start' => 0, 'end' => 34,
                'children' => [
                    [
                        'type' => 'group', 'start' => 0, 'end' => 11, 'kind' => 'branch_reset', 'name' => null, 'flags' => null, 'number' => null, 'scanned_groups' => [],
                        'child' => [
                            'type' => 'alternation', 'start' => 3, 'end' => 10,
                            'alternatives' => [
                                [
                                    'type' => 'group', 'start' => 3, 'end' => 6, 'kind' => 'capturing', 'name' => null, 'flags' => null, 'number' => 1, 'scanned_groups' => [],
                                    'child' => ['type' => 'literal', 'start' => 4, 'end' => 5, 'value' => 'a'],
                                ],
                                [
                                    'type' => 'group', 'start' => 7, 'end' => 10, 'kind' => 'capturing', 'name' => null, 'flags' => null, 'number' => 1, 'scanned_groups' => [],
                                    'child' => ['type' => 'literal', 'start' => 8, 'end' => 9, 'value' => 'b'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'group', 'start' => 11, 'end' => 18, 'kind' => 'named', 'name' => 'n', 'flags' => null, 'number' => 2, 'scanned_groups' => [],
                        'child' => ['type' => 'literal', 'start' => 16, 'end' => 17, 'value' => 'c'],
                    ],
                    ['type' => 'backref', 'start' => 18, 'end' => 23, 'reference' => '\k<n>', 'groups' => [2]],
                    ['type' => 'backref', 'start' => 23, 'end' => 25, 'reference' => '\1', 'groups' => [1]],
                    ['type' => 'subroutine', 'start' => 25, 'end' => 29, 'reference' => '1', 'groups' => [1], 'returned_groups' => []],
                    ['type' => 'subroutine', 'start' => 29, 'end' => 34, 'reference' => 'n', 'groups' => [2], 'returned_groups' => []],
                ],
            ],
        ];
        yield 'inline flags and a substring scan' => [
            'pattern' => '/(?i)(a)(*scs:(1)x)/',
            'expected' => [
                'type' => 'sequence', 'start' => 0, 'end' => 18,
                'children' => [
                    [
                        'type' => 'group', 'start' => 0, 'end' => 4, 'kind' => 'inline_flags', 'name' => null, 'flags' => 'i', 'number' => null, 'scanned_groups' => [],
                        'child' => ['type' => 'literal', 'start' => 3, 'end' => 3, 'value' => ''],
                    ],
                    [
                        'type' => 'group', 'start' => 4, 'end' => 7, 'kind' => 'capturing', 'name' => null, 'flags' => null, 'number' => 1, 'scanned_groups' => [],
                        'child' => ['type' => 'literal', 'start' => 5, 'end' => 6, 'value' => 'a'],
                    ],
                    [
                        'type' => 'group', 'start' => 7, 'end' => 18, 'kind' => 'scan_substring', 'name' => null, 'flags' => null, 'number' => null, 'scanned_groups' => ['1'],
                        'child' => ['type' => 'literal', 'start' => 16, 'end' => 17, 'value' => 'x'],
                    ],
                ],
            ],
        ];
        yield 'conditional on a group' => [
            'pattern' => '/(x)(?(1)a|b)/',
            'expected' => [
                'type' => 'sequence', 'start' => 0, 'end' => 12,
                'children' => [
                    [
                        'type' => 'group', 'start' => 0, 'end' => 3, 'kind' => 'capturing', 'name' => null, 'flags' => null, 'number' => 1, 'scanned_groups' => [],
                        'child' => ['type' => 'literal', 'start' => 1, 'end' => 2, 'value' => 'x'],
                    ],
                    [
                        'type' => 'conditional', 'start' => 3, 'end' => 12,
                        'condition' => ['type' => 'backref', 'start' => 6, 'end' => 7, 'reference' => '1', 'groups' => [1]],
                        'yes' => ['type' => 'literal', 'start' => 8, 'end' => 9, 'value' => 'a'],
                        'no' => ['type' => 'literal', 'start' => 10, 'end' => 11, 'value' => 'b'],
                    ],
                ],
            ],
        ];
        yield 'version condition' => [
            'pattern' => '/(?(VERSION>=10.4)x)/',
            'expected' => [
                'type' => 'conditional', 'start' => 0, 'end' => 19,
                'condition' => ['type' => 'version_condition', 'start' => 3, 'end' => 15, 'operator' => '>=', 'version' => '10.4'],
                'yes' => ['type' => 'literal', 'start' => 17, 'end' => 18, 'value' => 'x'],
                'no' => ['type' => 'literal', 'start' => 18, 'end' => 18, 'value' => ''],
            ],
        ];
        yield 'define' => [
            'pattern' => '/(?(DEFINE)(?<w>x))/',
            'expected' => [
                'type' => 'define', 'start' => 0, 'end' => 18,
                'content' => [
                    'type' => 'group', 'start' => 10, 'end' => 17, 'kind' => 'named', 'name' => 'w', 'flags' => null, 'number' => 1, 'scanned_groups' => [],
                    'child' => ['type' => 'literal', 'start' => 15, 'end' => 16, 'value' => 'x'],
                ],
            ],
        ];
        yield 'script run' => [
            'pattern' => '/(*sr:a)/',
            'expected' => [
                'type' => 'script_run', 'start' => 0, 'end' => 7, 'script' => 'a', 'atomic' => false,
                'content' => ['type' => 'literal', 'start' => 5, 'end' => 6, 'value' => 'a'],
            ],
        ];
        yield 'extended class' => [
            'pattern' => '/(?[ \p{L} - [aeiou] ])/',
            'expected' => [
                'type' => 'extended_char_class', 'start' => 0, 'end' => 22,
                'expression' => [
                    'type' => 'class_set_operation', 'start' => 4, 'end' => 19, 'operator' => 'difference',
                    'left' => ['type' => 'unicode_property', 'start' => 4, 'end' => 9, 'name' => 'L', 'negated' => false],
                    'right' => [
                        'type' => 'char_class', 'start' => 12, 'end' => 19, 'negated' => false,
                        'members' => [
                            ['type' => 'literal', 'start' => 13, 'end' => 14, 'value' => 'a'],
                            ['type' => 'literal', 'start' => 14, 'end' => 15, 'value' => 'e'],
                            ['type' => 'literal', 'start' => 15, 'end' => 16, 'value' => 'i'],
                            ['type' => 'literal', 'start' => 16, 'end' => 17, 'value' => 'o'],
                            ['type' => 'literal', 'start' => 17, 'end' => 18, 'value' => 'u'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Numbers and resolved references come from the whole pattern: a subtree
     * dumped alone has none to give.
     */
    #[Test]
    public function test_a_subtree_dumped_alone_carries_no_numbers(): void
    {
        $tree = RegexParser::create()->parse('/(a)\1/');

        $this->assertSame([
            'type' => 'sequence', 'start' => 0, 'end' => 5,
            'children' => [
                [
                    'type' => 'group', 'start' => 0, 'end' => 3, 'kind' => 'capturing', 'name' => null, 'flags' => null, 'number' => null, 'scanned_groups' => [],
                    'child' => ['type' => 'literal', 'start' => 1, 'end' => 2, 'value' => 'a'],
                ],
                ['type' => 'backref', 'start' => 3, 'end' => 5, 'reference' => '\1', 'groups' => []],
            ],
        ], $tree->pattern->accept(new JsonDumper()));
    }

    /**
     * A condition names its group without a backslash: relative, by number
     * or by a name several groups bear.
     */
    #[Test]
    public function test_a_condition_resolves_the_groups_it_tests(): void
    {
        $groups = [];
        foreach (self::nodesOfType(self::export('/(a)(?(+1)x)(b)(?(-1)y)(?J)(?<n>c)(?<n>d)(?(n)z)(?(9)w)/'), 'conditional') as $conditional) {
            $condition = $conditional['condition'];
            $this->assertIsArray($condition);
            $groups[] = $condition['groups'];
        }

        $this->assertSame([[2], [2], [3, 4], []], $groups);
    }

    /**
     * A node kind added to the visitor contract gets its own export: none
     * falls back to a base class default.
     */
    #[Test]
    public function test_every_kind_of_node_has_its_own_export(): void
    {
        foreach ((new \ReflectionClass(NodeVisitorInterface::class))->getMethods() as $method) {
            $this->assertSame(
                JsonDumper::class,
                (new \ReflectionMethod(JsonDumper::class, $method->getName()))->getDeclaringClass()->getName(),
                $method->getName().' has no export of its own.',
            );
        }
    }

    /**
     * The schema names one definition per node kind, and the examples above
     * meet every kind the parser builds.
     */
    #[Test]
    public function test_the_schema_defines_every_kind_the_examples_meet(): void
    {
        $types = ['regex' => true];
        foreach (self::provideNodes() as $row) {
            self::collectTypes($row['expected'], $types);
        }
        ksort($types);

        $definitions = self::schema()['$defs'];
        $this->assertIsArray($definitions);
        $defined = [];
        foreach ($definitions as $definition) {
            $this->assertIsArray($definition);
            $properties = $definition['properties'] ?? null;
            $type = \is_array($properties) && \is_array($properties['type'] ?? null) ? ($properties['type']['const'] ?? null) : null;
            if (\is_string($type)) {
                $defined[$type] = true;
            }
        }
        ksort($defined);

        $nodeClasses = glob(__DIR__.'/../../../../src/Parser/Node/*Node.php') ?: [];
        $nodeClasses = array_filter($nodeClasses, static fn (string $file): bool => 'AbstractNode.php' !== basename($file));

        $this->assertSame(array_keys($types), array_keys($defined));
        $this->assertCount(\count($nodeClasses), $defined, 'One kind per node class.');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCorpus(): iterable
    {
        foreach (self::provideNodes() as $name => $row) {
            yield $name => ['pattern' => $row['pattern']];
        }

        yield 'unicode literal' => ['pattern' => '/é+./u'];
        yield 'named in Python syntax' => ['pattern' => '/(?P<p>c)(?P=p)(?P>p)/'];
        yield 'relative references' => ['pattern' => '/(a)(b)\g{-1}\g-2(?-1)(?+1)(c)/'];
        yield 'lookarounds and atomic' => ['pattern' => '/(?=a)(?!b)(?<=c)(?<!d)(?>e)/'];
        yield 'returned groups' => ['pattern' => '/(a)(b)(?1(2))/'];
        yield 'extended comment' => ['pattern' => "/a # note\n/x"];
        yield 'atomic script run' => ['pattern' => '/(*asr:\p{Greek}+)/u'];
        yield 'class set operators' => ['pattern' => '/(?[ [a-z] & [aeiou] + [0-9] ^ [5] ])/'];
        yield 'bounds' => ['pattern' => '/a{3}b{2,}c?d{0,1}+/'];
        yield 'mark verb' => ['pattern' => '/(*MARK:x)(*LIMIT_DEPTH=5)a(*SKIP)(*F)|b/'];
        yield 'bytes in a comment' => ['pattern' => "/a(?#\xFF)/"];
    }

    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_the_export_conforms_to_the_shipped_schema(string $pattern): void
    {
        $json = json_encode(self::export($pattern), \JSON_THROW_ON_ERROR);

        $this->assertSame([], (new JsonSchemaSubsetValidator())->validate(self::schema(), json_decode($json, false, 512, \JSON_THROW_ON_ERROR)));
    }

    /**
     * PCRE2 compiles only "=" and ">=" after VERSION; the parser reads the
     * other comparisons too, for the validator to refuse, and the export
     * writes the one it read.
     */
    #[Test]
    #[DataProvider('provideVersionOperators')]
    public function test_every_version_operator_the_parser_reads_conforms_to_the_schema(string $operator): void
    {
        $export = self::export('/(?(VERSION'.$operator.'10.0)a|b)/');
        $json = json_encode($export, \JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"operator":'.json_encode($operator, \JSON_THROW_ON_ERROR), $json);
        $this->assertSame([], (new JsonSchemaSubsetValidator())->validate(self::schema(), json_decode($json, false, 512, \JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function test_the_schema_lists_the_version_operators_the_parser_reads(): void
    {
        $definitions = self::schema()['$defs'];
        $this->assertIsArray($definitions);
        $condition = $definitions['version_condition'];
        $this->assertIsArray($condition);
        $properties = $condition['properties'];
        $this->assertIsArray($properties);
        $operator = $properties['operator'];
        $this->assertIsArray($operator);

        $read = (new \ReflectionClassConstant(VersionCondition::class, 'OPERATORS'))->getValue();
        $this->assertIsArray($read);
        $enum = $operator['enum'];
        $this->assertIsArray($enum);
        sort($read);
        sort($enum);

        $this->assertSame($read, $enum);
        $covered = self::VERSION_OPERATORS;
        sort($covered);
        $this->assertSame($enum, $covered);
    }

    /**
     * @return iterable<string, array{operator: string}>
     */
    public static function provideVersionOperators(): iterable
    {
        foreach (self::VERSION_OPERATORS as $operator) {
            yield $operator => ['operator' => $operator];
        }
    }

    /**
     * The corpus holds the newest syntax, as (?[...]) and (*scs:...): the
     * tree is read for the newest PCRE2 the library knows, whatever the host
     * runs, as the export does not depend on the engine.
     *
     * @return array<string, mixed>
     */
    private static function export(string $pattern): array
    {
        return RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse($pattern)->accept(new JsonDumper());
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);

        $keyed = [];
        foreach ($schema as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return list<array<array-key, mixed>>
     */
    private static function nodesOfType(array $node, string $type): array
    {
        $found = ($node['type'] ?? null) === $type ? [$node] : [];
        foreach ($node as $value) {
            if (\is_array($value)) {
                array_push($found, ...self::nodesOfType($value, $type));
            }
        }

        return $found;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, true>     $types
     */
    private static function collectTypes(array $node, array &$types): void
    {
        if (isset($node['type']) && \is_string($node['type'])) {
            $types[$node['type']] = true;
        }

        foreach ($node as $value) {
            if (\is_array($value)) {
                self::collectTypes($value, $types);
            }
        }
    }
}
