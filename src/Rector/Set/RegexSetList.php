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

namespace PHPRegex\Rector\Set;

/**
 * The sets of PHPRegex rules, for RectorConfig::configure()->withSets().
 */
final class RegexSetList
{
    /**
     * The preg_*() calls a string function answers alike, as the automata
     * prove: preg_match() to str_contains() and its siblings, preg_replace()
     * to str_replace(), preg_split() to explode().
     */
    public const STRING_FUNCTIONS = __DIR__.'/../config/sets/string-functions.php';

    /**
     * The patterns PCRE2 10.43 (PHP 8.4) reads otherwise, rewritten so they
     * mean on PHP 8.4 what they mean today: "/a{,3}/" to "/a\{,3}/".
     */
    public const PCRE_UPGRADE = __DIR__.'/../config/sets/pcre-upgrade.php';
}
