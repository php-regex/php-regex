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
 * The playground's driver: the PHP that runs inside the browser's engine,
 * between the library's public API and the page.
 *
 * It is included once per engine, then called once per request:
 * phpregex_playground_run() reads a JSON request from a file and returns one
 * JSON document between two record separators (0x1E), so the caller can tell
 * the answer from anything else the engine prints. Four requests exist:
 *
 *   analyze   parse (tolerant), explain, validate, dump the tree, match
 *   match     the engine alone: its compile message and preg_match_all()
 *   lint      the lint findings of a pattern that parses strictly
 *   describe  one line about the node at a pre-order index of the tree
 *
 * The library counts bytes of the pattern body; a browser counts UTF-16
 * units of the whole delimited string. Every offset the page needs is
 * therefore returned converted or with the table that converts it, and the
 * page only looks up.
 *
 * The match request runs on PHP 8.0 too (the oldest engine the page offers,
 * where the library itself does not run), so this file keeps to PHP 8.0
 * syntax. It is also run by the local test suite, under the local PHP,
 * against the library in the tree and against its bundled copy.
 */

use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Toolkit\Regex;

// Declared only once per process: a second include is a no-op. The functions
// below are declared inside this block on purpose, so they are not hoisted
// ahead of it.
if (!\function_exists('phpregex_playground_run')) {
if (!\defined('PHPREGEX_PLAYGROUND_PROTOCOL')) {
    \define('PHPREGEX_PLAYGROUND_PROTOCOL', 1);
}
if (!\defined('PHPREGEX_PLAYGROUND_PATTERN_CAP')) {
    \define('PHPREGEX_PLAYGROUND_PATTERN_CAP', 2000);
}
if (!\defined('PHPREGEX_PLAYGROUND_SUBJECT_CAP')) {
    \define('PHPREGEX_PLAYGROUND_SUBJECT_CAP', 10000);
}
if (!\defined('PHPREGEX_PLAYGROUND_SETS_CAP')) {
    \define('PHPREGEX_PLAYGROUND_SETS_CAP', 500);
}

// The library's sources: the "src" directory beside this file (the layout
// of the bundle in the engine's filesystem), or the one the caller names.
spl_autoload_register(static function (string $class): void {
    if (0 !== strncmp($class, 'PHPRegex\\', 9)) {
        return;
    }
    $root = \defined('PHPREGEX_PLAYGROUND_ROOT') ? (string) PHPREGEX_PLAYGROUND_ROOT : __DIR__.'/src';
    $file = $root.'/'.strtr(substr($class, 9), '\\', '/').'.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Answers the request written in $inputFile, as one enveloped JSON document.
 */
function phpregex_playground_run(string $inputFile): string
{
    try {
        $input = json_decode((string) @file_get_contents($inputFile), true);
        if (!\is_array($input)) {
            throw new \InvalidArgumentException('The request is not a JSON object.');
        }
        $payload = phpregex_playground_answer($input);
    } catch (\Throwable $e) {
        $payload = [
            'protocol' => PHPREGEX_PLAYGROUND_PROTOCOL,
            'fatal' => $e instanceof \InvalidArgumentException ? $e->getMessage() : phpregex_playground_short_class($e).': '.$e->getMessage(),
        ];
    }

    // The tree nests one level per group: a pattern under the cap can go
    // past json_encode's default depth of 512, never past this one.
    $json = json_encode($payload, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE, 8192);
    if (!\is_string($json)) {
        $json = json_encode(['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'fatal' => 'The answer could not be encoded: '.json_last_error_msg()]);
    }

    return "\x1e".$json."\x1e";
}

/**
 * @param array<mixed> $input
 *
 * @return array<string, mixed>
 */
function phpregex_playground_answer(array $input): array
{
    $op = \is_string($input['op'] ?? null) ? $input['op'] : '';

    switch ($op) {
        case 'analyze':
            return phpregex_playground_analyze($input);
        case 'match':
            return phpregex_playground_match_answer($input);
        case 'lint':
            return phpregex_playground_lint($input);
        case 'describe':
            return phpregex_playground_describe($input);
    }

    throw new \InvalidArgumentException(\sprintf('Unknown op "%s".', $op));
}

/**
 * @param array<mixed> $input
 *
 * @return array<string, mixed>
 */
function phpregex_playground_analyze(array $input): array
{
    $started = hrtime(true);
    $regex = phpregex_playground_string($input, 'regex');
    [$subject, $truncated] = phpregex_playground_subject($input);
    $target = phpregex_playground_target($input);
    $library = phpregex_playground_library($target);

    $errors = [];
    $ast = null;
    $explain = null;
    $delimited = null;
    $groupNames = [];

    $parsed = $library->parseTolerant($regex);
    foreach ($parsed->errors as $error) {
        $errors[] = phpregex_playground_error($error, 'parse');
    }

    if ([] === $errors) {
        $ast = $parsed->ast->accept(new JsonDumper());
        $groupNames = phpregex_playground_group_names($ast);
        $explain = $parsed->ast->accept(new TextExplainer($library->target()));
        $validation = $library->validate($regex);
        if (!$validation->isValid) {
            $errors[] = [
                'source' => 'validate',
                'class' => null,
                'message' => (string) $validation->error,
                'position' => $validation->offset,
                'code' => null !== $validation->errorCode ? $validation->errorCode->value : null,
                'hint' => $validation->hint,
            ];
        }
    }

    try {
        $parts = DelimitedPattern::fromDelimited($regex, $library->target());
        $bodyStart = phpregex_playground_body_start($regex);
        $delimited = [
            'delimiter' => $parts->delimiter,
            'flags' => $parts->flags,
            'bodyStart' => $bodyStart,
            'bodyEnd' => $bodyStart + \strlen($parts->pattern),
        ];
    } catch (RegexException $e) {
        $delimited = null;
    }

    $payload = [
        'protocol' => PHPREGEX_PLAYGROUND_PROTOCOL,
        'engine' => phpregex_playground_engine(),
        'library' => ['version' => Regex::VERSION, 'jsonDumper' => JsonDumper::VERSION],
        'target' => $target,
        'input' => ['regexBytes' => \strlen($regex), 'subjectBytes' => \strlen($subject), 'truncated' => $truncated],
        'delimited' => $delimited,
        'unitOf' => phpregex_playground_unit_of(substr($regex, 0, PHPREGEX_PLAYGROUND_PATTERN_CAP)),
        'ast' => $ast,
        'groupNames' => (object) $groupNames,
        'explain' => $explain,
        'errors' => $errors,
        'engineError' => null,
        'matches' => null,
        'timing' => ['analyzeMs' => 0.0, 'matchMs' => null],
    ];
    $payload['timing']['analyzeMs'] = round((hrtime(true) - $started) / 1e6, 2);

    // A pattern past the cap is the library's refusal; the engine is not asked.
    if (false !== ($input['match'] ?? true) && \strlen($regex) <= PHPREGEX_PLAYGROUND_PATTERN_CAP) {
        $matched = phpregex_playground_match($regex, $subject);
        $payload['engineError'] = $matched['engineError'];
        $payload['matches'] = $matched['matches'];
        $payload['timing']['matchMs'] = $matched['ms'];
    }

    return $payload;
}

/**
 * The engine alone: no class of the library is touched, so this answer is
 * available on a PHP the library does not run on.
 *
 * @param array<mixed> $input
 *
 * @return array<string, mixed>
 */
function phpregex_playground_match_answer(array $input): array
{
    $regex = phpregex_playground_string($input, 'regex');
    [$subject, $truncated] = phpregex_playground_subject($input);
    $matched = \strlen($regex) <= PHPREGEX_PLAYGROUND_PATTERN_CAP
        ? phpregex_playground_match($regex, $subject)
        : ['engineError' => null, 'matches' => null, 'ms' => 0.0];

    return [
        'protocol' => PHPREGEX_PLAYGROUND_PROTOCOL,
        'engine' => phpregex_playground_engine(),
        'input' => ['regexBytes' => \strlen($regex), 'subjectBytes' => \strlen($subject), 'truncated' => $truncated],
        'engineError' => $matched['engineError'],
        'matches' => $matched['matches'],
        'timing' => ['matchMs' => $matched['ms']],
    ];
}

/**
 * @param array<mixed> $input
 *
 * @return array<string, mixed>
 */
function phpregex_playground_lint(array $input): array
{
    $started = hrtime(true);
    $regex = phpregex_playground_string($input, 'regex');
    $library = phpregex_playground_library(phpregex_playground_target($input));

    $findings = null;
    try {
        $findings = [];
        foreach ($library->lint($regex) as $violation) {
            $findings[] = [
                'id' => $violation->id,
                'message' => $violation->message,
                'offset' => $violation->offset,
                'end' => $violation->end,
                'severity' => $violation->severity->value,
                'hint' => $violation->hint,
            ];
        }
    } catch (RegexException $e) {
        // The linter needs a strict parse: an invalid pattern has no findings.
        $findings = null;
    }

    return [
        'protocol' => PHPREGEX_PLAYGROUND_PROTOCOL,
        'lint' => $findings,
        'timing' => ['lintMs' => round((hrtime(true) - $started) / 1e6, 2)],
    ];
}

/**
 * One line about the node at a pre-order index of the tree, with the span
 * the page checks against its own walk of the dumped document.
 *
 * @param array<mixed> $input
 *
 * @return array<string, mixed>
 */
function phpregex_playground_describe(array $input): array
{
    $regex = phpregex_playground_string($input, 'regex');
    $index = \is_int($input['index'] ?? null) ? $input['index'] : -1;
    $library = phpregex_playground_library(phpregex_playground_target($input));

    $answer = ['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'index' => $index, 'start' => null, 'end' => null, 'text' => null];
    if ($index < 0) {
        return $answer;
    }

    $parsed = $library->parseTolerant($regex);
    if ([] !== $parsed->errors) {
        return $answer;
    }

    $node = phpregex_playground_node_at($parsed->ast, $index);
    if (null === $node) {
        return $answer;
    }

    $text = $node->accept(new TextExplainer($library->target()));
    $newline = strpos($text, "\n");
    $answer['start'] = $node->getStartPosition();
    $answer['end'] = $node->getEndPosition();
    $answer['text'] = trim(false === $newline ? $text : substr($text, 0, $newline));

    return $answer;
}

/**
 * The node at a pre-order index: the root is 0, then each node's children
 * in pattern order, the order the dumped document lists them in.
 */
function phpregex_playground_node_at(NodeInterface $root, int $index): ?NodeInterface
{
    $stack = [$root];
    $seen = 0;
    while ([] !== $stack) {
        $node = array_pop($stack);
        if ($seen === $index) {
            return $node;
        }
        ++$seen;
        $children = $node->getChildren();
        // The dumped document lists a class's members directly, not the
        // alternation node that holds them: walk the way it dumps.
        if ($node instanceof CharClassNode && $node->expression instanceof AlternationNode) {
            $children = $node->expression->alternatives;
        }
        for ($i = \count($children) - 1; $i >= 0; --$i) {
            $stack[] = $children[$i];
        }
    }

    return null;
}

/**
 * The names of the capturing groups, by number, read from the dumped tree:
 * a name used twice under (?J) keeps both numbers, where the engine's match
 * array can carry it only once.
 *
 * @param array<string, mixed> $node
 *
 * @return array<string, string>
 */
function phpregex_playground_group_names(array $node): array
{
    $names = [];
    if (('group' === ($node['type'] ?? null)) && \is_string($node['name'] ?? null) && \is_int($node['number'] ?? null)) {
        $names[(string) $node['number']] = $node['name'];
    }
    foreach ($node as $value) {
        if (!\is_array($value)) {
            continue;
        }
        if (isset($value['type'])) {
            $names += phpregex_playground_group_names($value);
            continue;
        }
        foreach ($value as $item) {
            if (\is_array($item) && isset($item['type'])) {
                $names += phpregex_playground_group_names($item);
            }
        }
    }

    return $names;
}

/**
 * The engine's own answer: its compile message when the pattern does not
 * compile, otherwise every match with its groups, offsets in both counts.
 *
 * @return array{engineError: ?string, matches: ?array<string, mixed>, ms: float}
 */
function phpregex_playground_match(string $regex, string $subject): array
{
    $started = hrtime(true);
    $warning = null;
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning ??= $message;

        return true;
    });
    try {
        $count = preg_match_all($regex, $subject, $sets, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        // Read before anything else calls preg_*: the next call resets it.
        $error = preg_last_error_msg();
    } finally {
        restore_error_handler();
    }

    if (null !== $warning) {
        return ['engineError' => phpregex_playground_engine_message($warning), 'matches' => null, 'ms' => round((hrtime(true) - $started) / 1e6, 2)];
    }

    // A run the engine gave up on (a limit hit) has no sets worth showing:
    // whatever was collected before the limit would read as the answer.
    if (false === $count) {
        $sets = [];
    }
    $unitOf = phpregex_playground_unit_of($subject);
    $names = [];
    $shown = [];
    foreach ($sets as $set) {
        if ([] === $names) {
            $number = -1;
            foreach (array_keys($set) as $key) {
                if (\is_string($key)) {
                    $names[(string) ($number + 1)] = $key;
                } else {
                    $number = $key;
                }
            }
        }
        if (\count($shown) >= PHPREGEX_PLAYGROUND_SETS_CAP) {
            continue;
        }
        $sparse = [];
        foreach ($set as $key => [$text, $offset]) {
            if (\is_string($key) || null === $text || -1 === $offset) {
                continue;
            }
            $sparse[(string) $key] = [$text, $offset, $unitOf[$offset]];
        }
        $shown[] = (object) $sparse;
    }

    return [
        'engineError' => null,
        'matches' => [
            'count' => false === $count ? 0 : $count,
            'error' => $error,
            'shown' => \count($shown),
            'cap' => PHPREGEX_PLAYGROUND_SETS_CAP,
            'names' => (object) $names,
            'sets' => $shown,
        ],
        'ms' => round((hrtime(true) - $started) / 1e6, 2),
    ];
}

/**
 * PCRE2's text without the frame PHP puts around it: "preg_match_all(): "
 * on a server, "PHP Request Startup: " in the browser engine.
 */
function phpregex_playground_engine_message(string $warning): string
{
    if (1 === preg_match('/^(?:\w+\(\): |PHP Request Startup: )(.*)$/s', $warning, $m)) {
        return $m[1];
    }

    return $warning;
}

/**
 * The bytes before the pattern body: the leading whitespace PHP skips
 * (space, tab, newline, vertical tab, form feed, carriage return; not NUL)
 * and the opening delimiter.
 */
function phpregex_playground_body_start(string $regex): int
{
    return \strlen($regex) - \strlen(ltrim($regex, " \t\n\x0B\f\r")) + 1;
}

/**
 * The UTF-16 index of every byte offset of $text, from 0 to its length:
 * one unit per code point below U+10000, two above, one per byte that is
 * not UTF-8 (the replacement character the page shows instead).
 *
 * @return list<int>
 */
function phpregex_playground_unit_of(string $text): array
{
    $table = [];
    $units = 0;
    $length = \strlen($text);
    $i = 0;
    while ($i < $length) {
        $byte = \ord($text[$i]);
        $width = 1;
        $unit = 1;
        if ($byte >= 0xF0 && $byte <= 0xF4) {
            $width = 4;
            $unit = 2;
        } elseif ($byte >= 0xE0) {
            $width = 3;
        } elseif ($byte >= 0xC2 && $byte <= 0xDF) {
            $width = 2;
        }
        if ($i + $width > $length || 1 !== preg_match('/^.$/su', substr($text, $i, $width))) {
            $width = 1;
            $unit = 1;
        }
        for ($k = 0; $k < $width; ++$k) {
            $table[] = $units;
        }
        $units += $unit;
        $i += $width;
    }
    $table[] = $units;

    return $table;
}

/**
 * @return array{php: string, pcre: string, jit: bool, backtrackLimit: int}
 */
function phpregex_playground_engine(): array
{
    return [
        'php' => \PHP_VERSION,
        'pcre' => explode(' ', \PCRE_VERSION)[0],
        'jit' => '1' === (string) ini_get('pcre.jit'),
        'backtrackLimit' => (int) ini_get('pcre.backtrack_limit'),
    ];
}

/**
 * The engine the library judges for: the one named by the request (the
 * engine that matches, when another one analyses), else this one.
 *
 * @param array<mixed> $input
 *
 * @return array{php: string, pcre: string}
 */
function phpregex_playground_target(array $input): array
{
    $target = $input['target'] ?? null;
    if (\is_array($target) && \is_string($target['php'] ?? null) && \is_string($target['pcre'] ?? null)) {
        return ['php' => $target['php'], 'pcre' => $target['pcre']];
    }

    return ['php' => \PHP_VERSION, 'pcre' => explode(' ', \PCRE_VERSION)[0]];
}

/**
 * @param array{php: string, pcre: string} $target
 */
function phpregex_playground_library(array $target): Regex
{
    return Regex::create([
        'php_version' => $target['php'],
        'pcre_version' => $target['pcre'],
        'max_pattern_length' => PHPREGEX_PLAYGROUND_PATTERN_CAP,
    ]);
}

/**
 * @return array<string, mixed>
 */
function phpregex_playground_error(RegexException $error, string $source): array
{
    return [
        'source' => $source,
        'class' => phpregex_playground_short_class($error),
        'message' => $error->getMessage(),
        'position' => $error->position,
        'code' => $error->errorCode->value,
        'hint' => $error instanceof SemanticErrorException ? $error->hint : null,
    ];
}

function phpregex_playground_short_class(object $object): string
{
    $class = \get_class($object);
    $slash = strrpos($class, '\\');

    return false === $slash ? $class : substr($class, $slash + 1);
}

/**
 * @param array<mixed> $input
 */
function phpregex_playground_string(array $input, string $key): string
{
    $value = $input[$key] ?? '';

    return \is_string($value) ? $value : '';
}

/**
 * The subject, cut at the cap on a character boundary.
 *
 * @param array<mixed> $input
 *
 * @return array{string, bool}
 */
function phpregex_playground_subject(array $input): array
{
    $subject = phpregex_playground_string($input, 'subject');
    if (\strlen($subject) <= PHPREGEX_PLAYGROUND_SUBJECT_CAP) {
        return [$subject, false];
    }

    $cut = PHPREGEX_PLAYGROUND_SUBJECT_CAP;
    while ($cut > 0 && (\ord($subject[$cut]) & 0xC0) === 0x80) {
        --$cut;
    }

    return [substr($subject, 0, $cut), true];
}
}
