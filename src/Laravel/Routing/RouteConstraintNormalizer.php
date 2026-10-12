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

namespace PHPRegex\Laravel\Routing;

use Illuminate\Routing\Route;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\RegexParser;

/**
 * The pattern Laravel's router matches a route constraint with. A route
 * compiles through Symfony's RouteCompiler, always under the utf8 option:
 * the constraint loses a leading ^ or \A and a trailing $ or \z, as
 * Route::sanitizeRequirement() does, sits in a group of its own, and the
 * path is matched with "{^...$}sDu", the domain with "{^...$}sDiu".
 *
 * A top-level alternation is grouped here, so that every alternative stays
 * anchored. A constraint is a fragment, never a delimited pattern: the
 * delimiter is one the constraint does not contain, so that nothing in it
 * needs escaping.
 *
 * @internal
 */
final readonly class RouteConstraintNormalizer
{
    private const DELIMITERS = ['/', '#', '~', '!', '@', '%', '`'];

    private RegexParser $parser;

    public function __construct(?RegexParser $parser = null)
    {
        $this->parser = $parser ?? RegexParser::create();
    }

    public function normalize(Route $route, string $parameter, string $constraint): string
    {
        $flags = $this->isDomainParameter($route, $parameter) ? 'sDiu' : 'sDu';
        $delimiter = $this->delimiterFor($constraint);
        $body = $this->escapeDelimiter($this->sanitize($constraint), $delimiter);

        if ($this->hasTopLevelAlternation($body, $delimiter, $flags)) {
            $body = '(?:'.$body.')';
        }

        return $delimiter.'^'.$body.'$'.$delimiter.$flags;
    }

    /**
     * The constraint as Route::sanitizeRequirement() leaves it: a trailing
     * $ goes even escaped, and a \z only where it first stands.
     */
    private function sanitize(string $constraint): string
    {
        if (str_starts_with($constraint, '^')) {
            $constraint = substr($constraint, 1);
        } elseif (str_starts_with($constraint, '\\A')) {
            $constraint = substr($constraint, 2);
        }

        if (str_ends_with($constraint, '$')) {
            return substr($constraint, 0, -1);
        }

        if (\strlen($constraint) - 2 === strpos($constraint, '\\z')) {
            return substr($constraint, 0, -2);
        }

        return $constraint;
    }

    /**
     * A parameter of the domain and not of the path is matched by the host
     * pattern, which the route compiler reads case-insensitively.
     */
    private function isDomainParameter(Route $route, string $parameter): bool
    {
        $domain = $route->getDomain() ?? '';

        return $this->namesParameter($domain, $parameter) && !$this->namesParameter($route->uri(), $parameter);
    }

    private function namesParameter(string $template, string $parameter): bool
    {
        return str_contains($template, '{'.$parameter.'}') || str_contains($template, '{'.$parameter.'?}');
    }

    private function delimiterFor(string $constraint): string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (!str_contains($constraint, $delimiter)) {
                return $delimiter;
            }
        }

        return self::DELIMITERS[0];
    }

    /**
     * Escapes the delimiter where it stands bare; one a backslash already
     * escapes is left as it is.
     */
    private function escapeDelimiter(string $body, string $delimiter): string
    {
        $escaped = '';
        $length = \strlen($body);

        for ($i = 0; $i < $length; $i++) {
            if ('\\' === $body[$i] && $i + 1 < $length) {
                $escaped .= $body[$i].$body[++$i];
            } elseif ($delimiter === $body[$i]) {
                $escaped .= '\\'.$delimiter;
            } else {
                $escaped .= $body[$i];
            }
        }

        return $escaped;
    }

    /**
     * Whether the body is an alternation at its top level, as the parser
     * reads it. A body that does not parse is left as it is: the error is
     * the lint's to report.
     */
    private function hasTopLevelAlternation(string $body, string $delimiter, string $flags): bool
    {
        try {
            return $this->parser->parsePattern($body, $flags, $delimiter)->pattern instanceof AlternationNode;
        } catch (ExceptionInterface) {
            return false;
        }
    }
}
