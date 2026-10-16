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

namespace PHPRegex\Tests\Fixtures\Symfony\ValidatorDuplicate;

use Symfony\Component\Validator\Constraints as Assert;

final class RegexConstrainedEntity
{
    #[Assert\Regex('/attribute-and-validator/')]
    public string $name = '';
}
