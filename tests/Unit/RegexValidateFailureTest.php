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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Cache\CacheInterface;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\RegexNode;
use RegexParser\NodeVisitor\NodeVisitorInterface;
use RegexParser\Regex;
use RegexParser\ValidationErrorCategory;

/**
 * validate() judges the pattern. When the library itself fails, the
 * failure surfaces: reported as an invalid pattern, it would be a verdict
 * no one could tell from a real one.
 */
final class RegexValidateFailureTest extends TestCase
{
    #[Test]
    public function test_a_failure_of_the_library_is_not_reported_as_an_invalid_pattern(): void
    {
        // A cache handing back a tree a visitor fails on stands for any
        // failure of the library while it judges a valid pattern.
        $failing = new class implements NodeInterface {
            public function accept(NodeVisitorInterface $visitor): never
            {
                throw new \LogicException('A visitor failed.');
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 1;
            }
        };
        $cache = new class(new RegexNode($failing, '', '/', 0, 1)) implements CacheInterface {
            public function __construct(private readonly RegexNode $tree) {}

            public function generateKey(string $regex): string
            {
                return $regex;
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): RegexNode
            {
                return $this->tree;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A visitor failed.');

        Regex::create(['cache' => $cache])->validate('/a/');
    }

    #[Test]
    public function test_an_invalid_pattern_is_still_reported(): void
    {
        $result = Regex::create(['cache' => null])->validate('/a(/');

        $this->assertFalse($result->isValid);
        $this->assertSame(ValidationErrorCategory::SYNTAX, $result->category);
    }
}
