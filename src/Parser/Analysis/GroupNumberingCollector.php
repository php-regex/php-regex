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

use PHPRegex\Parser\Internal\GroupIndex;
use PHPRegex\Parser\Node\RegexNode;

/**
 * Collects PCRE-aware group numbering metadata, including branch reset groups.
 */
final class GroupNumberingCollector
{
    public function collect(RegexNode $ast): GroupNumbering
    {
        return GroupIndex::of($ast->pattern)->numbering();
    }
}
