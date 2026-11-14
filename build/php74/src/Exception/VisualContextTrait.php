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
 * Provides common visual context helpers for parser-related exceptions.
 *
 * @internal
 */
trait VisualContextTrait
{
    private ?string $pattern = null;

    /**
     * The pattern the snippet is drawn from, as written.
     */
    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    /**
     * Keeps the pattern, and draws the snippet the exception carries as
     * RegexException::$snippet.
     */
    private function initializeContext(?int $position, ?string $pattern): string
    {
        $this->pattern = $pattern;

        return Snippet::render($pattern, $position);
    }
}
