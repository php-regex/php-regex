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

namespace PHPRegex\Parser\Exception;

/**
 * Provides the visual context (pattern excerpt with a caret) of a parser-related exception.
 */
interface VisualContextInterface
{
    public function getVisualSnippet(): string;
}
