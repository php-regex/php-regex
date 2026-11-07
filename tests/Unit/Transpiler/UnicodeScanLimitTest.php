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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeScanLimitTest extends TestCase
{
    #[DataProvider('targets')]
    public function test_distinct_unicode_literals_are_bounded(string $target): void
    {
        StaticCaches::clear();
        $body = '';
        for ($point = 0x400; $point < 0x428; ++$point) {
            $body .= \sprintf('\\x{%X}', $point);
        }

        try {
            (new Transpiler())->transpile('/'.$body.'/iu', $target);
            $this->fail('An excessive number of Unicode scans must be refused.');
        } catch (TranspileException $exception) {
            $this->assertStringContainsString('PCRE cannot tell', $exception->getMessage());
        }

        // An aborted translation must release its budget for the next call.
        $this->assertNotEmpty((new Transpiler())->transpile('/\\x{410}/iu', $target)->pattern);
    }

    #[DataProvider('targets')]
    public function test_cached_literals_do_not_consume_the_scan_budget(string $target): void
    {
        StaticCaches::clear();
        $pattern = '/'.str_repeat('\\x{400}', 100).'/iu';

        $this->assertNotEmpty((new Transpiler())->transpile($pattern, $target)->pattern);
    }

    public static function targets(): iterable
    {
        yield ['python'];
        yield ['html'];
    }
}
