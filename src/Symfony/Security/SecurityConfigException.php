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

namespace PHPRegex\Symfony\Security;

use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * The security configuration cannot be read: a file that is missing or is
 * no YAML, a parameter nothing defines, a configuration the security bundle
 * refuses, symfony/yaml not installed.
 *
 * @internal
 */
final class SecurityConfigException extends \RuntimeException implements ExceptionInterface {}
