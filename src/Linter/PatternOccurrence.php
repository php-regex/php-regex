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

namespace PHPRegex\Linter;

/**
 * Represents a regex pattern occurrence found in source code.
 *
 * @internal
 */
final readonly class PatternOccurrence
{
    public function __construct(
        public string $pattern,
        public string $file,
        public int $line,
        public string $source,
        public ?string $displayPattern = null,
        public ?string $location = null,
        public bool $isIgnored = false,
        public ?int $column = null,
        public ?int $fileOffset = null,
    ) {}
}
