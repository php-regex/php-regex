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

namespace PHPRegex\Parser\Analysis;

/**
 * Represents PCRE-aware group numbering metadata for a regex pattern.
 */
final class GroupNumbering
{
    /**
     */
    public int $maxGroupNumber;
    /**
     * @var array<int>
     *
     */
    public array $captureSequence;
    /**
     * @var array<string, array<int>>
     *
     */
    public array $namedGroups;

    /**
     * @internal built by GroupNumberingCollector::collect()
     *
     * @param array<int>                $captureSequence
     * @param array<string, array<int>> $namedGroups
     */
    public function __construct(int $maxGroupNumber, array $captureSequence, array $namedGroups)
    {
        $this->maxGroupNumber = $maxGroupNumber;
        $this->captureSequence = $captureSequence;
        $this->namedGroups = $namedGroups;
    }

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
