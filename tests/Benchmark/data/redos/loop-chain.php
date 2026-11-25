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

return [
    'pattern' => '/^a+.{0,15}b+.{0,15}c+.{0,15}d+.{0,15}e+.{0,15}f+.{0,15}g+.{0,15}h+.{0,15}i+.{0,15}j+.{0,15}k+.{0,15}l+.{0,15}m+.{0,15}n+.{0,15}o+.{0,15}p+.{0,15}q+.{0,15}r+.{0,15}s+.{0,15}t+.{0,15}u+.{0,15}v+.{0,15}w+.{0,15}x+.{0,15}y+.{0,15}z+.{0,15}!/',
    'origin' => 'synthetic',
    'note' => 'Twenty-six loops, each followed by a stretch of at most fifteen characters: the paths one attempt tries at once are counted on one input, stretch by stretch, before the attempt is found past the margin.',
    'expect' => 'heuristic',
];
