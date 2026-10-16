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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;

/**
 * Reads regex.dist.json, then regex.json, from the working directory.
 *
 * Each file is checked against LintConfigSchema, and every problem of both
 * files is reported at once, a name in "extraction.interop" that is no
 * preset included. A spec of "extraction.functions" the lint cannot read is
 * a warning, never a problem: it is left out of the result.
 * regex.json overrides regex.dist.json: objects merge key by key, anything
 * else (a list included) is replaced whole. The result keeps the shape of
 * the file.
 *
 * @phpstan-import-type LintConfig from LintConfigResult
 *
 * @internal
 */
final class LintConfigLoader
{
    public const FILES = ['regex.dist.json', 'regex.json'];

    private readonly LintConfigValidator $validator;

    public function __construct()
    {
        $this->validator = new LintConfigValidator();
    }

    /**
     * @param string|null $directory where the files are read; the working directory by default
     */
    public function load(?string $directory = null): LintConfigResult
    {
        $directory ??= getcwd();
        if (false === $directory) {
            return new LintConfigResult([], []);
        }

        $merged = new \stdClass();
        $files = [];
        $errors = [];
        $warnings = [];

        foreach (self::FILES as $name) {
            $path = $directory.'/'.$name;
            if (!file_exists($path)) {
                continue;
            }

            $document = $this->read($path, $errors);
            if (null === $document) {
                continue;
            }

            $document = self::withoutMalformedFunctions($document, $warnings);
            $problems = $this->validator->validate($document, $path);
            if ([] !== $problems) {
                array_push($errors, ...$problems);

                continue;
            }

            $merged = self::merge($merged, $document);
            $files[] = $path;
        }

        if ([] !== $errors) {
            return new LintConfigResult([], [], implode("\n", $errors), $warnings);
        }

        /** @var LintConfig $config every file read as the schema says */
        $config = self::toArray($merged);

        return new LintConfigResult($config, $files, warnings: $warnings);
    }

    /**
     * The document with the specs of "extraction.functions" the lint cannot
     * read left out, one warning per spec. Anything that is not a spec
     * stays, for the schema to report.
     *
     * @param list<string> $warnings
     */
    private static function withoutMalformedFunctions(\stdClass $document, array &$warnings): \stdClass
    {
        $extraction = $document->extraction ?? null;
        if (!$extraction instanceof \stdClass || !isset($extraction->functions) || !\is_array($extraction->functions)) {
            return $document;
        }

        $kept = [];
        foreach ($extraction->functions as $spec) {
            if (!\is_string($spec) || '' === trim($spec, " \t\n\r\0\x0B") || PatternFunctionRegistry::isSpec($spec)) {
                $kept[] = $spec;

                continue;
            }

            $warning = PatternFunctionRegistry::malformedSpecWarning($spec);
            if (!\in_array($warning, $warnings, true)) {
                $warnings[] = $warning;
            }
        }

        $extraction = clone $extraction;
        $extraction->functions = $kept;
        $document = clone $document;
        $document->extraction = $extraction;

        return $document;
    }

    /**
     * @param list<string> $errors
     */
    private function read(string $path, array &$errors): ?\stdClass
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            $errors[] = 'Failed to read config file: '.$path;

            return null;
        }

        try {
            $decoded = json_decode($contents, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = 'Invalid JSON in '.$path.': '.$e->getMessage();

            return null;
        }

        if (!$decoded instanceof \stdClass) {
            $errors[] = 'Config file must contain a JSON object: '.$path;

            return null;
        }

        return $decoded;
    }

    /**
     * An object merged into another key by key: an object value merges in
     * turn, anything else (a list included) replaces.
     */
    private static function merge(\stdClass $base, \stdClass $override): \stdClass
    {
        $merged = clone $base;
        foreach (get_object_vars($override) as $key => $value) {
            $key = (string) $key;
            $current = $merged->{$key} ?? null;
            $merged->{$key} = $current instanceof \stdClass && $value instanceof \stdClass ? self::merge($current, $value) : $value;
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(\stdClass $object): array
    {
        $array = [];
        foreach (get_object_vars($object) as $key => $value) {
            $array[(string) $key] = self::plain($value);
        }

        return $array;
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return self::toArray($value);
        }

        if (\is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }
}
