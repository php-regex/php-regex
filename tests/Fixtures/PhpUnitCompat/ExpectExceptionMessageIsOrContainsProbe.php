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

namespace PHPRegex\Tests\Fixtures\PhpUnitCompat;

use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\TestCase;

/**
 * Run by PolyfilledMethodsTest in a PHPUnit of its own: every test_passes_*
 * must pass and every test_fails_* must fail, on the PHPUnit running the
 * suite.
 */
final class ExpectExceptionMessageIsOrContainsProbe extends TestCase
{
    use PhpUnitForwardCompat;

    public function test_passes_when_the_message_is_the_expected_one(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('the whole message');

        throw new \RuntimeException('the whole message');
    }

    public function test_passes_when_the_message_contains_the_expected_one(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('needle');

        throw new \RuntimeException('a needle in the message');
    }

    public function test_passes_on_an_empty_message_when_an_empty_one_is_expected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('');

        throw new \RuntimeException('');
    }

    public function test_fails_when_the_message_does_not_contain_the_expected_one(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('absent');

        throw new \RuntimeException('the message');
    }

    public function test_fails_when_the_expected_one_is_longer_than_the_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('the message and more');

        throw new \RuntimeException('the message');
    }

    public function test_fails_when_the_case_differs(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Needle');

        throw new \RuntimeException('a needle');
    }

    public function test_fails_on_a_message_when_an_empty_one_is_expected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('');

        throw new \RuntimeException('not empty');
    }
}
