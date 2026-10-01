<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Extraction;

use PhpRegex\Linter\PatternOccurrence;

/**
 * Interface for regex pattern extraction implementations.
 *
 * @api
 */
interface ExtractorInterface
{
    /**
     * Extract regex patterns from given PHP files.
     *
     * @param array<string> $files List of PHP file paths to analyze
     *
     * @return array<PatternOccurrence>
     */
    public function extract(array $files): array;
}
