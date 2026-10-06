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

namespace PHPRegex\Tests\Unit\Runtime;

use PHPRegex\Cli\PcreRuntimeInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PcreRuntimeInfoJitTest extends TestCase
{
    /**
     * The pcre.jit string as ini_get() gives it, read the way PHP reads a
     * boolean setting: the full table of values is IniFlagTest's; these
     * rows show the setting goes through it.
     */
    #[Test]
    #[DataProvider('provideJitSettings')]
    public function test_jit_is_read_as_php_reads_the_ini_bool(?string $setting, ?bool $expected): void
    {
        $info = new PcreRuntimeInfo('10.42', $setting, null, null);

        $this->assertSame($expected, $info->jsonSerialize()['jit']);
    }

    /**
     * @return iterable<string, array{setting: string|null, expected: bool|null}>
     */
    public static function provideJitSettings(): iterable
    {
        yield 'unknown' => ['setting' => null, 'expected' => null];
        yield 'zero' => ['setting' => '0', 'expected' => false];
        yield 'one' => ['setting' => '1', 'expected' => true];
        yield '2^32, which a C int truncates to zero' => ['setting' => '4294967296', 'expected' => false];
    }
}
