<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Exception;

use PhpRegex\Parser\ErrorCode;

/**
 * Base exception for all regex-related errors.
 */
class RegexException extends \Exception implements ExceptionInterface
{
    public function __construct(
        string $message,
        public readonly ErrorCode $errorCode,
        public readonly ?int $position = null,
        public readonly ?string $snippet = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function getSnippet(): ?string
    {
        return $this->snippet;
    }
}
