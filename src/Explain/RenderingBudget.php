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

namespace PHPRegex\Explain;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ResourceLimitException;

/**
 * Bounds expanded output, including indentation and line separators.
 *
 * @internal
 */
final class RenderingBudget
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private int $bytes = 0;

    public function reserve(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES - $this->bytes) {
            throw new ResourceLimitException('Explanation rendering exceeds the maximum output size of 8388608 bytes.', ErrorCode::RenderingOutputTooLarge);
        }

        $this->bytes += $bytes;
    }
}
