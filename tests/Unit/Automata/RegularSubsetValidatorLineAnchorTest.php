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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Transform\RegularSubsetValidator;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * lineAnchor() walks the tree for the line anchors a newline convention
 * moves ("$" and "\Z"), and every path that returns a node returns one it
 * instanceof-narrowed first: an AnchorNode for "$", an AssertionNode for
 * "\Z", or the same union back from the recursion. The signature still says
 * ?NodeInterface, so a future caller reading ->value or ->kind off the
 * anchor narrows by hand. The native union (the audit's verified proposal —
 * the method is private, no public surface moves, and PHP itself then
 * enforces what the body proves) hands the narrow type over for free.
 */
final class RegularSubsetValidatorLineAnchorTest extends TestCase
{
    #[Test]
    public function test_line_anchor_declares_the_anchor_union_it_returns(): void
    {
        $returnType = (new \ReflectionMethod(RegularSubsetValidator::class, 'lineAnchor'))->getReturnType();

        $this->assertInstanceOf(\ReflectionType::class, $returnType, 'lineAnchor() must declare what it returns.');

        $members = [];
        if ($returnType instanceof \ReflectionUnionType) {
            foreach ($returnType->getTypes() as $type) {
                $members[] = (string) $type;
            }
        } else {
            $members[] = (string) $returnType;
        }
        if ($returnType->allowsNull()) {
            $members[] = 'null';
        }
        $members = array_values(array_unique($members));
        sort($members);

        $this->assertSame(
            [
                AnchorNode::class,
                AssertionNode::class,
                'null',
            ],
            $members,
            'lineAnchor() returns only what its body proved: an instanceof-narrowed AnchorNode ("$"), an'
            .' instanceof-narrowed AssertionNode ("\\Z"), the same union back from the recursion, or null. The'
            .' native union makes the narrow type the one callers read — the sole caller today only null-checks,'
            .' so declaring it moves nothing at runtime.',
        );
    }
}
