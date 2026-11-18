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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

/**
 * The pattern Symfony matches a security path or host with:
 * preg_match('{'.$path.'}s', ...) in PathRequestMatcher and
 * preg_match('{'.$host.'}i', ...) in HostRequestMatcher. A path or a host
 * is a fragment, never a delimited pattern, and is compiled as written: it
 * gets no anchor, and a space or a line feed at either end is part of it.
 *
 * symfony/http-foundation adds the "s" to a path since 6.4.46, 7.4.19 and
 * 8.1.7, and never on 8.0: a path is read with the modifier of the
 * PathRequestMatcher the application runs.
 *
 * @internal
 */
final class SecurityPatternNormalizer
{
    private readonly string $pathModifier;

    public function __construct()
    {
        // Under "s", a dot takes the line feed "%0A" decodes to.
        $this->pathModifier = (new PathRequestMatcher('^/.$'))->matches(Request::create('/%0A')) ? 's' : '';
    }

    public function normalize(string $pattern, bool $host = false): string
    {
        return '{'.$pattern.'}'.($host ? 'i' : $this->pathModifier);
    }
}
