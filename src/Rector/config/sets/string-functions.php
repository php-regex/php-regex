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

use PHPRegex\Rector\PregMatchToStringComparisonRector;
use PHPRegex\Rector\PregReplaceToStrReplaceRector;
use PHPRegex\Rector\PregSplitToExplodeRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rules([
        PregMatchToStringComparisonRector::class,
        PregReplaceToStrReplaceRector::class,
        PregSplitToExplodeRector::class,
    ]);
};
