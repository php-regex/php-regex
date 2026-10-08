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

/**
 * The pattern Symfony matches a security path or host with:
 * preg_match('{'.$path.'}s', ...) in PathRequestMatcher and
 * preg_match('{'.$host.'}i', ...) in HostRequestMatcher. A path or a host
 * is a fragment, never a delimited pattern, and gets no anchor.
 *
 * @internal
 */
final readonly class SecurityPatternNormalizer
{
    public function normalize(string $pattern, bool $host = false): string
    {
        return '{'.trim($pattern).'}'.($host ? 'i' : 's');
    }
}
