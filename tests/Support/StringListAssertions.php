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

namespace PHPRegex\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * assertContainsOnlyString() arrived in PHPUnit 11.5; the suite also runs
 * on PHPUnit 10.5, under PHP 8.1.
 */
trait StringListAssertions
{
    /**
     * @phpstan-assert iterable<string> $values
     */
    private static function assertOnlyStrings(mixed $values, string $message = ''): void
    {
        Assert::assertIsIterable($values, $message);

        $others = [];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                $others[] = get_debug_type($value);
            }
        }

        Assert::assertSame([], $others, '' === $message ? 'Every value is a string.' : $message);
    }
}
