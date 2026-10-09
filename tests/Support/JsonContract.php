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

namespace PHPRegex\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The JSON the regex command prints, written down once: every object of
 * every document, by address, with its exact keys. The CLI tests check real
 * documents against it and the documentation test checks the reference page
 * against it, so the two cannot drift apart.
 *
 * An address is the document kind for its top level (lint, analyze, debug,
 * redos, transpile, error for the error envelope, baseline for a baseline
 * file), then ".key" for a nested object and "[]" for the elements of a list.
 * An object several documents embed has an address of its own (runtime,
 * validation, redos_analysis, benchmark) and is described once.
 */
final class JsonContract
{
    /**
     * Every key of every document: lower snake_case.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/';

    /**
     * The keys of each object. An issue, a result, a payload: every key
     * listed here is present, null when it does not apply, unless
     * OPTIONAL_KEYS says otherwise.
     */
    public const OBJECTS = [
        // Shared objects.
        'runtime' => ['version', 'jit', 'backtrack_limit', 'recursion_limit'],
        'validation' => ['is_valid', 'error', 'error_code', 'category', 'offset', 'caret_snippet', 'hint', 'complexity_score'],
        'redos_analysis' => [
            'severity', 'score', 'mode', 'confirmed', 'confidence', 'vulnerable_part', 'vulnerable_subpattern',
            'trigger', 'false_positive_risk', 'suggested_rewrite', 'recommendations', 'error', 'findings',
            'hotspots', 'confirmation', 'complexity', 'degree', 'proof', 'witness', 'replayed', 'abstractions',
            'pcre_version', 'analysis_version', 'search_cost',
        ],
        'redos_analysis.findings[]' => ['severity', 'message', 'pattern', 'trigger', 'suggested_rewrite', 'confidence', 'false_positive_risk'],
        'redos_analysis.hotspots[]' => ['start', 'end', 'severity', 'pattern', 'trigger'],
        'redos_analysis.witness' => ['prefix', 'pump', 'suffix'],
        'redos_analysis.search_cost' => ['degree', 'witness', 'replayed'],
        'redos_analysis.search_cost.witness' => ['prefix', 'run', 'breaker'],
        'redos_analysis.confirmation' => [
            'confirmed', 'samples', 'jit_setting', 'backtrack_limit', 'recursion_limit', 'iterations',
            'timeout_ms', 'timed_out', 'evidence', 'note', 'error',
        ],
        'redos_analysis.confirmation.samples[]' => ['input_length', 'duration_ms', 'input_preview', 'preg_error_code', 'preg_error'],
        'benchmark' => ['label', 'result', 'wall_ms', 'avg_ms', 'cpu_ms', 'mem_bytes', 'peak_bytes', 'err_msg', 'err_code', 'iterations'],

        // The error envelope.
        'error' => ['error', 'stage', 'validation'],

        // regex lint --format=json
        'lint' => ['target', 'stats', 'results'],
        'lint.target' => ['php', 'pcre', 'source', 'range'],
        'lint.target.range[]' => ['php', 'pcre'],
        'lint.stats' => ['errors', 'warnings', 'optimizations', 'redos_errors', 'infos', 'lint_errors', 'parser_fallbacks'],
        'lint.results[]' => ['file', 'line', 'column', 'file_offset', 'source', 'pattern', 'location', 'issues', 'optimizations'],
        'lint.results[].issues[]' => [
            'severity', 'file', 'line', 'column', 'file_offset', 'position', 'issue_id', 'message', 'hint',
            'tip', 'source', 'validation', 'analysis', 'target',
        ],
        'lint.results[].issues[].target' => ['php', 'pcre'],
        'lint.results[].optimizations[]' => ['file', 'line', 'column', 'file_offset', 'optimization', 'savings', 'source'],
        'lint.results[].optimizations[].optimization' => ['original', 'optimized', 'changes'],

        // regex analyze --format=json
        'analyze' => ['pattern', 'runtime', 'parse', 'validation', 'redos', 'explain'],
        'analyze.parse' => ['ok'],

        // regex debug --format=json
        'debug' => ['pattern', 'runtime', 'validation', 'analysis', 'input'],
        'debug.input' => ['value', 'source'],

        // regex redos --format=json
        'redos' => ['pattern', 'safe_pattern', 'runtime', 'input', 'settings', 'bench', 'summary'],
        'redos.input' => ['source', 'base_length', 'final_length', 'repeat', 'prefix', 'suffix', 'preview', 'value', 'note'],
        'redos.settings' => ['iterations', 'warmup'],
        'redos.bench' => ['vuln', 'safe'],
        'redos.summary' => ['result_parity', 'speedup', 'delta_ms'],

        // regex transpile --format=json, and the Symfony and Laravel commands
        'transpile' => ['target', 'source', 'pattern', 'flags', 'literal', 'constructor', 'warnings', 'notes'],

        // regex lint --generate-baseline
        'baseline' => ['version', 'issues'],
        'baseline.issues[]' => ['file', 'line', 'column', 'issue_id', 'message', 'severity', 'pattern', 'pattern_hash'],
    ];

    /**
     * The keys an object may leave out: the envelope's sibling keys, and
     * the second benchmark row, which only a run against --safe has.
     */
    public const OPTIONAL_KEYS = [
        'error' => ['validation'],
        'redos.bench' => ['safe'],
    ];

    /**
     * Where a document embeds a shared object.
     */
    public const EMBEDDED = [
        'analyze.runtime' => 'runtime',
        'debug.runtime' => 'runtime',
        'redos.runtime' => 'runtime',
        'analyze.validation' => 'validation',
        'debug.validation' => 'validation',
        'error.validation' => 'validation',
        'lint.results[].issues[].validation' => 'validation',
        'analyze.redos' => 'redos_analysis',
        'debug.analysis' => 'redos_analysis',
        'lint.results[].issues[].analysis' => 'redos_analysis',
        'redos.bench.vuln' => 'benchmark',
        'redos.bench.safe' => 'benchmark',
    ];

    /**
     * Walk a decoded document and check that every key of every object is
     * lower snake_case. List indices are not keys.
     */
    public static function assertSnakeCaseKeys(mixed $document, string $path = '$'): void
    {
        if (!\is_array($document)) {
            return;
        }

        $isList = array_is_list($document);
        foreach ($document as $key => $value) {
            if (!$isList) {
                Assert::assertMatchesRegularExpression(self::KEY_PATTERN, (string) $key, \sprintf('The key "%s" at %s is not snake_case.', $key, $path));
            }

            self::assertSnakeCaseKeys($value, $isList ? $path.'['.$key.']' : $path.'.'.$key);
        }
    }

    /**
     * Walk a decoded document from its address and check that every object
     * in it has exactly the keys written down for its address, and that no
     * object turns up where none is written down.
     */
    public static function assertShape(string $address, mixed $document, string $path = '$'): void
    {
        $address = self::EMBEDDED[$address] ?? $address;

        if (!\is_array($document)) {
            return;
        }

        // An empty JSON object and an empty list decode alike; neither has
        // a key to check.
        if (array_is_list($document)) {
            foreach ($document as $index => $element) {
                self::assertShape($address.'[]', $element, $path.'['.$index.']');
            }

            return;
        }

        Assert::assertArrayHasKey($address, self::OBJECTS, \sprintf('An object at %s (address "%s") that the contract does not describe.', $path, $address));

        $actual = array_map(strval(...), array_keys($document));
        $allowed = self::OBJECTS[$address];
        $required = array_values(array_diff($allowed, self::OPTIONAL_KEYS[$address] ?? []));

        sort($actual);
        sort($required);
        $missing = array_values(array_diff($required, $actual));
        $unexpected = array_values(array_diff($actual, $allowed));

        Assert::assertSame([], $missing, \sprintf('Keys missing at %s (address "%s"): %s', $path, $address, implode(', ', $missing)));
        Assert::assertSame([], $unexpected, \sprintf('Keys not in the contract at %s (address "%s"): %s', $path, $address, implode(', ', $unexpected)));

        foreach ($document as $key => $value) {
            self::assertShape($address.'.'.$key, $value, $path.'.'.$key);
        }
    }

    /**
     * A decoded value that must be an array, as one: for reading a document
     * a level deeper.
     *
     * @return array<mixed>
     */
    public static function asArray(mixed $value, string $message = ''): array
    {
        Assert::assertIsArray($value, $message);

        return $value;
    }

    /**
     * Check that stdout holds one JSON document and nothing else: it ends
     * with exactly one newline and decodes as a whole.
     *
     * @return array<mixed>
     */
    public static function decodeDocument(string $stdout): array
    {
        Assert::assertStringEndsWith("\n", $stdout, 'The JSON document does not end with a newline: '.$stdout);
        Assert::assertStringEndsNotWith("\n\n", $stdout, 'The JSON document ends with more than one newline: '.$stdout);

        try {
            $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Assert::fail('stdout is not one JSON document ('.$e->getMessage().'): '.$stdout);
        }

        Assert::assertIsArray($decoded, 'stdout is not a JSON object or list: '.$stdout);

        return $decoded;
    }
}
