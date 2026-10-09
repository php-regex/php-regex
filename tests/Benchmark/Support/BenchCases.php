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

namespace PHPRegex\Tests\Benchmark\Support;

/**
 * The inputs the benchmarks read: the pathological cases of data/, keyed by
 * "<group>/<slug>", and the realistic corpus, keyed by a hash of each
 * pattern. Neither key depends on the order the inputs are listed in, so a
 * comparison between two runs never pairs two different patterns.
 */
final class BenchCases
{
    public const DATA_DIR = __DIR__.'/../data';

    public const CORPUS = __DIR__.'/../../Fixtures/Corpus/lint-expectations.json';

    private const REQUIRED = ['pattern', 'origin', 'note'];

    private const ALLOWED = ['pattern', 'origin', 'note', 'invalid', 'expect'];

    /**
     * The outcomes a case of each group may declare, the values
     * Workloads::outcome() reports for the work the group measures. A group
     * listed here requires "expect"; any other group refuses it. A redos case
     * never declares "not_analyzed": it would be timing the error path.
     */
    private const OUTCOMES = [
        'redos' => ['proven', 'heuristic', 'budget_exceeded'],
        'automata' => ['complete', 'guard'],
    ];

    /**
     * Every case of the data folder, sorted by key.
     *
     * @throws \UnexpectedValueException when a case file is malformed
     *
     * @return array<string, BenchCase>
     */
    public static function all(?string $dataDir = null): array
    {
        $dataDir ??= self::DATA_DIR;
        $cases = [];

        foreach (self::entries($dataDir) as $group) {
            $groupDir = $dataDir.'/'.$group;
            if (!is_dir($groupDir)) {
                continue;
            }

            foreach (self::entries($groupDir) as $file) {
                if (!str_ends_with($file, '.php') || !is_file($groupDir.'/'.$file)) {
                    continue;
                }

                $case = self::load($groupDir.'/'.$file, $group, substr($file, 0, -4));
                $cases[$case->key] = $case;
            }
        }

        ksort($cases, \SORT_STRING);

        return $cases;
    }

    /**
     * The cases of one group, sorted by key; none for a group with no folder.
     *
     * @throws \UnexpectedValueException when a case file is malformed
     *
     * @return array<string, BenchCase>
     */
    public static function forGroup(string $group, ?string $dataDir = null): array
    {
        return array_filter(self::all($dataDir), static fn (BenchCase $case): bool => $case->group === $group);
    }

    /**
     * One case by its key, for a benchmark whose parameter carries only the
     * key (a pattern holding raw bytes stays out of the report).
     *
     * @throws \OutOfBoundsException when no case has that key
     */
    public static function get(string $key, ?string $dataDir = null): BenchCase
    {
        return self::all($dataDir)[$key] ?? throw new \OutOfBoundsException(\sprintf('No data case "%s".', $key));
    }

    /**
     * The parameter sets of a group's cases: one per case, named by its key.
     *
     * @return array<string, array{key: string}>
     */
    public static function params(string $group): array
    {
        $params = [];
        foreach (array_keys(self::forGroup($group)) as $key) {
            $params[$key] = ['key' => $key];
        }

        return $params;
    }

    /**
     * The parameter sets of the realistic corpus: one per pattern, named by
     * keyFor().
     *
     * @return array<string, array{key: string}>
     */
    public static function realisticParams(): array
    {
        $params = [];
        foreach (array_keys(self::realistic()) as $key) {
            $params[$key] = ['key' => $key];
        }

        return $params;
    }

    /**
     * The key of a pattern that has no file of its own: a prefix of its
     * hash, the same for the same bytes whatever list it comes from.
     */
    public static function keyFor(string $pattern): string
    {
        return 'p'.substr(hash('xxh128', $pattern), 0, 12);
    }

    /**
     * The realistic corpus, each distinct pattern once, keyed by keyFor().
     *
     * @throws \UnexpectedValueException when the corpus is not a JSON list of entries, or two patterns share a key
     *
     * @return array<string, string>
     */
    public static function realistic(?string $corpus = null): array
    {
        $corpus ??= self::CORPUS;

        try {
            $entries = json_decode((string) file_get_contents($corpus), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException(\sprintf('The corpus "%s" is not valid JSON: %s', $corpus, $e->getMessage()), 0, $e);
        }

        if (!\is_array($entries)) {
            throw new \UnexpectedValueException(\sprintf('The corpus "%s" does not hold a list of entries.', $corpus));
        }

        $patterns = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['pattern'] ?? null)) {
                continue;
            }

            $pattern = $entry['pattern'];
            $key = self::keyFor($pattern);
            if (isset($patterns[$key]) && $patterns[$key] !== $pattern) {
                throw new \UnexpectedValueException(\sprintf('Two corpus patterns share the key "%s": %s and %s', $key, $patterns[$key], $pattern));
            }

            $patterns[$key] = $pattern;
        }

        ksort($patterns, \SORT_STRING);

        return $patterns;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $entries = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $entry): bool => !str_starts_with($entry, '.'),
        ));
        sort($entries, \SORT_STRING);

        return $entries;
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function load(string $file, string $group, string $slug): BenchCase
    {
        $data = (static fn (string $path): mixed => require $path)($file);

        if (!\is_array($data)) {
            throw new \UnexpectedValueException(\sprintf('The data case "%s" must return an array, got %s.', $file, get_debug_type($data)));
        }

        $unknown = array_diff(array_map(strval(...), array_keys($data)), self::ALLOWED);
        if ([] !== $unknown) {
            throw new \UnexpectedValueException(\sprintf('The data case "%s" has unknown keys: %s.', $file, implode(', ', $unknown)));
        }

        foreach (self::REQUIRED as $field) {
            if (!\is_string($data[$field] ?? null)) {
                throw new \UnexpectedValueException(\sprintf('The data case "%s" needs a string "%s".', $file, $field));
            }
        }

        $invalid = $data['invalid'] ?? false;
        if (!\is_bool($invalid)) {
            throw new \UnexpectedValueException(\sprintf('The data case "%s" has a non-bool "invalid".', $file));
        }

        return new BenchCase($group, $slug, $data['pattern'], $data['origin'], $data['note'], $invalid, self::expect($file, $group, $data));
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException
     */
    private static function expect(string $file, string $group, array $data): ?string
    {
        $outcomes = self::OUTCOMES[$group] ?? null;

        if (null === $outcomes) {
            if (\array_key_exists('expect', $data)) {
                throw new \UnexpectedValueException(\sprintf('The data case "%s" declares "expect", which only a case of %s may.', $file, implode(' or ', array_keys(self::OUTCOMES))));
            }

            return null;
        }

        $expect = $data['expect'] ?? null;
        if (!\is_string($expect) || !\in_array($expect, $outcomes, true)) {
            throw new \UnexpectedValueException(\sprintf('The data case "%s" needs an "expect" among %s, got %s.', $file, implode(', ', $outcomes), \is_string($expect) ? '"'.$expect.'"' : get_debug_type($expect)));
        }

        return $expect;
    }
}
