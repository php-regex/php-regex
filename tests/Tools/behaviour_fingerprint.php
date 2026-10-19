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

/*
 * Writes what the library says about each pattern of a list, one line per
 * pattern and target, so two versions of the code can be compared with
 * diff: a change meant to make the library faster must leave every line as
 * it was.
 *
 * Per pattern and per target (the running engine, each PHP version where a
 * verdict may change with the PCRE2 it bundles, and PHP 8.4 on each later
 * PCRE2 release a rule depends on): the tokens (type, text, offsets) or the
 * exception tokenizing throws, the serialized tree or the exception parsing
 * throws, the tolerant tree and its errors, and every field of the
 * validation result. An exception is its class, code, position and message.
 * Each part is written as a hash; --plain writes it as read instead.
 *
 * The patterns come from JSON lists (tests/Tools/harvest_patterns.php
 * writes them; an entry may be {"base64": "..."}), or from the corpus
 * fixture when "fixture" is named. --autoload loads another copy of the
 * library, to fingerprint the code of another revision with this script.
 *
 * --lint writes what the lint says instead, per pattern and target: the
 * issues of the rules on by default ("default"), of every rule ("all"), or
 * the issues and the optimizations "regex lint" reports by default, each
 * rewrite checked with the automata ("suggest"). --slice=I/N keeps every
 * Nth pattern from the Ith, to run one list in N processes.
 *
 * Usage:
 *   php tests/Tools/behaviour_fingerprint.php [--autoload=FILE] [--targets=runtime,php80100,...] [--lint=default|all|suggest] [--slice=I/N] [--plain] --out=FILE fixture|LIST.json...
 */

namespace PHPRegex\Tests\Tools;

$options = getopt('', ['autoload:', 'targets:', 'lint:', 'slice:', 'plain', 'out:'], $restIndex);
$autoload = $options['autoload'] ?? __DIR__.'/../../vendor/autoload.php';
require_once \is_string($autoload) ? $autoload : __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Cache\AstSerializer;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Token\Token;

const FIXTURE = __DIR__.'/../Fixtures/Corpus/lint-expectations.json';

/**
 * The targets a verdict may differ between: the running engine, each PHP
 * version boundary with the PCRE2 it bundles, and PHP 8.4 on the PCRE2
 * releases after 10.44 that a rule names.
 *
 * @return array<string, array{php_version?: int|string, pcre_version?: string}>
 */
function defaultTargets(): array
{
    $targets = ['runtime' => []];
    foreach ([70300, 70400, 80000, 80100, 80200, 80300, 80400, 80425, 80500, 80510] as $php) {
        $targets['php'.$php] = ['php_version' => $php];
    }

    foreach (['10.43', '10.45', '10.47', '10.48'] as $pcre) {
        $targets['php80400-pcre'.$pcre] = ['php_version' => 80400, 'pcre_version' => $pcre];
    }

    return $targets;
}

/**
 * @return list<string>
 */
function readPatterns(string $source): array
{
    $file = 'fixture' === $source ? FIXTURE : $source;
    $entries = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
    if (!\is_array($entries)) {
        throw new \UnexpectedValueException(\sprintf('%s does not hold a JSON list.', $file));
    }

    $patterns = [];
    foreach ($entries as $entry) {
        if (\is_string($entry)) {
            $patterns[] = $entry;
        } elseif (\is_array($entry) && \is_string($entry['pattern'] ?? null)) {
            $patterns[] = $entry['pattern'];
        } elseif (\is_array($entry) && \is_string($entry['base64'] ?? null)) {
            $patterns[] = (string) base64_decode($entry['base64'], true);
        }
    }

    return $patterns;
}

function describe(\Throwable $e): string
{
    $code = $e instanceof RegexException ? $e->errorCode->name : '';
    $position = $e instanceof RegexException ? (string) $e->position : '';

    return 'E:'.$e::class.'|'.$code.'|'.$position.'|'.$e->getMessage();
}

/**
 * @return array{string, string, string, string}
 */
function fingerprint(RegexParser $parser, string $pattern): array
{
    try {
        $tokens = implode("\x1f", array_map(
            static fn (Token $t): string => $t->type->value.':'.$t->value.':'.$t->position.':'.$t->end(),
            $parser->tokenize($pattern),
        ));
    } catch (\Throwable $e) {
        $tokens = describe($e);
    }

    try {
        $tree = AstSerializer::serialize($parser->parse($pattern));
    } catch (\Throwable $e) {
        $tree = describe($e);
    }

    try {
        $tolerant = $parser->parseTolerant($pattern);
        $tolerantTree = AstSerializer::serialize($tolerant->ast).'|'.implode(';', array_map(describe(...), $tolerant->errors));
    } catch (\Throwable $e) {
        $tolerantTree = describe($e);
    }

    try {
        $result = $parser->validate($pattern);
        $validation = json_encode([
            $result->isValid,
            $result->error,
            $result->complexityScore,
            $result->category?->name,
            $result->offset,
            $result->caretSnippet,
            $result->hint,
            $result->errorCode?->name,
        ], \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        $validation = describe($e);
    }

    return [$tokens, $tree, $tolerantTree, $validation];
}

/**
 * What the lint reports for one pattern: the issues, and in "suggest" mode
 * the optimizations, serialized whole.
 *
 * @return array{string}
 */
function lintFingerprint(AnalysisService $service, string $mode, string $pattern): array
{
    $occurrence = new PatternOccurrence($pattern, 'pattern.php', 1, 'preg_match()');

    try {
        if ('suggest' !== $mode) {
            return [serialize($service->lint([$occurrence]))];
        }

        [$issues, $analyzed] = $service->lintForSuggestions([$occurrence]);

        return [serialize([$issues, $service->suggestOptimizations([$occurrence], 1, new OptimizerOptions(verifyWithAutomata: true), 1, null, $analyzed)])];
    } catch (\Throwable $e) {
        return [describe($e)];
    }
}

/**
 * Every rule of the registry turned on, by id.
 *
 * @return array<string, bool>
 */
function everyRule(): array
{
    $rules = [];
    foreach ((new LintRuleRegistry())->all() as $rule) {
        foreach ($rule->getRuleIds() as $id) {
            $rules[$id] = true;
        }
    }

    return $rules;
}

$out = $options['out'] ?? null;
$sources = \array_slice($argv, $restIndex);
if (!\is_string($out) || [] === $sources) {
    fwrite(\STDERR, "Usage: php tests/Tools/behaviour_fingerprint.php [--autoload=FILE] [--targets=runtime,php80100,...] [--plain] --out=FILE fixture|LIST.json...\n");

    exit(1);
}

$targets = defaultTargets();
if (isset($options['targets']) && \is_string($options['targets'])) {
    $targets = array_intersect_key($targets, array_flip(explode(',', $options['targets'])));
}

$plain = isset($options['plain']);
$lint = isset($options['lint']) && \is_string($options['lint']) ? $options['lint'] : null;
[$sliceIndex, $sliceCount] = isset($options['slice']) && \is_string($options['slice']) ? array_map(intval(...), explode('/', $options['slice'], 2)) + [1 => 1] : [0, 1];
$handle = fopen($out, 'w');
if (false === $handle) {
    fwrite(\STDERR, \sprintf("Cannot write %s\n", $out));

    exit(1);
}

foreach ($sources as $source) {
    $patterns = readPatterns($source);
    foreach ($targets as $name => $targetOptions) {
        $parser = RegexParser::create(['cache' => null] + $targetOptions);
        $service = null === $lint ? null : new AnalysisService($parser, lintRules: 'all' === $lint ? everyRule() : []);
        foreach ($patterns as $index => $pattern) {
            if ($index % max(1, $sliceCount) !== $sliceIndex) {
                continue;
            }

            $parts = null === $service || null === $lint ? fingerprint($parser, $pattern) : lintFingerprint($service, $lint, $pattern);
            fwrite($handle, basename($source)."\t".$name."\t".$index."\t".implode("\t", $plain ? array_map(addcslashes(...), $parts, array_fill(0, \count($parts), "\0..\37\\")) : array_map(md5(...), $parts))."\n");
        }
    }
}

fclose($handle);
