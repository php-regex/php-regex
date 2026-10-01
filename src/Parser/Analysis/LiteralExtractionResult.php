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
 * Structured literal extraction output for fast prefilters.
 */
final readonly class LiteralExtractionResult
{
    /**
     * @param array<string> $literals
     * @param array<string> $patterns
     */
    public function __construct(
        public array $literals,
        public array $patterns,
        public string $confidence,
        public LiteralSet $literalSet,
    ) {}
}
