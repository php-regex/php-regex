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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\PcreTarget;

/**
 * The validator's answer for one PHP version and PCRE2 release.
 */
final readonly class TargetVerdict
{
    /**
     * @internal built by CompatibilityChecker::check()
     */
    public function __construct(public PcreTarget $target, public ValidationResult $validation) {}
}
