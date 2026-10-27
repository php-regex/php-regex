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

namespace PHPRegex\Tests\Support;

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\PatternOccurrence;

/**
 * An extractor an application writes for its own helper: it reads the
 * pattern of each check('...') call, and nothing else, as the public
 * contract alone lets it.
 */
final class HelperCallExtractor implements ExtractorInterface
{
    public function extract(array $files): array
    {
        $occurrences = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $index => $line) {
                if (1 === preg_match("~\\bcheck\\('([^']*)'~", $line, $match, \PREG_OFFSET_CAPTURE)) {
                    // The column of the opening quote, counted from 1: the
                    // pattern starts one byte after it.
                    $occurrences[] = PatternOccurrence::found($match[1][0], $file, $index + 1, $match[1][1], 'check()');
                }
            }
        }

        return $occurrences;
    }
}
