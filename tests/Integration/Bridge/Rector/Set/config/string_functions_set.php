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

use PHPRegex\Rector\Set\RegexSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withSets([RegexSetList::STRING_FUNCTIONS]);
