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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures\NoNewMixed;

/**
 * Prose may say mixed: only types count.
 *
 * @phpstan-type Bag array<string, mixed>
 */
final class MixedDeclarations
{
    use SharedDeclarations;

    /**
     * @var array<string, mixed>
     */
    public array $bag = [];

    /**
     * @param list<mixed> $values a mixed bag of values
     */
    public function __construct(public readonly mixed $value, public readonly array $values = []) {}

    public function read(mixed $mixed): mixed
    {
        /** @var mixed $copy */
        $copy = $mixed;
        $map = static fn (mixed $item): string => \is_string($item) ? $item : '';

        return [$copy, $map];
    }

    public function typed(string $text): string
    {
        return $text;
    }
}

final class OtherDeclarations
{
    use SharedDeclarations;
}

trait SharedDeclarations
{
    public function shared(mixed $value): void
    {
    }
}

function helper(mixed $value): mixed
{
    return $value;
}
