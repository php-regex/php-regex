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

namespace PHPRegex\Tests\Fixtures\Symfony\ValidatorAudit\src\Entity;

/**
 * No constraint at all: the locator does not read it, as its file never
 * names one.
 */
final class Plain
{
    public string $name = '';
}
