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

namespace PHPRegex\Parser\Attribute;

/**
 * Marks a parameter that receives a regex pattern, as a project's own
 * wrapper around preg_match() takes one:
 *
 *     public static function matches(string $subject, #[Pattern] string $regex): bool
 *
 * `regex lint` then reads the pattern of every call to the function, or to
 * the static method, as it reads the one of a preg_*() call. PHP never loads
 * the class unless the attribute is instantiated by reflection, so requiring
 * the package as a dev dependency is enough.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Pattern {}
