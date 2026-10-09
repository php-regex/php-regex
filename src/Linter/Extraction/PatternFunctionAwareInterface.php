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
     * "App\Str::matches#1") in place of scanning its files for them. The
     * functions it already knows (configured, presets, native) keep their
     * entry; several specs of one function read the union of their
     * arguments. $plain names the namespaced functions declared without a
     * pattern parameter, which an unqualified call in their namespace
     * reaches before a global pattern function of the same name.
     *
     * @param array<string> $specs
     * @param array<string> $plain
     */
    public function withPatternFunctions(array $specs, array $plain = []): static;

    /**
     * The global functions configured as pattern functions ("grep"), in
     * lower case: a namespaced function of the same name, marked or not,
     * answers an unqualified call in its namespace before them. A native
     * function is not among them: a namespaced copy of it keeps its
     * signature.
     *
     * @return list<string>
     */
    public function customGlobalFunctions(): array;
}
