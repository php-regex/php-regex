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

namespace PHPRegex\PHPUnit\Internal;

/**
 * What one guarded match attempt answered, already judged against the
 * expectation vocabulary.
 *
 * @internal read through the assertions and their constraints
 */
final class MatchAttempt
{
    /**
     * @param bool|null                $matched  whether the pattern matched, null when the attempt could not run
     * @param array<int|string, mixed> $expected the expectation as the caller wrote it, empty when the attempt could not run
     * @param array<int|string, mixed> $actual   the engine's answer in the expectation's own spelling, filled on a match
     * @param string|null              $failure  why the attempt answered nothing: the library's parse or limit message, a
     *                                           vocabulary refusal, or the engine's own words for a refused match
     */
    public function __construct(
        public readonly ?bool $matched,
        public readonly array $expected,
        public readonly array $actual,
        public readonly ?string $failure,
    ) {}
}
