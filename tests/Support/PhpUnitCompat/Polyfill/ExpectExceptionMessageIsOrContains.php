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

namespace PHPRegex\Tests\Support\PhpUnitCompat\Polyfill;

/**
 * PHPUnit 10.5 to 13.1 lack expectExceptionMessageIsOrContains(); their
 * expectExceptionMessage() checks the same thing, that the message is the
 * given one or contains it, and an empty string expects an empty message.
 *
 * Not final, unlike PHPUnit's own: a test case may use the trait again
 * below a base class that already does.
 */
trait ExpectExceptionMessageIsOrContains
{
    protected function expectExceptionMessageIsOrContains(string $message): void
    {
        $this->expectExceptionMessage($message);
    }
}
