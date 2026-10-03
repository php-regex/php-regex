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

final class PregReplaceCallbackArray
{
    public function a(string $subject): void
    {
        preg_replace_callback_array(
            [
                '/foo' => fn ($m) => '', // Refused by the running engine: ignored
                '/(a+)+$/' => fn ($m) => '', // ReDoS (critical) -> regex.redos
                '/valid/' => fn ($m) => '',
            ],
            $subject,
        );
    }
}
