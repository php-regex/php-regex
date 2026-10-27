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

namespace PHPRegex\Linter\Exception;

use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Json\JsonDocument;

/**
 * A lint run that stops before its report: what stopped it, the stage the
 * JSON error envelope names, and the exit code every front end ends with.
 *
 * @internal
 */
final class LintRunFailureException extends \RuntimeException implements ExceptionInterface
{
    /**
     * The run found what it was asked to report: a pattern fails, or the
     * run broke on the way.
     */
    public const EXIT_FAILURE = 1;

    /**
     * The run was asked for something it cannot do: a usage, a setting or
     * a target it cannot read.
     */
    public const EXIT_INVALID = 2;

    /**
     * @param JsonDocument::STAGE_* $stage
     */
    public function __construct(
        string $message,
        public readonly string $stage,
        public readonly int $exitCode,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
