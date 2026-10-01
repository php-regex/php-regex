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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One way to do each thing on the facade: create() builds it, parse() parses,
 * parseTolerant() parses tolerantly, and the cache seed stays the parser's.
 */
final class FacadeSurfaceTest extends TestCase
{
    #[Test]
    public function test_create_is_the_only_constructor(): void
    {
        $this->assertFalse((new \ReflectionClass(Regex::class))->hasMethod('new'));
    }

    #[Test]
    public function test_parse_takes_only_the_pattern(): void
    {
        $parse = new \ReflectionMethod(Regex::class, 'parse');

        $this->assertSame(1, $parse->getNumberOfParameters());
        $this->assertSame(RegexNode::class, (string) $parse->getReturnType());
    }

    #[Test]
    public function test_the_cache_seed_is_internal_to_the_parser(): void
    {
        $this->assertFalse((new \ReflectionClass(Regex::class))->hasMethod('cacheSeed'));
        $this->assertStringContainsString('@internal', (string) (new \ReflectionMethod(RegexParser::class, 'cacheSeed'))->getDocComment());
    }
}
