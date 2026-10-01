<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Analysis;

/**
 * Represents PCRE-aware group numbering metadata for a regex pattern.
 */
final readonly class GroupNumbering
{
    /**
     * @param array<int>                $captureSequence
     * @param array<string, array<int>> $namedGroups
     */
    public function __construct(
        public int $maxGroupNumber,
        public array $captureSequence,
        public array $namedGroups,
    ) {}

    public function hasNamedGroup(string $name): bool
    {
        return isset($this->namedGroups[$name]);
    }

    /**
     * @return array<int>
     */
    public function getNamedGroupNumbers(string $name): array
    {
        return $this->namedGroups[$name] ?? [];
    }

    public function getCaptureCount(): int
    {
        return \count($this->captureSequence);
    }
}
