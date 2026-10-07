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

namespace PHPRegex\Psalm\Internal;

use Psalm\Issue\PluginIssue;

/**
 * A preg_* pattern the targeted PHP and PCRE2 refuse. Psalm names the issue
 * after the short class name: configurations, suppressions and baselines
 * spell "InvalidRegexPattern", so the name stays for 2.x wherever the class
 * moves.
 *
 * @internal
 */
final class InvalidRegexPattern extends PluginIssue {}
