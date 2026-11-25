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

/**
 * An extractor an application writes around the built-in one, to read its
 * own helper beside the calls the library reads: the built-in occurrences,
 * then those of each check('...') call.
 */
final class DecoratingHelperCallExtractor implements ExtractorInterface
{
    public function __construct(private readonly ExtractorInterface $inner) {}

    public function extract(array $files): array
    {
        return [...$this->inner->extract($files), ...(new HelperCallExtractor())->extract($files)];
    }
}
