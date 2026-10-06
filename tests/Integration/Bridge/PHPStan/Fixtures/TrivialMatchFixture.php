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
        // Locale-dependent in byte mode, or a verb that changes how PCRE
        // runs the pattern: no string function is named.
        preg_match('/^\s\z/', $subject);
        preg_match('/^foo\z/i', $subject);
        preg_match('/(*LIMIT_MATCH=1)foo/', $subject);
        // One string reached along many paths: preg_match() can fail on the
        // backtrack limit where str_contains() answers.
        preg_match('/(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}b/', $subject);
        // A raw 0xA0 the locale may call white space in extended mode.
        preg_match("/prix\xa0eur/x", $subject);
    }

    private function method(): string
    {
        return 'GET';
    }
}
