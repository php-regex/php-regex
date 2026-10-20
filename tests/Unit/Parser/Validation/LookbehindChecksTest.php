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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Validation\LookbehindChecks;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LookbehindChecksTest extends TestCase
{
    /**
     * The hint names the first item of no bound in the order PCRE reads
     * them: the condition of a conditional, then its first branch, then the
     * second; the start of a range before its end.
     */
    #[Test]
    public function test_the_item_named_in_the_hint_is_the_first_of_no_bound_in_reading_order(): void
    {
        $lookbehinds = new LookbehindChecks(GroupIndex::empty(), PcreTarget::runtime(), static function (): void {}, null, 255, false);
        $method = (new \ReflectionClass($lookbehinds))->getMethod('findUnboundedNode');

        $condition = new BackrefNode('1', 0, 0);
        $yes = new QuantifierNode(new LiteralNode('a', 0, 0), '*', QuantifierType::Greedy, 0, 0);
        $no = new QuantifierNode(new LiteralNode('b', 0, 0), '+', QuantifierType::Greedy, 0, 0);
        $this->assertSame($condition, $method->invoke($lookbehinds, new ConditionalNode($condition, $yes, $no, 0, 0)));
        $this->assertSame($yes, $method->invoke($lookbehinds, new ConditionalNode(new LiteralNode('c', 0, 0), $yes, $no, 0, 0)));

        $start = new BackrefNode('1', 0, 0);
        $end = new BackrefNode('2', 0, 0);
        $this->assertSame($start, $method->invoke($lookbehinds, new RangeNode($start, $end, 0, 0)));
        $this->assertSame($end, $method->invoke($lookbehinds, new CharClassNode(new RangeNode(new LiteralNode('a', 0, 0), $end, 0, 0), false, 0, 0)));
    }

    /**
     * Off a walk from the pattern root, a lookbehind visited on its own is
     * measured afresh: one measured earlier may be gone, and a new node may
     * take its identity.
     */
    #[Test]
    public function test_a_lookbehind_visited_off_a_walk_is_measured_even_where_an_earlier_one_stood(): void
    {
        $validator = new Validator(255, null, new PcreTarget(80400, '10.49'));
        $fixed = new GroupNode(new LiteralNode('a', 0, 0), GroupType::LookbehindPositive);
        $fixed->accept($validator);
        $id = spl_object_id($fixed);

        $repeat = new QuantifierNode(new LiteralNode('a', 0, 0), '+', QuantifierType::Greedy, 0, 0);
        unset($fixed);
        $unbounded = new GroupNode($repeat, GroupType::LookbehindPositive);
        $this->assertSame($id, spl_object_id($unbounded));

        try {
            $unbounded->accept($validator);
            $this->fail('An unbounded lookbehind is refused.');
        } catch (SemanticErrorException $error) {
            $this->assertSame(ErrorCode::LookbehindUnbounded, $error->errorCode);
        }
    }
}
