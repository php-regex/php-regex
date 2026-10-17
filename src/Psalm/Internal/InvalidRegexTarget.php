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
 * A "@regex-target" marker whose constraint cannot be read, or that keeps
 * no version Psalm's PHP is judged for. Psalm names the issue after the
 * short class name: configurations, suppressions and baselines spell
 * "InvalidRegexTarget", so the name stays for 2.x wherever the class moves.
 *
 * @internal
 */
final class InvalidRegexTarget extends PluginIssue {}
