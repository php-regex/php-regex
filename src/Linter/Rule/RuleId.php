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

namespace PHPRegex\Linter\Rule;

/**
 * The two spellings of a lint rule's identifier: in full, as an issue
 * carries it ("regex.lint.quantifier.useless"), and short, as a rules map
 * may write it ("quantifier.useless").
 *
 * @internal
 */
final class RuleId
{
    /**
     * What the full identifier of every lint rule starts with.
     */
    public const PREFIX = 'regex.lint.';

    private function __construct() {}

    /**
     * The identifier without the prefix; one without it as it is.
     */
    public static function short(string $id): string
    {
        return str_starts_with($id, self::PREFIX) ? substr($id, \strlen(self::PREFIX)) : $id;
    }

    /**
     * The identifier with the prefix; one with it as it is.
     */
    public static function full(string $id): string
    {
        return str_starts_with($id, self::PREFIX) ? $id : self::PREFIX.$id;
    }

    /**
     * Whether the identifier is the full one of a lint rule the library
     * runs, not of an analysis issue spelled under the same prefix.
     */
    public static function isLintRule(string $id): bool
    {
        return str_starts_with($id, self::PREFIX) && \in_array(self::short($id), RuleCatalog::lintRuleIds(), true);
    }
}
