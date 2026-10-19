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

namespace PHPRegex\Symfony\Routing;

use PHPRegex\Linter\Extraction\RouteRequirementCompiler;

/**
 * The pattern Symfony's route compiler matches a requirement with: the
 * path with "{^...$}sD", the host with "{^...$}sDi", plus u under the
 * route's utf8 option, as RouteRequirementCompiler writes it.
 *
 * @internal
 */
final class RouteRequirementNormalizer
{
    /**
     * A requirement as written, sanitized as setting it on a Route does.
     */
    public function normalize(string $requirement, string $parameter, bool $utf8 = false, bool $host = false): string
    {
        return RouteRequirementCompiler::compile(RouteRequirementCompiler::sanitize($requirement), $parameter, $utf8, $host);
    }

    /**
     * A requirement as a Route holds it: sanitized when it was set, so the
     * compiler takes it as is and a doubled anchor keeps its second copy.
     */
    public function normalizeHeld(string $requirement, string $parameter, bool $utf8 = false, bool $host = false): string
    {
        return RouteRequirementCompiler::compile($requirement, $parameter, $utf8, $host);
    }
}
