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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures;

final class ComplexityFixture
{
    public function calls(string $subject): void
    {
        preg_match('/^(ab|cd)(ef|gh)(ij|kl)(mn|op)(qr|st)(uv|wx)(yz|AB)(CD|EF)(GH|IJ)$/', $subject);
        preg_match('/^(a|b|c)(d|e|f)(g|h)$/', $subject);
        // A lint issue: reported only while lint is on.
        preg_match('/a{1}/', $subject);
    }
}
