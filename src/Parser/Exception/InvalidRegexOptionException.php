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
 * Thrown when invalid options are passed to `Regex::create()`, when
 * `CaptureShape::matchShape()` is given a flag preg_match() refuses, or when
 * `CaptureShape::matchAllShape()` is given one preg_match_all() refuses.
 */
final class InvalidRegexOptionException extends \InvalidArgumentException implements ExceptionInterface
{
    /**
     * @internal the library throws its exceptions; catch them, read them
     *
     * @param string|null $option the option at fault, by the key the options
     *                            array gives it ("php_version",
     *                            "max_pattern_length"); null when the fault
     *                            is no single option's
     */
    public function __construct(
        string $message,
        public readonly ?string $option = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
