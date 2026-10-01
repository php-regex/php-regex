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

namespace PHPRegex\Transpiler;

/**
 * Captures transpilation output for a target regex dialect.
 */
final readonly class TranspileResult
{
    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $notes
     */
    public function __construct(
        public string $source,
        public string $target,
        public string $pattern,
        public string $flags,
        public string $literal,
        public string $constructor,
        public array $warnings = [],
        public array $notes = [],
    ) {}

    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }

    public function hasNotes(): bool
    {
        return [] !== $this->notes;
    }
}
