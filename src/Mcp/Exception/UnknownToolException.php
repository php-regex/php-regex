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

namespace PHPRegex\Mcp\Exception;

/**
 * A tool name the catalog does not serve.
 */
final class UnknownToolException extends \InvalidArgumentException implements ExceptionInterface
{
    public static function named(string $name): self
    {
        return new self(\sprintf('Unknown tool "%s".', $name));
    }
}
