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

namespace PhpRegex\Symfony;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony Bundle for the PhpRegex library.
 */
final class PhpRegexBundle extends Bundle
{
    /**
     * @return string the absolute path to the bundle's root directory
     */
    #[\Override]
    public function getPath(): string
    {
        return __DIR__;
    }
}
