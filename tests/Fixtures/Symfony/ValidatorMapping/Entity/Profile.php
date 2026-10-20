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

namespace PHPRegex\Tests\Fixtures\Symfony\ValidatorMapping\Entity;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mapped with an attribute, which the PHP files give.
 */
final class Profile
{
    #[Assert\Regex('/[0-9/')]
    public string $zip = '';
}
