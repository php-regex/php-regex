<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Extraction;

/**
 * The namespace and imports in effect at a point in a PHP file.
 *
 * The tokenizer sees "Preg::match()" without knowing which class Preg is.
 * Tracking the current namespace and the use statements above the call turns
 * that into a fully qualified name, so a wrapper is recognised only where it
 * was actually imported and a project class of the same name is left alone.
 *
 * @internal
 */
final class NameResolutionContext
{
    private string $namespace = '';

    /**
     * @var array<string, string> lowercase alias => fully qualified class name
     */
    private array $classAliases = [];

    /**
     * @var array<string, string> lowercase alias => fully qualified function name
     */
    private array $functionAliases = [];

    /**
     * Enter a namespace, dropping the imports of the previous block.
     */
    public function enterNamespace(string $namespace): void
    {
        $this->namespace = trim($namespace, '\\');
        $this->classAliases = [];
        $this->functionAliases = [];
    }

    public function importClass(string $alias, string $target): void
    {
        $this->classAliases[strtolower($alias)] = ltrim($target, '\\');
    }

    public function importFunction(string $alias, string $target): void
    {
        $this->functionAliases[strtolower($alias)] = ltrim($target, '\\');
    }

    /**
     * Fully qualify a class name as written at the call site.
     */
    public function resolveClass(string $written): string
    {
        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }

        $segments = explode('\\', $written);
        $first = strtolower($segments[0]);

        if (isset($this->classAliases[$first])) {
            $segments[0] = $this->classAliases[$first];

            return implode('\\', $segments);
        }

        if ('namespace' === $first) {
            array_shift($segments);

            return $this->qualify(implode('\\', $segments));
        }

        return $this->qualify($written);
    }

    /**
     * Fully qualify a function name as written at the call site.
     *
     * An unqualified name is reported as-is: PHP looks it up in the current
     * namespace and falls back to the global function, which is the one the
     * registry knows about.
     */
    public function resolveFunction(string $written): string
    {
        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }

        if (!str_contains($written, '\\')) {
            return $this->functionAliases[strtolower($written)] ?? $written;
        }

        return $this->resolveClass($written);
    }

    private function qualify(string $name): string
    {
        if ('' === $this->namespace || '' === $name) {
            return $name;
        }

        return $this->namespace.'\\'.$name;
    }
}
