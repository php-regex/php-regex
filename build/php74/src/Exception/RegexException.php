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

use PHPRegex\Parser\ErrorCode;

/**
 * Base exception for all regex-related errors.
 */
class RegexException extends \Exception implements ExceptionInterface
{
    /**
     */
    public ErrorCode $errorCode;
    /**
     */
    public ?int $position = null;
    /**
     */
    public ?string $snippet = null;

    /**
     * @internal the library throws its exceptions; catch them, read them
     */
    public function __construct(
        string $message,
        ErrorCode $errorCode,
        ?int $position = null,
        ?string $snippet = null,
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->position = $position;
        $this->snippet = $snippet;
        parent::__construct($message, 0, $previous);
    }
}
