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

final class TrivialMatchFixture
{
    /**
     * @param array<string> $matches
     */
    public function calls(string $subject, array $matches): void
    {
        preg_match('/^https:/', $subject);
        preg_match('/^(?:GET|POST)\z/', $this->method());
        preg_match('/^foo$/', $subject);
        preg_match('/foo/i', $subject);
        preg_match('/^foo/', $subject, $matches);
        preg_match_all('/foo/', $subject);
        preg_match('/^\d+$/', $subject);
        preg_match('/(foo/', $subject);
    }

    private function method(): string
    {
        return 'GET';
    }
}
