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
final readonly class TranspileResult implements \JsonSerializable
{
    /**
     * @internal built by Transpiler::transpile() and Regex::transpile()
     *
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

    /**
     * @return array{target: string, source: string, pattern: string, flags: string, literal: string, constructor: string, warnings: array<int, string>, notes: array<int, string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'target' => $this->target,
            'source' => $this->source,
            'pattern' => $this->pattern,
            'flags' => $this->flags,
            'literal' => $this->literal,
            'constructor' => $this->constructor,
            'warnings' => $this->warnings,
            'notes' => $this->notes,
        ];
    }
}
