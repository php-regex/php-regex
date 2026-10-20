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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidatorNestingTest extends TestCase
{
    /**
     * PCRE refuses the level past 250 once it has read the opener, before
     * the spaces "x" skips: preg_match() on PCRE2 10.49 reports "parentheses
     * are too deeply nested at offset 255", right after the 251st "(".
     */
    #[Test]
    public function test_the_level_past_the_limit_is_refused_before_the_spaces_x_skips(): void
    {
        $pattern = '/(?x)'.str_repeat('(', 250).'(   a'.str_repeat(')', 251).'/';
        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([ErrorCode::GroupNestedTooDeep, 255], [$result->errorCode, $result->offset]);
    }

    /**
     * Off a walk from the pattern root, the level refused is not counted:
     * a tree as deep as the limit visited next is taken.
     */
    #[Test]
    public function test_a_level_refused_off_a_walk_is_not_counted(): void
    {
        $validator = new Validator();

        try {
            self::nested(251)->accept($validator);
            $this->fail('A group past the nesting limit is refused.');
        } catch (SemanticErrorException $error) {
            $this->assertSame(ErrorCode::GroupNestedTooDeep, $error->errorCode);
        }

        $refused = null;

        try {
            self::nested(250)->accept($validator);
        } catch (SemanticErrorException $error) {
            $refused = $error->errorCode;
        }

        $this->assertNull($refused);
    }

    private static function nested(int $depth): GroupNode
    {
        $node = new GroupNode(new LiteralNode('a', 0, 0), GroupType::NonCapturing);
        for ($level = 1; $level < $depth; $level++) {
            $node = new GroupNode($node, GroupType::NonCapturing);
        }

        return $node;
    }
}
