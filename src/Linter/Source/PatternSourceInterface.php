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

namespace PHPRegex\Linter\Source;

use PHPRegex\Linter\PatternOccurrence;

/**
 * Provides regex pattern occurrences from a specific source.
 *
 * @internal
 */
interface PatternSourceInterface
{
    public function getName(): string;

    public function isSupported(): bool;

    /**
     * @return array<PatternOccurrence>
     */
    public function extract(PatternSourceContext $context): array;
}
