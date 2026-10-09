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

namespace PHPRegex\Linter\Extraction;

/**
 * An extractor that can be handed the pattern functions the project
 * declares (the parameters marked #[RegexPattern] or #[Language('RegExp')]),
 * read once over the whole project before the files are shared out.
 *
 * Handed them, it reads those and no longer scans the files it extracts for
 * declarations; used on its own, it still does. An extractor that does not
 * implement this interface is given the files alone, as before.
 *
 * @internal
 */
interface PatternFunctionAwareInterface
{
    /**
     * A copy that reads the given pattern functions ("App\grep#0",
     * "App\Str::matches#1") in place of scanning its files for them.
     *
     * @param array<string> $specs
     */
    public function withPatternFunctions(array $specs): static;
}
