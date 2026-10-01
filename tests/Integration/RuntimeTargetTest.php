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

namespace PHPRegex\Tests\Integration;

use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPRegex\Tests\Unit\NodeVisitor\ErrorOffsetReleaseTest;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * With no target, the running PHP and the PCRE2 it links judge: the one test
 * that asks the engine itself, on whatever PHP runs it. Every other test
 * names its target.
 */
final class RuntimeTargetTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_default_target_agrees_with_the_running_engine(string $pattern): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame(false !== @preg_match($pattern, ''), $result->isValid, $pattern);
        $this->assertSame(PhpErrorOffset::of($pattern), $result->offset, $pattern);
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_default_target_is_the_running_php_and_its_pcre2_named(string $pattern): void
    {
        $named = Regex::create(['cache' => null, 'php_version' => \PHP_VERSION_ID, 'pcre_version' => \PCRE_VERSION]);

        $this->assertEquals($named->validate($pattern), Regex::create(['cache' => null])->validate($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        foreach (ErrorOffsetReleaseTest::provideErrors() as $name => $row) {
            yield $name => ['pattern' => $row['pattern']];
        }

        yield 'ASCII option' => ['pattern' => '/(?aD)x/'];
        yield 'count with no minimum' => ['pattern' => '/a{,2}/'];
        yield 'x alone' => ['pattern' => '/a\\xg/'];
        yield 'recent script' => ['pattern' => '/\\p{Sidetic}/'];
        yield 'plain' => ['pattern' => '/^[a-z]+\\d{2,}$/i'];
    }
}
