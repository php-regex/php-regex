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
 * A preg_* pattern one match attempt of which backtracks at or above the
 * ReDoS threshold: the PHPStan extension's regex.redos. Psalm names the
 * issue after the short class name: configurations, suppressions and
 * baselines spell "RegexRedos", so the name stays for 2.x wherever the class
 * moves.
 *
 * @internal
 */
final class RegexRedos extends PluginIssue {}
