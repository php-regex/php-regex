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

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A sign-up form whose constraints say one thing to the server and
 * another to the browser.
 */
final class Signup
{
    /**
     * The flag drops the HTML pattern.
     */
    #[Assert\Regex('/^[a-z0-9._%+-]+@[a-z0-9-]+\.[a-z]{2,}$/i')]
    public string $email = '';

    /**
     * The browser refuses "[a-z0-9-]" under v.
     */
    #[Assert\Regex('/^[a-z0-9-]+$/')]
    public string $slug = '';

    /**
     * Nothing to say: the browser reads it as the server does.
     */
    #[Assert\Regex('/^\d{5}$/')]
    public string $zip = '';

    /**
     * The htmlPattern lets through what the pattern refuses.
     */
    #[Assert\Regex(pattern: '/^\d+$/', htmlPattern: '[0-9a-f]+')]
    public string $code = '';

    /**
     * No value of five letters is at most three characters long.
     */
    #[Assert\Sequentially([new Assert\Length(max: 3), new Assert\Regex('/^[A-Z]{5}$/')])]
    public string $ticker = '';

    /**
     * "Gold" is offered and refused.
     */
    #[Assert\Choice(choices: ['bronze', 'silver', 'Gold'])]
    #[Assert\Regex('/^[a-z]+$/')]
    public string $tier = '';

    /**
     * No value is both digits and letters.
     */
    #[Assert\Regex('/^\d+$/')]
    #[Assert\Regex('/^[a-z]+$/')]
    public string $pin = '';

    /**
     * The value must hold no digit: the derived HTML pattern agrees.
     */
    #[Assert\Regex(pattern: '/\d/', match: false)]
    public string $nickname = '';
}
