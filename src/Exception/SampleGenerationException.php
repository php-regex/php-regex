<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Exception;

use RegexParser\ErrorCode;

/**
 * Raised when no sample the pattern matches was found: the pattern matches
 * nothing, as "a^b" or "(*FAIL)", or its assertions ask for more than the
 * samples give.
 */
final class SampleGenerationException extends RegexException implements RegexParserExceptionInterface
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        ErrorCode $errorCode = ErrorCode::GenerateNoMatch,
    ) {
        parent::__construct($message, $errorCode, null, null, $previous);
    }
}
