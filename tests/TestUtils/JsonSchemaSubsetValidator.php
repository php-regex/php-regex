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

namespace PhpRegex\Tests\TestUtils;

/**
 * A validator for the part of JSON Schema a configuration schema uses.
 *
 * The schema is read as an associative array (json_decode(..., true)); the
 * document as decoded objects (json_decode(..., false)), so that an empty
 * object and an empty list stay apart. A keyword the validator does not know
 * is an error of the test, not a pass: a schema growing a keyword makes this
 * class fail loudly until it learns it.
 */
final class JsonSchemaSubsetValidator
{
    /**
     * Keywords that describe and never constrain ("format" is an annotation
     * in draft 2020-12 unless a validator opts into its assertion vocabulary).
     */
    private const ANNOTATIONS = [
        '$schema', '$id', '$comment', 'title', 'description', 'markdownDescription', 'default', 'examples',
        'deprecated', 'enumDescriptions', '$defs', 'definitions', 'format',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $root = [];

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> one message per violation, empty when the document is valid
     */
    public function validate(array $schema, mixed $document): array
    {
        $this->root = $schema;

        return $this->check($schema, $document, '$');
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private function check(array $schema, mixed $value, string $at): array
    {
        $errors = [];

        foreach ($schema as $keyword => $argument) {
            $keyword = (string) $keyword;
            if (\in_array($keyword, self::ANNOTATIONS, true) || str_starts_with($keyword, 'x-')) {
                continue;
            }

            $found = match ($keyword) {
                '$ref' => $this->check($this->resolve($argument), $value, $at),
                'type' => $this->checkType($argument, $value, $at),
                'enum' => \in_array($this->plain($value), $this->list($argument), true) ? [] : [$at.': not one of the enum values'],
                'const' => $this->plain($value) === $argument ? [] : [$at.': not the constant value'],
                'properties' => $this->checkProperties($this->map($argument), $value, $at),
                'additionalProperties' => $this->checkAdditional($schema, $argument, $value, $at),
                'required' => $this->checkRequired($this->list($argument), $value, $at),
                'items' => $this->checkItems($this->map($argument), $value, $at),
                'minItems' => \is_array($value) && \count($value) < $argument ? [$at.': too few items'] : [],
                'uniqueItems' => \is_array($value) && true === $argument && \count($value) !== \count(array_unique(array_map(serialize(...), array_map($this->plain(...), $value)))) ? [$at.': duplicate items'] : [],
                'minimum' => (\is_int($value) || \is_float($value)) && $value < $argument ? [$at.': below the minimum'] : [],
                'maximum' => (\is_int($value) || \is_float($value)) && $value > $argument ? [$at.': above the maximum'] : [],
                'minLength' => \is_string($value) && mb_strlen($value) < $argument ? [$at.': string too short'] : [],
                'pattern' => \is_string($value) && 1 !== preg_match('/'.str_replace('/', '\/', $this->string($argument)).'/u', $value) ? [$at.': does not match the pattern'] : [],
                'anyOf' => $this->countValid($argument, $value, $at) >= 1 ? [] : [$at.': matches none of anyOf'],
                'oneOf' => 1 === $this->countValid($argument, $value, $at) ? [] : [$at.': does not match exactly one of oneOf'],
                'allOf' => $this->checkAll($argument, $value, $at),
                default => throw new \LogicException(\sprintf('The test validator does not implement the "%s" keyword (at %s).', $keyword, $at)),
            };

            array_push($errors, ...$found);
        }

        return $errors;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function resolve(mixed $ref): array
    {
        if (!\is_string($ref) || !str_starts_with($ref, '#/')) {
            throw new \LogicException('The test validator only resolves local references, not '.var_export($ref, true).'.');
        }

        $node = $this->root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                throw new \LogicException('Unresolvable reference '.$ref.'.');
            }
            $node = $node[$segment];
        }

        return $this->map($node);
    }

    /**
     * @return list<string>
     */
    private function checkType(mixed $types, mixed $value, string $at): array
    {
        foreach (\is_array($types) ? $types : [$types] as $type) {
            $matches = match ($type) {
                'object' => $value instanceof \stdClass,
                'array' => \is_array($value) && array_is_list($value),
                'string' => \is_string($value),
                'integer' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                'boolean' => \is_bool($value),
                'null' => null === $value,
                default => throw new \LogicException('Unknown JSON type '.var_export($type, true).'.'),
            };
            if ($matches) {
                return [];
            }
        }

        return [$at.': expected type '.implode('|', array_map(strval(...), \is_array($types) ? $types : [$types]))];
    }

    /**
     * @param array<array-key, mixed> $properties
     *
     * @return list<string>
     */
    private function checkProperties(array $properties, mixed $value, string $at): array
    {
        if (!$value instanceof \stdClass) {
            return [];
        }

        $errors = [];
        foreach (get_object_vars($value) as $name => $property) {
            if (\array_key_exists($name, $properties)) {
                array_push($errors, ...$this->check($this->map($properties[$name]), $property, $at.'.'.$name));
            }
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private function checkAdditional(array $schema, mixed $additional, mixed $value, string $at): array
    {
        if (!$value instanceof \stdClass) {
            return [];
        }

        $known = isset($schema['properties']) ? $this->map($schema['properties']) : [];
        $errors = [];
        foreach (get_object_vars($value) as $name => $property) {
            if (\array_key_exists($name, $known)) {
                continue;
            }
            if (false === $additional) {
                $errors[] = $at.'.'.$name.': unknown property';
            } elseif (\is_array($additional)) {
                array_push($errors, ...$this->check($additional, $property, $at.'.'.$name));
            }
        }

        return $errors;
    }

    /**
     * @param list<mixed> $required
     *
     * @return list<string>
     */
    private function checkRequired(array $required, mixed $value, string $at): array
    {
        if (!$value instanceof \stdClass) {
            return [];
        }

        $errors = [];
        foreach ($required as $name) {
            $name = $this->string($name);
            if (!property_exists($value, $name)) {
                $errors[] = $at.'.'.$name.': required';
            }
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<string>
     */
    private function checkItems(array $items, mixed $value, string $at): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $errors = [];
        foreach ($value as $index => $item) {
            array_push($errors, ...$this->check($items, $item, $at.'['.$index.']'));
        }

        return $errors;
    }

    private function countValid(mixed $schemas, mixed $value, string $at): int
    {
        $valid = 0;
        foreach ($this->list($schemas) as $schema) {
            if ([] === $this->check($this->map($schema), $value, $at)) {
                $valid++;
            }
        }

        return $valid;
    }

    /**
     * @return list<string>
     */
    private function checkAll(mixed $schemas, mixed $value, string $at): array
    {
        $errors = [];
        foreach ($this->list($schemas) as $schema) {
            array_push($errors, ...$this->check($this->map($schema), $value, $at));
        }

        return $errors;
    }

    /**
     * A decoded document value as an associative-array value, to compare
     * with enum and const members read from the schema.
     */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return array_map($this->plain(...), get_object_vars($value));
        }
        if (\is_array($value)) {
            return array_map($this->plain(...), $value);
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \LogicException('Expected a list in the schema.');
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('Expected a string in the schema.');
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function map(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \LogicException('Expected an object in the schema.');
        }

        return $value;
    }
}
