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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Parser\Validation\ValidationResult;

final class StubRegex
{
    /**
     * @param array<string, array{0: bool, 1: ?string, 2: int}> $results
     * @param array<string>                                     $ignored
     */
    public function __construct(private array $results, private readonly array $ignored = []) {}

    public function validate(string $pattern): ValidationResult
    {
        $result = $this->results[$pattern] ?? [true, null, 0];

        return new ValidationResult($result[0], $result[1], $result[2]);
    }

    /**
     * @return array<string>
     */
    public function getRedosIgnoredPatterns(): array
    {
        return $this->ignored;
    }
}
