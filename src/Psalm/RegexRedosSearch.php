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

namespace PHPRegex\Psalm;

use Psalm\Issue\PluginIssue;

/**
 * A preg_* pattern one match attempt of which is proven linear while its
 * search is quadratic, through the retries of an unanchored search or the
 * loop of a function that looks for every match: the PHPStan extension's
 * regex.redos.search. Psalm names the issue after the short class name:
 * configurations, suppressions and baselines spell "RegexRedosSearch", so
 * the name stays for 2.x wherever the class moves.
 *
 * @internal
 */
final class RegexRedosSearch extends PluginIssue {}
