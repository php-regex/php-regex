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
 * An extractor that can be handed the constants of the project's files,
 * read once before the files are shared out: a call naming a constant of
 * a class its own file does not declare reads it through them, under the
 * rules of the file. Used on its own, it reads the file's constants only.
 *
 * @internal
 */
interface ProjectConstantsAwareInterface
{
    /**
     * A copy that resolves a class the file does not declare through the
     * given constants.
     */
    public function withProjectConstants(ProjectConstants $constants): static;
}
