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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\RedosArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A subject, a prefix, a suffix or a pattern is free text: one that starts
 * with "-" is read after the space as after "=", "-" being a delimiter PHP
 * takes ("-a-" compiles).
 */
final class RedosArgumentsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFreeTextOptions')]
    public function test_a_free_text_value_may_start_with_a_dash(string $option, string $property): void
    {
        $spaced = RedosArguments::parse(['/a/', $option, '-x-']);
        $attached = RedosArguments::parse(['/a/', $option.'=-x-']);

        $this->assertInstanceOf(RedosArguments::class, $spaced);
        $this->assertInstanceOf(RedosArguments::class, $attached);
        $this->assertSame('-x-', $spaced->{$property});
        $this->assertSame($attached->{$property}, $spaced->{$property});
    }

    /**
     * @return iterable<string, array{option: string, property: string}>
     */
    public static function provideFreeTextOptions(): iterable
    {
        yield 'the input' => ['option' => '--input', 'property' => 'input'];
        yield 'the prefix' => ['option' => '--prefix', 'property' => 'prefix'];
        yield 'the suffix' => ['option' => '--suffix', 'property' => 'suffix'];
        yield 'the safe pattern' => ['option' => '--safe', 'property' => 'safePattern'];
    }
}
