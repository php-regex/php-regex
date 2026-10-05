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

namespace PHPRegex\Linter\Config;

use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Checks a decoded regex.json against LintConfigSchema::definition().
 *
 * It reads the keywords the definition uses, and reports every problem of
 * a file in one pass, each message naming the key by its dotted path: an
 * unknown key, a key 2.0 removed (with the key that replaced it), a value
 * of the wrong kind.
 *
 * @internal
 */
final readonly class LintConfigValidator
{
    /**
     * @var array<string, mixed>
     */
    private array $schema;

    public function __construct()
    {
        $this->schema = LintConfigSchema::definition();
    }

    /**
     * @param \stdClass $document the file decoded with objects kept as objects
     * @param string    $file     the file named in the messages
     *
     * @return list<string> one message per problem, empty when the file is valid
     */
    public function validate(\stdClass $document, string $file): array
    {
        $errors = [];
        $this->checkNode($this->schema, $document, '', $file, $errors);

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string>            $errors
     */
    private function checkNode(array $schema, mixed $value, string $path, string $file, array &$errors): void
    {
        $schema = $this->resolve($schema);

        if (isset($schema['properties']) && $value instanceof \stdClass) {
            $this->checkObject($schema, $value, $path, $file, $errors);

            return;
        }

        if ($this->accepts($schema, $value)) {
            return;
        }

        $expected = $schema['x-expected'] ?? null;
        if (!\is_string($expected)) {
            $expected = $this->describe($schema);
        }
        if (\is_bool($value) && isset($schema['properties']) && \is_array($schema['properties']) && isset($schema['properties']['enabled'])) {
            $expected .= \sprintf(' (the boolean form was removed in 2.0: write {"enabled": %s})', $value ? 'true' : 'false');
        }

        $item = $schema['x-item'] ?? null;
        if (\is_string($item) && \is_array($value) && isset($schema['items'])) {
            foreach ($value as $entry) {
                if (!$this->accepts($this->map($schema['items']), $entry)) {
                    $errors[] = \sprintf('Invalid "%s" in %s: unknown %s %s; expected %s.', $path, $file, $item, json_encode($entry, \JSON_UNESCAPED_SLASHES), $expected);

                    return;
                }
            }
        }

        $errors[] = \sprintf('Invalid "%s" in %s: expected %s.', $path, $file, $expected);
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string>            $errors
     */
    private function checkObject(array $schema, \stdClass $value, string $path, string $file, array &$errors): void
    {
        $properties = $this->map($schema['properties']);

        foreach (get_object_vars($value) as $name => $property) {
            $name = (string) $name;
            $childPath = '' === $path ? $name : $path.'.'.$name;

            if (\array_key_exists($name, $properties)) {
                $this->checkNode($this->map($properties[$name]), $property, $childPath, $file, $errors);

                continue;
            }

            if (\array_key_exists($childPath, LintConfigSchema::REMOVED_KEYS)) {
                $replacement = LintConfigSchema::REMOVED_KEYS[$childPath];
                $errors[] = null === $replacement
                    ? \sprintf('"%s" in %s was removed in 2.0 and has no replacement: the lint command always runs the ReDoS confirmation without JIT.', $childPath, $file)
                    : \sprintf('"%s" in %s was removed in 2.0: use "%s" instead.', $childPath, $file, $replacement);

                continue;
            }

            $suggestion = $this->suggest($name, array_map(strval(...), array_keys($properties)));
            $hint = null === $suggestion ? '' : \sprintf(' Did you mean "%s"?', $suggestion);

            $errors[] = 'checks.lint.rules' === $path
                ? \sprintf('Unknown lint rule "%s" in %s of %s.%s', $name, $path, $file, $hint)
                : \sprintf('Unknown key "%s" in %s.%s', $childPath, $file, $hint);
        }
    }

    /**
     * Whether a value satisfies a schema node that holds no object: the
     * objects of the definition are walked key by key by checkObject().
     *
     * @param array<array-key, mixed> $schema
     */
    private function accepts(array $schema, mixed $value): bool
    {
        $schema = $this->resolve($schema);

        if (isset($schema['type']) && !$this->hasType($schema['type'], $value)) {
            return false;
        }

        if (isset($schema['enum'])) {
            $candidate = \is_string($value) && true === ($schema['x-case-insensitive'] ?? false) ? strtolower($value) : $value;
            if (!\in_array($candidate, $this->map($schema['enum']), true)) {
                return false;
            }
        }

        if (\array_key_exists('const', $schema) && $value !== $schema['const']) {
            return false;
        }

        if (isset($schema['minimum']) && \is_int($value) && $value < $schema['minimum']) {
            return false;
        }

        if (isset($schema['minLength']) && \is_string($value) && mb_strlen($value) < $schema['minLength']) {
            return false;
        }

        if (isset($schema['pattern']) && \is_string($schema['pattern']) && \is_string($value)
            && 1 !== LibraryPcre::match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value)) {
            return false;
        }

        if (\is_array($value)) {
            if (isset($schema['items'])) {
                foreach ($value as $item) {
                    if (!$this->accepts($this->map($schema['items']), $item)) {
                        return false;
                    }
                }
            }
            if (true === ($schema['uniqueItems'] ?? false) && \count($value) !== \count(array_unique(array_map(serialize(...), $value)))) {
                return false;
            }
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (!isset($schema[$keyword])) {
                continue;
            }
            $matches = 0;
            foreach ($this->map($schema[$keyword]) as $branch) {
                if ($this->accepts($this->map($branch), $value)) {
                    $matches++;
                }
            }
            if (0 === $matches || ('oneOf' === $keyword && $matches > 1)) {
                return false;
            }
        }

        return true;
    }

    private function hasType(mixed $types, mixed $value): bool
    {
        $actual = match (true) {
            $value instanceof \stdClass => 'object',
            \is_array($value) => 'array',
            \is_string($value) => 'string',
            \is_int($value) => 'integer',
            \is_bool($value) => 'boolean',
            default => 'other',
        };

        return \in_array($actual, \is_array($types) ? $types : [$types], true);
    }

    /**
     * What a node accepts, in words, when it carries no "x-expected".
     *
     * @param array<array-key, mixed> $schema
     */
    private function describe(array $schema): string
    {
        if (isset($schema['enum'])) {
            return 'one of '.implode(', ', array_map(static fn (mixed $value): string => \is_string($value) ? $value : (string) json_encode($value), $this->map($schema['enum'])));
        }

        $words = [
            'object' => 'an object',
            'array' => 'a list',
            'string' => isset($schema['minLength']) ? 'a non-empty string' : 'a string',
            'integer' => isset($schema['minimum']) && \is_int($schema['minimum']) ? 'an integer >= '.$schema['minimum'] : 'an integer',
            'boolean' => 'a boolean',
        ];
        $types = $schema['type'] ?? [];
        $types = \is_array($types) ? $types : [$types];

        return implode(' or ', array_filter($words, static fn (string $type): bool => \in_array($type, $types, true), \ARRAY_FILTER_USE_KEY));
    }

    /**
     * The known key the unknown one most likely meant: the same letters
     * with another case, or snake_case for camelCase.
     *
     * @param list<string> $known
     */
    private function suggest(string $name, array $known): ?string
    {
        $wanted = strtolower(str_replace('_', '', $name));
        foreach ($known as $candidate) {
            if (strtolower($candidate) === $wanted) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A node with its "$ref" followed, the node's own keywords kept.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return array<array-key, mixed>
     */
    private function resolve(array $schema): array
    {
        if (!isset($schema['$ref']) || !\is_string($schema['$ref'])) {
            return $schema;
        }

        $node = $this->schema;
        foreach (explode('/', substr($schema['$ref'], 2)) as $segment) {
            $node = $this->map($node)[$segment] ?? throw new \LogicException('Unresolvable reference '.$schema['$ref'].' in the configuration schema.');
        }

        $local = $schema;
        unset($local['$ref']);

        return $this->resolve($this->map($node)) + $local;
    }

    /**
     * A schema node read as an array; the definition holds nothing else.
     *
     * @return array<array-key, mixed>
     */
    private function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
