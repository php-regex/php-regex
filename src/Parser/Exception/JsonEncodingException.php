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
 * A value JsonDocument cannot write: a non-finite float, or an object with
 * no JSON mapping. A mapping missing, never a fault of the input.
 */
final class JsonEncodingException extends \RuntimeException implements ExceptionInterface {}
