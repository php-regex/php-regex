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

namespace PHPRegex\Tests\Unit\Bridge\Laravel;

use PHPRegex\Laravel\Facades\Regex as LaravelRegex;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The facade docblock is the whole static-analysis surface a Laravel consumer
 * gets: IDE completion, PHPStan and Psalm all read the @method lines, never
 * the toolkit class behind the accessor.
 *
 * The facade resolves the bound \PHPRegex\Toolkit\Regex singleton, so every
 * public method needs a line — the static ones (create, tokenize) and the
 * instance ones alike — and each line must agree with the reflected
 * signature. Names, arities, types and defaults are compared after
 * normalization (unions order-insensitive, class names resolved against the
 * file that spells them), never as raw text.
 */
final class FacadeParityTest extends TestCase
{
    #[Test]
    #[DataProvider('provideToolkitPublicMethods')]
    public function test_every_public_toolkit_method_has_a_facade_method_tag(string $method): void
    {
        $this->assertArrayHasKey(
            $method,
            self::facadeMethodTags(),
            sprintf(
                '%s() is public on Toolkit\Regex but has no @method line on the Laravel facade. The facade proxies'
                .' the bound singleton, so static and instance methods both need one — without it the method is an'
                .' unknown static call under plain PHPStan/Psalm and absent from IDE completion.',
                $method,
            ),
        );
    }

    #[Test]
    #[DataProvider('provideFacadeMethodTags')]
    public function test_facade_method_tag_matches_the_reflected_signature(string $method): void
    {
        $tag = self::facadeMethodTags()[$method];
        $reflected = self::reflectedSignatures()[$method];

        $this->assertSame(
            [],
            self::signatureMismatches($tag, $reflected),
            sprintf('The @method line for %s() on the Laravel facade disagrees with the reflection.', $method),
        );
    }

    #[Test]
    public function test_every_facade_method_tag_is_marked_static(): void
    {
        $notStatic = array_keys(array_filter(
            self::facadeMethodTags(),
            static fn (array $tag): bool => !$tag['static'],
        ));

        $this->assertSame(
            [],
            $notStatic,
            'Every @method line on the facade must carry the static marker: consumers call Regex::parse(), never'
            .' an instance they hold.',
        );
    }

    #[Test]
    public function test_every_facade_method_tag_names_a_public_toolkit_method(): void
    {
        $unknown = [];
        foreach (array_keys(self::facadeMethodTags()) as $name) {
            if (!isset(self::reflectedSignatures()[$name])) {
                $unknown[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'The facade accessor is \PHPRegex\Toolkit\Regex, so an @method line may only name one of its public'
            .' methods; anything else is a line about a call the facade cannot proxy.',
        );
    }

    public static function provideToolkitPublicMethods(): iterable
    {
        foreach (array_keys(self::reflectedSignatures()) as $method) {
            yield $method => [$method];
        }
    }

    public static function provideFacadeMethodTags(): iterable
    {
        foreach (array_keys(self::facadeMethodTags()) as $method) {
            yield $method => [$method];
        }
    }

    /**
     * @return array<string, array{static: bool, return: string, params: list<array{name: string, type: string, default: string|null}>}>
     */
    private static function facadeMethodTags(): array
    {
        $docComment = (string) (new \ReflectionClass(LaravelRegex::class))->getDocComment();
        [$useMap, $namespace] = self::fileImports(LaravelRegex::class);

        $tags = [];
        preg_match_all('/^\s*\*?\s*@method\s+(.+)$/m', $docComment, $lines, \PREG_SET_ORDER);

        foreach ($lines as $line) {
            $rest = trim($line[1]);
            $isStatic = false;
            if (preg_match('/^static\s+/', $rest)) {
                $isStatic = true;
                $rest = trim((string) preg_replace('/^static\s+/', '', $rest));
            }

            // The return type may be an array shape, whose braces hold spaces.
            if (!preg_match('/^((?:[^{}\s]|\{[^}]*\})+)\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)\s*$/', $rest, $parts)) {
                throw new \InvalidArgumentException(sprintf('Unparsable @method line: "@method %s".', $rest));
            }

            $params = [];
            $paramText = trim($parts[3]);
            if ('' !== $paramText) {
                foreach (self::splitTopLevel($paramText, ',') as $piece) {
                    $piece = trim($piece);
                    if (!preg_match('/^(.+?)\s+(\$\w+)(?:\s*=\s*(.+))?$/', $piece, $param)) {
                        throw new \InvalidArgumentException(sprintf('Unparsable @method parameter: "%s".', $piece));
                    }
                    $default = isset($param[3]) && '' !== trim($param[3])
                        ? self::normalizeDefault($param[3], $useMap, $namespace)
                        : null;
                    $params[] = [
                        'name' => substr($param[2], 1),
                        'type' => self::normalizeType($param[1], $useMap, $namespace),
                        'default' => $default,
                    ];
                }
            }

            $tags[$parts[2]] = [
                'static' => $isStatic,
                'return' => self::normalizeType($parts[1], $useMap, $namespace),
                'params' => $params,
            ];
        }

        return $tags;
    }

    /**
     * The reflected signatures, normalized the same way the facade lines are:
     * reflection already resolves class names to their fully spelled form, so
     * the imports of the Toolkit file never come into it.
     *
     * @return array<string, array{static: bool, return: string, params: list<array{name: string, type: string, default: string|null}>}>
     */
    private static function reflectedSignatures(): array
    {
        $signatures = [];
        $class = new \ReflectionClass(Regex::class);

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (Regex::class !== $method->getDeclaringClass()->getName()) {
                continue;
            }

            $params = [];
            foreach ($method->getParameters() as $parameter) {
                $params[] = [
                    'name' => $parameter->getName(),
                    'type' => self::normalizeType(null === $parameter->getType() ? '' : (string) $parameter->getType(), [], ''),
                    'default' => $parameter->isDefaultValueAvailable() ? self::reflectedDefault($parameter) : null,
                ];
            }

            $signatures[$method->name] = [
                'static' => $method->isStatic(),
                'params' => $params,
                'return' => self::expectedFacadeReturnType($method),
            ];
        }

        ksort($signatures);

        return $signatures;
    }

    /**
     * What the facade line should answer for: the reflected return type, with
     * self spelled out (it would name the facade on the docblock side) and a
     * bare array refined by the shape the method's own @return declares —
     * getCacheStats() carries its array{hits: int, misses: int} shape there.
     */
    private static function expectedFacadeReturnType(\ReflectionMethod $method): string
    {
        $declared = null === $method->getReturnType() ? '' : (string) $method->getReturnType();

        if ('self' === $declared || 'static' === $declared) {
            return Regex::class;
        }

        if ('array' === $declared
            && preg_match('/@return\s+(array\{[^}]*\})/', (string) $method->getDocComment(), $match)
        ) {
            return self::normalizeType($match[1], [], '');
        }

        return self::normalizeType($declared, [], '');
    }

    /**
     * @param array{static: bool, return: string, params: list<array{name: string, type: string, default: string|null}>} $tag
     * @param array{static: bool, return: string, params: list<array{name: string, type: string, default: string|null}>} $reflected
     *
     * @return list<string>
     */
    private static function signatureMismatches(array $tag, array $reflected): array
    {
        $mismatches = [];

        if ($tag['return'] !== $reflected['return']) {
            $mismatches[] = sprintf(
                'return type: the facade says %s, the reflection %s.',
                $tag['return'],
                $reflected['return'],
            );
        }

        if (\count($tag['params']) !== \count($reflected['params'])) {
            $mismatches[] = sprintf(
                'arity: the facade declares %d parameter(s), the reflection %d.',
                \count($tag['params']),
                \count($reflected['params']),
            );

            return $mismatches;
        }

        foreach ($reflected['params'] as $index => $expected) {
            $actual = $tag['params'][$index];
            $position = $index + 1;

            if ($actual['name'] !== $expected['name']) {
                $mismatches[] = sprintf(
                    'parameter #%d: the facade names it $%s, the reflection $%s (named arguments make the name public).',
                    $position,
                    $actual['name'],
                    $expected['name'],
                );
            }

            if ($actual['type'] !== $expected['type']) {
                $mismatches[] = sprintf(
                    'parameter #%d ($%s): the facade type is %s, the reflection %s.',
                    $position,
                    $expected['name'],
                    $actual['type'],
                    $expected['type'],
                );
            }

            if ($actual['default'] !== $expected['default']) {
                $mismatches[] = sprintf(
                    'default of $%s: the facade says %s, the reflection %s.',
                    $expected['name'],
                    $actual['default'] ?? '(no default)',
                    $expected['default'] ?? '(no default)',
                );
            }
        }

        return $mismatches;
    }

    /**
     * Unions compared as sets: order-insensitive members, each resolved to a
     * fully spelled name against the file that wrote it, so "OptimizerOptions|array"
     * equals "array|OptimizerOptions" while nothing else does.
     *
     * @param array<string, string> $useMap
     */
    private static function normalizeType(string $type, array $useMap, string $namespace): string
    {
        $type = trim($type);

        if ('' === $type) {
            return '';
        }

        $members = [];
        if (str_starts_with($type, '?')) {
            $members[] = 'null';
            $type = substr($type, 1);
        }

        foreach (self::splitTopLevel($type, '|') as $member) {
            $member = trim($member);
            if ('' === $member) {
                continue;
            }
            $members[] = self::resolveTypeName($member, $useMap, $namespace);
        }

        $members = array_values(array_unique($members));
        sort($members);

        return implode('|', $members);
    }

    /**
     * @param array<string, string> $useMap
     */
    private static function resolveTypeName(string $name, array $useMap, string $namespace): string
    {
        if (str_contains($name, '{')) {
            // An array shape: canonicalize whitespace only; both sides must spell it the same way.
            return trim((string) preg_replace('/\s+/', ' ', $name));
        }

        static $keywords = [
            'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
            'mixed', 'null', 'object', 'parent', 'self', 'static', 'string', 'true', 'void',
        ];

        if (\in_array($name, $keywords, true)) {
            // Left literal on purpose: self/static in the facade docblock name the
            // facade, not the toolkit — a line relying on them must not pass.
            return $name;
        }

        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (str_contains($name, '\\')) {
            return $name;
        }

        return $useMap[$name] ?? ('' === $namespace ? $name : $namespace.'\\'.$name);
    }

    /**
     * @param array<string, string> $useMap
     */
    private static function normalizeDefault(string $expression, array $useMap, string $namespace): string
    {
        $expression = trim($expression);

        if (preg_match('/^(\\\\?[A-Za-z_][\w\\\\]*)::(\w+)$/', $expression, $match)) {
            return '\\'.self::resolveTypeName($match[1], $useMap, $namespace).'::'.$match[2];
        }

        return 'NULL' === $expression ? 'null' : $expression;
    }

    private static function reflectedDefault(\ReflectionParameter $parameter): string
    {
        $value = $parameter->getDefaultValue();

        if (null === $value) {
            return 'null';
        }

        if ($value instanceof \UnitEnum) {
            return '\\'.$value::class.'::'.$value->name;
        }

        if (\is_array($value)) {
            if ([] === $value) {
                return '[]';
            }

            throw new \InvalidArgumentException('A non-empty array default has no docblock spelling to compare.');
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return var_export($value, true);
    }

    /**
     * Splits on the separator, ignoring the ones nested in brackets or quotes.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $text, string $separator): array
    {
        $pieces = [];
        $current = '';
        $depth = 0;
        $length = \strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ('\'' === $char || '"' === $char) {
                $quote = $char;
                $current .= $char;
                while (++$i < $length) {
                    $current .= $text[$i];
                    if ($quote === $text[$i]) {
                        break;
                    }
                }

                continue;
            }

            if ('(' === $char || '<' === $char || '{' === $char || '[' === $char) {
                $depth++;
            } elseif (')' === $char || '>' === $char || '}' === $char || ']' === $char) {
                $depth = max(0, $depth - 1);
            } elseif ($separator === $char && 0 === $depth) {
                $pieces[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $pieces[] = $current;

        return $pieces;
    }

    /**
     * The imports and namespace of a file, to resolve the short class names a
     * docblock of that file spells: a type only means what its file resolves
     * it to.
     *
     * @param class-string $className
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function fileImports(string $className): array
    {
        $reflection = new \ReflectionClass($className);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $match)) {
            $namespace = $match[1];
        }

        $useMap = [];
        if (preg_match_all('/^use\s+([^;]+);/m', $source, $statements, \PREG_SET_ORDER)) {
            foreach ($statements as $statement) {
                foreach (explode(',', $statement[1]) as $import) {
                    $import = trim($import);
                    if ('' === $import || preg_match('/^(function|const)\s+/', $import)) {
                        continue;
                    }
                    if (preg_match('/^([\w\\\\]+)(?:\s+as\s+(\w+))?$/', $import, $parts)) {
                        $pos = strrpos($parts[1], '\\');
                        $short = false === $pos ? $parts[1] : substr($parts[1], $pos + 1);
                        $useMap[$parts[2] ?? $short] = ltrim($parts[1], '\\');
                    }
                }
            }
        }

        return [$useMap, $namespace];
    }
}
