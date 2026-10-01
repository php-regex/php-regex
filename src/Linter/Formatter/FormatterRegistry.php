<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Formatter;

use PhpRegex\Linter\LintException;

/**
 * Registry for managing output formatters.
 */
final class FormatterRegistry
{
    /**
     * @var array<string, \PhpRegex\Linter\Formatter\OutputFormatterInterface>
     */
    private array $formatters = [];

    public function __construct()
    {
        $this->register('console', new ConsoleFormatter());
        $this->register('json', new JsonFormatter());
        $this->register('github', new GithubFormatter());
        $this->register('checkstyle', new CheckstyleFormatter());
        $this->register('junit', new JunitFormatter());
    }

    /**
     * Register a formatter with a specific name.
     */
    public function register(string $name, OutputFormatterInterface $formatter): void
    {
        $this->formatters[$name] = $formatter;
    }

    /**
     * Get a formatter by name.
     */
    public function get(string $name): OutputFormatterInterface
    {
        if (!isset($this->formatters[$name])) {
            throw new LintException(\sprintf('Formatter "%s" not found. Available formatters: %s',
                $name, implode(', ', array_keys($this->formatters))));
        }

        return $this->formatters[$name];
    }

    /**
     * Check if a formatter is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->formatters[$name]);
    }

    /**
     * Get all registered formatter names.
     *
     * @return array<string>
     */
    public function getNames(): array
    {
        return array_keys($this->formatters);
    }

    /**
     * Override a formatter with a specific name.
     */
    public function override(string $name, OutputFormatterInterface $formatter): void
    {
        $this->formatters[$name] = $formatter;
    }
}
