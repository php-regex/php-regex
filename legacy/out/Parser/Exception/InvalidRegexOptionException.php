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
final class InvalidRegexOptionException extends \InvalidArgumentException implements ExceptionInterface {}
