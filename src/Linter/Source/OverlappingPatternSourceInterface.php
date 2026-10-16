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

namespace PHPRegex\Linter\Source;

/**
 * A source that may read again a pattern the PHP files hold, as the
 * Symfony validator reads a #[Regex] attribute the files also spell:
 * PatternSourceCollection drops its occurrence of a pattern the PHP files
 * gave for the same file. Any other source is a pattern of its own.
 *
 * @internal
 */
interface OverlappingPatternSourceInterface extends PatternSourceInterface {}
