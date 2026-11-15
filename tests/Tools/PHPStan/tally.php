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

// Tally installs under tools/tally on PHP 8.2 and later only. Where it is
// installed, the counted benchmarks read their attributes from it; a job
// that runs PHPStan on PHP 8.1, or without the tool, scans nothing more,
// where a missing directory would stop the analysis. PHPStan keeps the
// container it compiled from this answer in .cache/phpstan: after
// installing or removing the tool, empty .cache/phpstan/cache once.
$tally = \dirname(__DIR__, 3).'/tools/tally/vendor/phpstan/tally/src';

return ['parameters' => ['scanDirectories' => is_dir($tally) ? [$tally] : []]];
