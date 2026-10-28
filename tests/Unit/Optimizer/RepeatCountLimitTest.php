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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\RepeatRewrites;
use PHPRegex\Parser\Syntax\PcreNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The optimizer keeps its own copy of the largest count PCRE2 takes, the
 * parser's being internal to its package: both say 65535, PCRE2's
 * MAX_REPEAT_COUNT, past which a count does not compile (PHP 8.4.27).
 */
final class RepeatCountLimitTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCountsAroundTheLimit')]
    public function test_the_optimizer_counts_to_the_limit_the_parser_reads(string $atTheLimit, string $pastTheLimit): void
    {
        $this->assertSame(1, preg_match($atTheLimit, str_repeat('a', PcreNumber::MAX)));
        $this->assertFalse(@preg_match($pastTheLimit, ''));

        $this->assertSame(PcreNumber::MAX, (new \ReflectionClassConstant(RepeatRewrites::class, 'MAX_COUNT'))->getValue());
    }

    /**
     * @return iterable<string, array{atTheLimit: string, pastTheLimit: string}>
     */
    public static function provideCountsAroundTheLimit(): iterable
    {
        yield 'a letter' => ['atTheLimit' => '/^a{65535}$/', 'pastTheLimit' => '/a{65536}/'];
    }
}
