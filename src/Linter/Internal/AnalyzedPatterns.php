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

namespace PHPRegex\Linter\Internal;

use PHPRegex\Parser\Validation\ValidationResult;

/**
 * What the analysis step read of each pattern, handed to the optimization
 * step so it parses and validates nothing again: the validation, and the
 * tree of a valid pattern, serialized as a cache stores it. A worker sends
 * it back to the main process with its findings.
 *
 * @internal
 */
final class AnalyzedPatterns
{
    /**
     * @param array<string, array{0: ValidationResult, 1: string|null}> $byPattern
     */
    public function __construct(public readonly array $byPattern) {}
}
