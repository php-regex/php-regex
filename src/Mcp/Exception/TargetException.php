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
 * A target a call names that no pattern can be judged for: an option
 * that is not a version, or a PHP that ships PCRE1.
 */
final class TargetException extends \InvalidArgumentException implements ExceptionInterface
{
    /**
     * @param string $stage the stage of the CLI's error envelope: "usage" for an option that cannot be read, "target" for a PHP the library does not judge
     */
    public function __construct(
        string $message,
        public readonly string $stage,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
