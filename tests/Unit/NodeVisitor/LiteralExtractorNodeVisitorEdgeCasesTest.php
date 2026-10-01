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

use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Toolkit\Regex;
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
        $literal = new CharLiteralNode('\\x41', 0x41, CharLiteralType::Unicode, 0, 0);

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

        $quantifier = new QuantifierNode($node, '{2}', QuantifierType::Greedy, 0, 0);
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
