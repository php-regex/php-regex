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

namespace PHPRegex\Tests\Unit\ReDoS;

use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfirmationJsonTest extends TestCase
{
    #[Test]
    public function test_json_serialize_writes_every_key_with_the_jit_setting_as_a_bool(): void
    {
        $sample = new ConfirmationSample(32, 1.5, 'aaaa', 2, 'Backtrack limit exhausted');
        $confirmation = new Confirmation(true, [$sample], '0', 1000, 100, 3, 50.0, false, 'backtrack_limit', 'a note', null);

        $this->assertSame([
            'confirmed' => true,
            'samples' => [$sample],
            'jit_setting' => false,
            'backtrack_limit' => 1000,
            'recursion_limit' => 100,
            'iterations' => 3,
            'timeout_ms' => 50.0,
            'timed_out' => false,
            'evidence' => 'backtrack_limit',
            'note' => 'a note',
            'error' => null,
        ], $confirmation->jsonSerialize());
    }

    /**
     * The pcre.jit string as ini_get() gives it, read the way PHP reads a
     * boolean setting: the full table of values is IniFlagTest's; these
     * rows show the setting goes through it.
     */
    #[Test]
    #[DataProvider('provideJitSettings')]
    public function test_jit_setting_is_read_as_php_reads_the_ini_bool(?string $setting, ?bool $expected): void
    {
        $confirmation = new Confirmation(false, [], $setting, null, null, 1, 1.0);

        $this->assertSame($expected, $confirmation->jsonSerialize()['jit_setting']);
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
