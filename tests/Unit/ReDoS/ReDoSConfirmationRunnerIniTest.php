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

namespace RegexParser\Tests\Unit\ReDoS;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\ReDoS\ReDoSConfirmationRunner;

/**
 * The runner restores the PCRE limits it read from the ini settings: a value
 * that is no plain number is not one it can restore.
 */
final class ReDoSConfirmationRunnerIniTest extends TestCase
{
    #[Test]
    #[DataProvider('provideIniValues')]
    public function test_ini_value_is_read_as_a_plain_number(mixed $value, ?int $expected): void
    {
        $method = (new \ReflectionClass(ReDoSConfirmationRunner::class))->getMethod('parseIniInt');

        $this->assertSame($expected, $method->invoke(new ReDoSConfirmationRunner(), $value));
    }

    /**
     * @return iterable<string, array{value: mixed, expected: int|null}>
     */
    public static function provideIniValues(): iterable
    {
        yield 'digits' => ['value' => '1000000', 'expected' => 1000000];
        yield 'digits around spaces' => ['value' => ' 42 ', 'expected' => 42];
        yield 'integer' => ['value' => 7, 'expected' => 7];
        yield 'empty' => ['value' => '', 'expected' => null];
        yield 'negative' => ['value' => '-1', 'expected' => null];
        yield 'high byte' => ['value' => "1\xB2", 'expected' => null];
        yield 'false from ini_get' => ['value' => false, 'expected' => null];
    }
}
