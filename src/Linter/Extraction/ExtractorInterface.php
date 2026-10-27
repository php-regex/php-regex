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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Linter\PatternOccurrence;

/**
 * Reads the patterns of PHP files: the built-in extractors read the native
 * calls and the configured functions, one of your own reads the calls it
 * knows. Build each occurrence with PatternOccurrence::found().
 */
interface ExtractorInterface
{
    /**
     * Extract regex patterns from given PHP files.
     *
     * @param array<string> $files List of PHP file paths to analyze
     *
     * @return list<PatternOccurrence>
     */
    public function extract(array $files): array;
}
