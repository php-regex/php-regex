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
 * Marks the exceptions that show the pattern: RegexException::$snippet holds
 * the line at fault with a caret under it, drawn from getPattern().
 */
interface VisualContextInterface
{
    /**
     * The pattern the snippet is drawn from, as written; null when the
     * exception was built without one.
     */
    public function getPattern(): ?string;
}
