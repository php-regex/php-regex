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

namespace PhpRegex\Tests\Unit\Runtime;

use PhpRegex\Cli\PcreRuntimeInfo;
use PHPUnit\Framework\TestCase;

final class PcreRuntimeInfoTest extends TestCase
{
    public function test_from_ini_reflects_current_runtime(): void
    {
        $info = PcreRuntimeInfo::fromIni();

        $this->assertSame(\PCRE_VERSION, $info->version);
        $this->assertSame((int) \ini_get('pcre.backtrack_limit'), $info->backtrackLimit);
        $this->assertSame((int) \ini_get('pcre.recursion_limit'), $info->recursionLimit);
    }

    public function test_json_serialization_shape(): void
    {
        $info = new PcreRuntimeInfo('10.42', '1', 1000000, 100000);

        $this->assertSame([
            'version' => '10.42',
            'jit' => '1',
            'backtrack_limit' => 1000000,
            'recursion_limit' => 100000,
        ], $info->jsonSerialize());
    }

    public function test_nullable_ini_values_are_preserved(): void
    {
        $info = new PcreRuntimeInfo('unknown', null, null, null);

        $this->assertNull($info->jitSetting);
        $this->assertNull($info->backtrackLimit);
        $this->assertNull($info->recursionLimit);
        $this->assertSame('unknown', $info->version);
    }
}
