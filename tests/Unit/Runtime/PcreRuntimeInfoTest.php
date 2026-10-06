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
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
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

    /**
     * The report names the caller's limits, never the floor the library
     * raises around its own regexes: it reads them outside any call.
     */
    #[Test]
    public function test_from_ini_reports_the_callers_limits_after_a_library_call(): void
    {
        $backtrack = \ini_get('pcre.backtrack_limit');
        $recursion = \ini_get('pcre.recursion_limit');
        \ini_set('pcre.backtrack_limit', '19');
        \ini_set('pcre.recursion_limit', '50');

        try {
            RegexParser::create()->validate('/(?:.*\n)+x/');
            $info = PcreRuntimeInfo::fromIni();
        } finally {
            \ini_set('pcre.backtrack_limit', false === $backtrack ? '1000000' : $backtrack);
            \ini_set('pcre.recursion_limit', false === $recursion ? '100000' : $recursion);
        }

        $this->assertSame(19, $info->backtrackLimit);
        $this->assertSame(50, $info->recursionLimit);
    }

    public function test_json_serialization_shape(): void
    {
        $info = new PcreRuntimeInfo('10.42', '1', 1000000, 100000);

        $this->assertSame([
            'version' => '10.42',
            'jit' => true,
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
