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

use PhpRegex\Parser\Analysis\LiteralExtractor;
use PhpRegex\Parser\Analysis\LiteralSet;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class LiteralExtractorNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_case_insensitive_char_class_expands_literals(): void
    {
        $regex = Regex::create();
        $visitor = new LiteralExtractor();

        $ast = $regex->parse('/[ab]/i');
        $result = $ast->accept($visitor);

        $this->assertContains('a', $result->prefixes);
        $this->assertContains('A', $result->prefixes);
    }

    public function test_visit_assertion_returns_empty_literal_set(): void
    {
        $visitor = new LiteralExtractor();
        $result = $visitor->visitAssertion(new AssertionNode('A', 0, 0));

        $this->assertSame([''], $result->prefixes);
    }

    public function test_visit_range_returns_empty_literal_set(): void
    {
        $visitor = new LiteralExtractor();
        $range = new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('z', 0, 0), 0, 0);

        $this->assertTrue($visitor->visitRange($range)->isVoid());
    }

    public function test_visit_char_literal_returns_empty_literal_set(): void
    {
        $visitor = new LiteralExtractor();
        $literal = new CharLiteralNode('\\x41', 0x41, CharLiteralType::UNICODE, 0, 0);

        $this->assertTrue($visitor->visitCharLiteral($literal)->isVoid());
    }

    public function test_visit_posix_class_returns_empty_literal_set(): void
    {
        $visitor = new LiteralExtractor();
        $posix = new PosixClassNode('alpha', 0, 0);

        $this->assertTrue($visitor->visitPosixClass($posix)->isVoid());
    }

    public function test_visit_sequence_caps_large_literal_sets(): void
    {
        $visitor = new LiteralExtractor();
        $largeSet = $this->makeLiteralSetWithPrefixes(200);
        $node = new class($largeSet) implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function __construct(private readonly LiteralSet $set) {}

            public function accept(NodeVisitorInterface $visitor): LiteralSet
            {
                return $this->set;
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 0;
            }
        };

        $sequence = new SequenceNode([$node], 0, 0);
        $result = $visitor->visitSequence($sequence);

        // 200 prefixes do not fit: only the empty start of the sequence holds.
        $this->assertFalse($result->isVoid());
        $this->assertSame([''], $result->prefixes);
    }

    public function test_visit_quantifier_caps_large_literal_sets(): void
    {
        $visitor = new LiteralExtractor();
        $largeSet = $this->makeLiteralSetWithPrefixes(200);
        $node = new class($largeSet) implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function __construct(private readonly LiteralSet $set) {}

            public function accept(NodeVisitorInterface $visitor): LiteralSet
            {
                return $this->set;
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 0;
            }
        };

        $quantifier = new QuantifierNode($node, '{2}', QuantifierType::T_GREEDY, 0, 0);
        $result = $visitor->visitQuantifier($quantifier);

        // 200 prefixes, repeated, fit nowhere: nothing is claimed.
        $this->assertTrue($result->isVoid());
        $this->assertSame([], $result->prefixes);
    }

    public function test_visit_alternation_exits_on_large_literal_sets(): void
    {
        $visitor = new LiteralExtractor();
        $largeSet = $this->makeLiteralSetWithPrefixes(200);
        $node = new class($largeSet) implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function __construct(private readonly LiteralSet $set) {}

            public function accept(NodeVisitorInterface $visitor): LiteralSet
            {
                return $this->set;
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 0;
            }
        };

        $alternation = new AlternationNode([$node], 0, 0);
        $result = $visitor->visitAlternation($alternation);

        $this->assertTrue($result->isVoid());
    }

    private function makeLiteralSetWithPrefixes(int $count): LiteralSet
    {
        $ref = new \ReflectionClass(LiteralSet::class);
        $set = $ref->newInstanceWithoutConstructor();

        $prefixes = [];
        for ($i = 0; $i < $count; $i++) {
            $prefixes[] = 'p'.$i;
        }

        $ref->getProperty('prefixes')->setValue($set, $prefixes);
        $ref->getProperty('suffixes')->setValue($set, $prefixes);
        $ref->getProperty('complete')->setValue($set, true);

        return $set;
    }
}
