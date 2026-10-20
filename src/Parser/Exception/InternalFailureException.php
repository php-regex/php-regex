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
 * The library could not finish its own work: one of its regexes gave up,
 * or it reached a state it does not expect. No verdict on the pattern it
 * was reading, which is never reported as the pattern's fault: it is not a
 * RegexException, and validate() and parseTolerant() let it through.
 *
 * The usual cause is a host where PHP refuses to raise pcre.backtrack_limit
 * or pcre.recursion_limit (a disabled ini_set(), a php_admin_value) while
 * they stand below PHP's defaults: raising them, or allowing ini_set(),
 * lets the library work again.
 */
final class InternalFailureException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param int $pcreError the preg_last_error() code of a regex that gave
     *                       up, PREG_NO_ERROR for a state the library does
     *                       not expect
     */
    private function __construct(string $message, public readonly int $pcreError)
    {
        parent::__construct($message);
    }

    /**
     * One of the library's own regexes gave no answer.
     *
     * @param int $pcreError the preg_last_error() code it left
     */
    public static function pcreGaveUp(int $pcreError): self
    {
        $reason = match ($pcreError) {
            \PREG_BACKTRACK_LIMIT_ERROR => 'the backtrack limit (pcre.backtrack_limit) was exhausted',
            \PREG_RECURSION_LIMIT_ERROR => 'the recursion limit (pcre.recursion_limit) was exhausted',
            \PREG_JIT_STACKLIMIT_ERROR => 'the JIT stack limit was exhausted',
            default => \sprintf('PCRE error %d', $pcreError),
        };

        return new self(\sprintf('A regex of the library gave up: %s. Raise the limit, or allow ini_set() so the library can raise it for its own regexes.', $reason), $pcreError);
    }

    /**
     * A state the library does not expect, which is a bug to report.
     */
    public static function unexpectedState(string $message): self
    {
        return new self($message, \PREG_NO_ERROR);
    }
}
