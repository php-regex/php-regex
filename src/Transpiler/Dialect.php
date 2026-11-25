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

namespace PHPRegex\Transpiler;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Text\Ascii;

/**
 * The regex dialects a PCRE pattern transpiles to. Each case's value is the
 * name the result, the CLI and the JSON documents give the dialect.
 */
enum Dialect: string
{
    /**
     * A JavaScript RegExp, written as a literal and as a constructor call.
     */
    case JavaScript = 'javascript';

    /**
     * The value of an HTML pattern attribute, which the browser matches
     * whole under the v flag.
     */
    case HtmlPattern = 'html-pattern';

    /**
     * A pattern for Python's re module.
     */
    case Python = 'python';

    /**
     * The dialect a name or an alias stands for: "javascript" or "js",
     * "html-pattern" or "html", "python" or "py"; case and surrounding space
     * are forgiven.
     *
     * @throws InvalidRegexOptionException when no dialect answers to the name
     */
    public static function fromName(string $name): self
    {
        $key = Ascii::lower(trim($name));
        foreach (self::cases() as $dialect) {
            if ($key === $dialect->value || \in_array($key, $dialect->aliases(), true)) {
                return $dialect;
            }
        }

        throw new InvalidRegexOptionException(\sprintf('Unknown transpile dialect: %s. Expected one of: %s.', $name, implode(', ', self::names())));
    }

    /**
     * Every name fromName() takes: each dialect's own, then its aliases, in
     * the order of the cases.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::cases() as $dialect) {
            $names = [...$names, $dialect->value, ...$dialect->aliases()];
        }

        return $names;
    }

    /**
     * The short names the dialect also answers to.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::JavaScript => ['js'],
            self::HtmlPattern => ['html'],
            self::Python => ['py'],
        };
    }
}
