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

namespace Fixture;

/**
 * Two patterns a suite tests only in part: the escaped mutants say which
 * subjects it never passes.
 */
final class Slug
{
    public static function isValid(string $slug): bool
    {
        return 1 === preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    public static function version(string $tag): ?string
    {
        return 1 === preg_match('/^v(\d+)\.(\d+)$/', $tag, $matches) ? $matches[1].'.'.$matches[2] : null;
    }
}
