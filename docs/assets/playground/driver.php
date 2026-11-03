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
 * the answer from anything else the engine prints. Ten requests exist:
 *
 *   analyze   parse (tolerant), explain, validate, dump the tree, match
 *   match     the engine alone: its compile message and preg_match_all()
 *   lint      the lint findings of a pattern that parses strictly
 *   describe  one line about the node at a pre-order index of the tree
 *   format    the pattern laid out over lines, or compacted to one
 *   redos     the ReDoS verdict, its witness and the attack string
 *   transpile the pattern in JavaScript, HTML or Python, losses included
 *   samples   subjects the engine matches, and some it does not
 *   check     the engine alone: preg_match() without JIT on each subject
 *   export    a regex-phpunit test asserting what the engine answered
 *
 * The analysis also answers the pattern's tokens — one byte span per piece
 * the parser reads, in the highlighting visitor's vocabulary — because the
 * page colors the pattern field, and a colored field must align with what
 * the visitor typed: spans as data, never rebuilt HTML.
 *
 * The library counts bytes of the pattern body; a browser counts UTF-16
 * units of the whole delimited string. Every offset the page needs is
 * therefore returned converted or with the table that converts it, and the
 * page only looks up.
 *
 * The match and check requests run on PHP 8.0 too (the oldest engine the
 * page offers, where the library itself does not run), so this file keeps
 * to PHP 8.0 syntax. It is also run by the local test suite, under the local PHP,
 * against the library in the tree and against its bundled copy.
 */

use PHPRegex\Explain\TextExplainer;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\TranspileOptions;

// Declared only once per process: a second include is a no-op. The functions
// below are declared inside this block on purpose, so they are not hoisted
// ahead of it.
if (!\function_exists('phpregex_playground_run')) {
    if (!\defined('PHPREGEX_PLAYGROUND_PROTOCOL')) {
        \define('PHPREGEX_PLAYGROUND_PROTOCOL', 2);
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
    if (!\defined('PHPREGEX_PLAYGROUND_SUBJECTS_CAP')) {
        \define('PHPREGEX_PLAYGROUND_SUBJECTS_CAP', 50);
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
        // A notice or a deprecation the library raises on this engine (a
        // 32-bit one reads some constants as floats) would be printed into
        // the output and break the envelope: it is collected and answered
        // instead, so the page can say it and the answer still arrives.
        $notices = [];
        set_error_handler(static function (int $severity, string $message) use (&$notices): bool {
            // What "@" silences (the driver's own read of the request) is no
            // notice of the engine's: the mask says so.
            if (0 === (error_reporting() & $severity)) {
                return true;
            }
            if (!\in_array($message, $notices, true)) {
                $notices[] = $message;
            }

            return true;
        }, \E_NOTICE | \E_USER_NOTICE | \E_DEPRECATED | \E_USER_DEPRECATED | \E_WARNING | \E_USER_WARNING);

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
        } finally {
            restore_error_handler();
        }
        $payload['notices'] = $notices;

        // The tree nests one level per group: a pattern under the cap can go
        // past json_encode's default depth of 512, never past this one. An
        // object of the answer that cannot be written out is a fatal answer,
        // not a blank one.
        try {
            // A timing of exactly 16.0 ms (a Windows clock tick) stays a float
            // on the page's side.
            $json = json_encode($payload, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION, 8192);
        } catch (\Throwable $e) {
            $json = false;
            $payload = ['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'fatal' => 'The answer could not be written: '.phpregex_playground_short_class($e).': '.$e->getMessage()];
            $json = json_encode($payload);
        }
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
            case 'format':
                return phpregex_playground_format($input);
            case 'redos':
                return phpregex_playground_redos($input);
            case 'transpile':
                return phpregex_playground_transpile($input);
            case 'samples':
                return phpregex_playground_samples($input);
            case 'check':
                return phpregex_playground_check($input);
            case 'export':
                return phpregex_playground_export($input);
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
        $tokens = null;

        $parsed = $library->parseTolerant($regex);
        foreach ($parsed->errors as $error) {
            $errors[] = phpregex_playground_error($error, 'parse');
        }

        if ([] === $errors) {
            $ast = $parsed->ast->accept(new JsonDumper());
            $groupNames = phpregex_playground_group_names($ast);
            $tokens = phpregex_playground_tokens($ast, $regex);
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
            'tokens' => $tokens,
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
            $seen++;
            $children = $node->getChildren();
            // The dumped document lists a class's members directly, not the
            // alternation node that holds them: walk the way it dumps.
            if ($node instanceof CharClassNode && $node->expression instanceof AlternationNode) {
                $children = $node->expression->alternatives;
            }
            for ($i = \count($children) - 1; $i >= 0; $i--) {
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
     * The pattern's tokens: one span per piece the parser reads, read from the
     * dumped tree the way the page draws pieces with — a node's children carry
     * their own class, the glue between them the node's. Answered as data, for
     * the page colors the field itself and must align with what the visitor
     * typed.
     *
     * @param array<string, mixed> $node the dumped tree
     *
     * @return list<array{int, int, string}> [start, end, class], body bytes, end exclusive
     */
    function phpregex_playground_tokens(array $node, string $regex): array
    {
        $tokens = [];
        phpregex_playground_token_walk($node, substr($regex, phpregex_playground_body_start($regex)), $tokens);

        return $tokens;
    }

    /**
     * @param array<string, mixed>          $node
     * @param list<array{int, int, string}> $tokens
     */
    function phpregex_playground_token_walk(array $node, string $body, array &$tokens): void
    {
        $class = phpregex_playground_token_class($node);
        $at = (int) $node['start'];
        foreach (phpregex_playground_children($node) as $child) {
            $childStart = (int) $child['start'];
            if ($childStart > $at) {
                phpregex_playground_token_span($at, $childStart, $class, $node, $body, $tokens);
            }
            phpregex_playground_token_walk($child, $body, $tokens);
            $at = max($at, (int) $child['end']);
        }
        if ((int) $node['end'] > $at) {
            phpregex_playground_token_span($at, (int) $node['end'], $class, $node, $body, $tokens);
        }
    }

    /**
     * The span [$from, $to) as one token, or — inside a group's glue — a name
     * or an inline setting's flags told apart from the punctuation around them.
     * The name is what follows its opener, "(?<", "(?'" or "(?P<", in the glue
     * that opens the group: the glue around it may spell the same letters
     * ("(?P<P>", "\Q", "\E"). The flags are the only letters of theirs.
     *
     * @param array<string, mixed>          $node
     * @param list<array{int, int, string}> $tokens
     */
    function phpregex_playground_token_span(int $from, int $to, string $class, array $node, string $body, array &$tokens): void
    {
        $cuts = [];
        if ('group' === $node['type']) {
            $glue = substr($body, $from, $to - $from);
            $name = $node['name'] ?? null;
            if (\is_string($name) && '' !== $name && $from === (int) $node['start']
                && 1 === preg_match('/^\(\?(?:P?<|\')/', $glue, $opener)
                && substr($glue, \strlen($opener[0]), \strlen($name)) === $name) {
                $cuts[] = [$from + \strlen($opener[0]), $from + \strlen($opener[0]) + \strlen($name), 'identifier'];
            }
            $flags = $node['flags'] ?? null;
            if (\is_string($flags) && '' !== $flags) {
                $found = strpos($glue, $flags);
                if (false !== $found) {
                    $cuts[] = [$from + $found, $from + $found + \strlen($flags), 'flag'];
                }
            }
        }

        if ([] === $cuts) {
            $tokens[] = [$from, $to, $class];

            return;
        }

        usort($cuts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $at = $from;
        foreach ($cuts as [$cutStart, $cutEnd, $cutClass]) {
            if ($cutStart > $at) {
                $tokens[] = [$at, $cutStart, $class];
            }
            $tokens[] = [$cutStart, $cutEnd, $cutClass];
            $at = max($at, $cutEnd);
        }
        if ($to > $at) {
            $tokens[] = [$at, $to, $class];
        }
    }

    /**
     * The direct children of a dumped node, in the order the document lists
     * them: pattern order.
     *
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    function phpregex_playground_children(array $node): array
    {
        $children = [];
        foreach ($node as $value) {
            if (!\is_array($value)) {
                continue;
            }
            if (isset($value['type'])) {
                $children[] = $value;

                continue;
            }
            foreach ($value as $item) {
                if (\is_array($item) && isset($item['type'])) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }

    /**
     * The class a node's own bytes speak: the highlighting visitor's
     * vocabulary, told from the dumped kind.
     *
     * @param array<string, mixed> $node
     */
    function phpregex_playground_token_class(array $node): string
    {
        switch ($node['type']) {
            case 'anchor':
            case 'assertion':
                return 'anchor';
            case 'quantifier':
                return 'quantifier';
            case 'literal':
                return 'literal';
            case 'char_type':
            case 'char_literal':
            case 'control_char':
            case 'unicode_property':
            case 'posix_class':
            case 'keep':
                return 'escape';
            case 'backref':
                return 'backref';
            case 'comment':
                return 'comment';
            case 'subroutine':
            case 'pcre_verb':
            case 'define':
            case 'limit_match':
            case 'callout':
            case 'script_run':
            case 'version_condition':
                return 'keyword';
            case 'group':
            case 'conditional':
            case 'extended_char_class':
                return 'group';
            default:
                // sequence, alternation, regex, dot, char_class, range and the
                // class set operators: the pattern's own structure.
                return 'meta';
        }
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
            for ($k = 0; $k < $width; $k++) {
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
        // The library wants a release ("8.6.0"); an engine may call itself
        // "8.6.0RC4". The caption keeps the engine's own words.
        $php = 1 === preg_match('/^\d+\.\d+(?:\.\d+)?/', $target['php'], $m) ? $m[0] : $target['php'];
        $pcre = 1 === preg_match('/^\d+\.\d+/', $target['pcre'], $m) ? $m[0] : $target['pcre'];

        return Regex::create([
            'php_version' => $php,
            'pcre_version' => $pcre,
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

    /**
     * Text written into a line comment of the export: a line break or a PHP
     * close tag would end the comment, so both are spelled as escapes.
     */
    function phpregex_playground_comment_text(string $text): string
    {
        return str_replace(["\r", "\n", '?>'], ['\\r', '\\n', '?\\>'], $text);
    }

    function phpregex_playground_short_class(object $object): string
    {
        $class = $object::class;
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
            $cut--;
        }

        return [substr($subject, 0, $cut), true];
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_format(array $input): array
    {
        $started = hrtime(true);
        $regex = phpregex_playground_string($input, 'regex');
        $library = phpregex_playground_library(phpregex_playground_target($input));
        $answer = ['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'format' => null, 'error' => null, 'timing' => ['formatMs' => 0.0]];

        $fault = phpregex_playground_parse_fault($library, $regex);
        if (null !== $fault) {
            $answer['error'] = $fault;

            return phpregex_playground_timed($answer, 'formatMs', $started);
        }

        $width = \is_int($input['width'] ?? null) ? $input['width'] : 80;
        $compact = true === ($input['compact'] ?? false);

        try {
            $result = $compact ? $library->compact($regex) : $library->format($regex, $width);
        } catch (InvalidRegexOptionException $e) {
            $answer['error'] = ['source' => 'format', 'class' => phpregex_playground_short_class($e), 'message' => $e->getMessage(), 'position' => null, 'code' => null, 'hint' => null];

            return phpregex_playground_timed($answer, 'formatMs', $started);
        } catch (RegexException $e) {
            // The formatter refused to hand over a pattern that would not read
            // back as the same one: the pattern stays as written.
            $answer['format'] = ['pattern' => $regex, 'changed' => false, 'refusal' => phpregex_playground_format_refusal('round_trip')];

            return phpregex_playground_timed($answer, 'formatMs', $started);
        }

        $refusal = null !== $result->refusal ? phpregex_playground_format_refusal($result->refusal->value) : null;
        $pattern = $result->pattern;
        $changed = $result->changed;
        // The formatter checks its own output; the boundary checks once more,
        // for the page shows the result as a pattern to copy: it parses, and
        // written on one line it is the pattern that was given.
        if (null === $refusal && $changed && !phpregex_playground_same_pattern($library, $regex, $pattern)) {
            $refusal = phpregex_playground_format_refusal('round_trip');
            $pattern = $regex;
            $changed = false;
        }
        $answer['format'] = ['pattern' => $pattern, 'changed' => $changed, 'refusal' => $refusal];

        return phpregex_playground_timed($answer, 'formatMs', $started);
    }

    /**
     * Two spellings of one pattern: both parse, and compacted to one line they
     * are the same bytes.
     */
    function phpregex_playground_same_pattern(Regex $library, string $before, string $after): bool
    {
        try {
            if ([] !== $library->parseTolerant($after)->errors) {
                return false;
            }

            return $library->compact($before)->pattern === $library->compact($after)->pattern;
        } catch (RegexException $e) {
            return false;
        }
    }

    /**
     * The formatter's refusals, each said in a sentence: the library names
     * them by a code and leaves the words to the caller.
     *
     * @return array{code: string, message: string}
     */
    function phpregex_playground_format_refusal(string $code): array
    {
        $sentences = [
            'newline_convention' => 'The pattern holds # comments under a newline convention where a comment does not end at a line feed.',
            'spaced_quantifier' => 'The pattern holds a quantifier written with spaces, which x would turn into another pattern.',
            'extended_mode_switched_off' => 'The pattern turns x off at its top level: a line break after that point would be a literal.',
            'line_break_in_name' => 'A verb name or a callout string holds a raw line break that no escape can spell.',
            'parenthesis_in_comment' => 'Under a ( delimiter, a # comment holds a ) that PHP counts and no escape can hide.',
            'delimiter_misread' => 'The pattern hides a character of its delimiter behind \c\, which PHP and PCRE read differently.',
            'round_trip' => 'The formatter\'s output did not read back as the same pattern.',
        ];

        return ['code' => $code, 'message' => $sentences[$code] ?? 'The formatter gave the pattern back as written.'];
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_redos(array $input): array
    {
        $started = hrtime(true);
        $regex = phpregex_playground_string($input, 'regex');
        $library = phpregex_playground_library(phpregex_playground_target($input));
        $mode = 'confirmed' === ($input['mode'] ?? null) ? RedosMode::Confirmed : RedosMode::Theoretical;
        $answer = ['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'redos' => null, 'error' => null, 'timing' => ['redosMs' => 0.0]];

        $replayError = null;

        try {
            $analysis = $library->redos($regex, null, $mode);
            $verdict = phpregex_playground_verdict($analysis);
        } catch (RegexException $e) {
            $answer['error'] = phpregex_playground_error($e, 'redos');

            return phpregex_playground_timed($answer, 'redosMs', $started);
        } catch (\Error $e) {
            // The replay runs the library's confirmation, which this engine may
            // not carry through, when it runs or when it is written out (a
            // 32-bit engine reads a 32-bit mask as a float, which the library
            // refuses). The verdict without the replay is answered, with the
            // reason the replay has none.
            if (RedosMode::Confirmed !== $mode) {
                throw $e;
            }
            $replayError = phpregex_playground_short_class($e).': '.$e->getMessage();
            $analysis = $library->redos($regex, null, RedosMode::Theoretical);
            $verdict = phpregex_playground_verdict($analysis);
        }

        $verdict['replay_error'] = $replayError;
        // The rule regex-phpunit's assertPatternIsLinear() applies: a linear
        // attempt and a linear unanchored search.
        $verdict['linear'] = $analysis->isProvenSafe() && null === $analysis->searchCost;
        // The witness as bytes, built here: its JSON spelling is escaped text.
        $verdict['attack'] = null;
        $witness = $analysis->witness;
        if (null !== $witness) {
            $attack = $witness->build(30);
            $confirmation = $analysis->confirmation;
            if (null !== $confirmation) {
                foreach ($confirmation->samples as $sample) {
                    // The input that tripped the engine, as it was: the preview
                    // holds it whole when it fits.
                    if (null !== $sample->pregError && \is_string($sample->inputPreview) && \strlen($sample->inputPreview) === $sample->inputLength) {
                        $attack = $sample->inputPreview;

                        break;
                    }
                }
            }
            // The page carries text: an attack of bytes that are not UTF-8
            // could not reach the subject field as it is.
            $verdict['attack'] = 1 === preg_match('//u', $attack) ? $attack : null;
        }
        $answer['redos'] = $verdict;

        return phpregex_playground_timed($answer, 'redosMs', $started);
    }

    /**
     * The verdict as plain arrays: the library's objects are written out here,
     * where a failure to write them is still the request's own.
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_verdict(object $analysis): array
    {
        /** @var array<string, mixed> $verdict */
        $verdict = json_decode(json_encode($analysis->jsonSerialize(), \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $verdict;
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_transpile(array $input): array
    {
        $started = hrtime(true);
        $regex = phpregex_playground_string($input, 'regex');
        // The dialect has a key of its own: "target" names the engine to judge for.
        $target = phpregex_playground_string($input, 'dialect');
        $library = phpregex_playground_library(phpregex_playground_target($input));
        $answer = ['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'transpile' => null, 'refusal' => null, 'error' => null, 'timing' => ['transpileMs' => 0.0]];

        $fault = phpregex_playground_parse_fault($library, $regex);
        if (null !== $fault) {
            $answer['error'] = $fault;

            return phpregex_playground_timed($answer, 'transpileMs', $started);
        }

        $options = null;
        $pythonVersion = $input['pythonVersion'] ?? null;
        if (\is_string($pythonVersion) && '' !== $pythonVersion) {
            try {
                $options = new TranspileOptions(pythonVersion: $pythonVersion);
            } catch (InvalidRegexOptionException $e) {
                $answer['error'] = ['source' => 'transpile', 'class' => phpregex_playground_short_class($e), 'message' => $e->getMessage(), 'position' => null, 'code' => null, 'hint' => null];

                return phpregex_playground_timed($answer, 'transpileMs', $started);
            }
        }

        try {
            $answer['transpile'] = $library->transpile($regex, $target, $options)->jsonSerialize();
        } catch (RegexException $e) {
            // A construct the target has no spelling for, or a target the
            // transpiler does not know: content, in the transpiler's words.
            $answer['refusal'] = ['message' => $e->getMessage()];
        }

        return phpregex_playground_timed($answer, 'transpileMs', $started);
    }

    /**
     * Subjects for the pattern: a few the engine matches, drawn at random from
     * the tree (the same ones for the same pattern), a few it does not, each
     * one run through preg_match() here before it is answered.
     *
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_samples(array $input): array
    {
        $started = hrtime(true);
        $regex = phpregex_playground_string($input, 'regex');
        $library = phpregex_playground_library(phpregex_playground_target($input));
        $answer = ['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'samples' => null, 'error' => null, 'timing' => ['samplesMs' => 0.0]];

        $fault = phpregex_playground_parse_fault($library, $regex);
        if (null !== $fault) {
            $answer['error'] = $fault;

            return phpregex_playground_timed($answer, 'samplesMs', $started);
        }

        $seed = crc32($regex);
        $matching = [];
        foreach ([0, 1, 2] as $draw) {
            try {
                $matching[] = (new SampleGenerator(3, seed: $seed + $draw))->generate($regex, $library->parser());
            } catch (RegexException $e) {
                break;
            }
        }
        $cases = $library->parse($regex)->accept(new TestCaseGenerator());
        $matching = array_merge($matching, $cases['matching']);
        $nonMatching = $cases['non_matching'];

        $dropped = 0;
        $answer['samples'] = [
            'matching' => phpregex_playground_kept($regex, $matching, 1, $dropped),
            'nonMatching' => phpregex_playground_kept($regex, $nonMatching, 0, $dropped),
            'seed' => $seed,
            'dropped' => $dropped,
        ];

        return phpregex_playground_timed($answer, 'samplesMs', $started);
    }

    /**
     * The strings the engine agrees with, at most three, no duplicate, none
     * with a control byte a chip could not show (a line feed and a tab may
     * stand in a subject), none that is not UTF-8 (the page carries text),
     * none longer than a subject can be (the match tab, the check and the
     * export would cut it).
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    function phpregex_playground_kept(string $regex, array $candidates, int $expected, int &$dropped): array
    {
        $kept = [];
        foreach ($candidates as $candidate) {
            // No duplicate, no control byte a chip could not show, nothing a
            // page carrying text could not send back as it is, nothing
            // longer than a subject can be.
            if (!\is_string($candidate) || \strlen($candidate) > PHPREGEX_PLAYGROUND_SUBJECT_CAP || \in_array($candidate, $kept, true) || 1 === preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $candidate) || 1 !== preg_match('//u', $candidate)) {
                continue;
            }
            if (phpregex_playground_check_one($regex, $candidate)['matched'] !== $expected) {
                $dropped++;

                continue;
            }
            $kept[] = $candidate;
            if (3 === \count($kept)) {
                break;
            }
        }

        return $kept;
    }

    /**
     * The engine alone on a list of subjects: what preg_match() answers for
     * each, run without the JIT the way regex-phpunit runs its assertions, so
     * what is answered here is what a pasted test will see.
     *
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_check(array $input): array
    {
        $started = hrtime(true);
        $regex = phpregex_playground_string($input, 'regex');
        $subjects = phpregex_playground_subjects($input);
        $checks = [];
        if (\is_array($subjects) && \strlen($regex) <= PHPREGEX_PLAYGROUND_PATTERN_CAP) {
            foreach ($subjects as $subject) {
                $checks[] = phpregex_playground_check_one($regex, $subject);
            }
        }

        return phpregex_playground_timed([
            'protocol' => PHPREGEX_PLAYGROUND_PROTOCOL,
            'engine' => phpregex_playground_engine(),
            'checks' => $checks,
            'timing' => ['checkMs' => 0.0],
        ], 'checkMs', $started);
    }

    /**
     * The subjects a request names, when they are what a check or an export
     * takes: a list of at most fifty strings, none past the subject cap.
     * Otherwise the reason, in a sentence.
     *
     * @param array<mixed> $input
     *
     * @return list<string>|string
     */
    function phpregex_playground_subjects(array $input)
    {
        $subjects = $input['subjects'] ?? null;
        if (!\is_array($subjects)) {
            return 'The subjects must be a list of strings.';
        }
        if (\count($subjects) > PHPREGEX_PLAYGROUND_SUBJECTS_CAP) {
            return \sprintf('At most %d subjects at a time.', PHPREGEX_PLAYGROUND_SUBJECTS_CAP);
        }
        $list = [];
        foreach ($subjects as $subject) {
            if (!\is_string($subject)) {
                return 'The subjects must be a list of strings.';
            }
            if (\strlen($subject) > PHPREGEX_PLAYGROUND_SUBJECT_CAP) {
                return \sprintf('A subject is longer than %d bytes: the test would assert something that was never matched.', PHPREGEX_PLAYGROUND_SUBJECT_CAP);
            }
            $list[] = $subject;
        }

        return $list;
    }

    /**
     * One preg_match() without the JIT: matched (1, 0, or null when the engine
     * refused or gave up), the engine's message, and the first match's groups
     * as PHP writes them, by name and by number, a group that did not
     * participate as null.
     *
     * @return array{matched: ?int, error: ?string, groups: ?object}
     */
    function phpregex_playground_check_one(string $regex, string $subject): array
    {
        $jit = ini_get('pcre.jit');
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });

        try {
            // ini_set() may be disabled, in which case PHP 8 has no such
            // function: the engine then runs as it is set, which the browser
            // engine already runs without JIT.
            $toggles = \function_exists('ini_set');
            if ($toggles) {
                @ini_set('pcre.jit', '0');
            }
            $result = preg_match($regex, $subject, $groups, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
            $error = preg_last_error_msg();
        } finally {
            if ($toggles && false !== $jit) {
                @ini_set('pcre.jit', $jit);
            }
            restore_error_handler();
        }

        if (null !== $warning) {
            return ['matched' => null, 'error' => phpregex_playground_engine_message($warning), 'groups' => null];
        }
        if (false === $result) {
            return ['matched' => null, 'error' => $error, 'groups' => null];
        }
        if (0 === $result) {
            return ['matched' => 0, 'error' => null, 'groups' => null];
        }
        $shape = [];
        foreach ($groups as $key => [$text, $offset]) {
            if (null === $text || -1 === $offset) {
                $shape[(string) $key] = null;

                continue;
            }
            // Without u, a group may take part of a character: the answer
            // carries text, so the bytes travel beside it, in hex.
            $shape[(string) $key] = 1 === preg_match('//u', $text) ? [$text, $offset] : [$text, $offset, bin2hex($text)];
        }

        return ['matched' => 1, 'error' => null, 'groups' => (object) $shape];
    }

    /**
     * A regex-phpunit test asserting what the engine answered for the
     * subjects: a method with its data provider, to paste into a test class,
     * and the same body wrapped in a class of its own. Every string in it is
     * written by var_export(); every expectation is the engine's own answer.
     *
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_export(array $input): array
    {
        $started = hrtime(true);
        $regex = phpregex_playground_string($input, 'regex');
        $library = phpregex_playground_library(phpregex_playground_target($input));
        $answer = ['protocol' => PHPREGEX_PLAYGROUND_PROTOCOL, 'export' => null, 'error' => null, 'timing' => ['exportMs' => 0.0]];

        $fault = phpregex_playground_parse_fault($library, $regex);
        if (null !== $fault) {
            $answer['error'] = $fault;

            return phpregex_playground_timed($answer, 'exportMs', $started);
        }
        $subjects = phpregex_playground_subjects($input);
        if (\is_string($subjects)) {
            $answer['error'] = ['source' => 'export', 'class' => null, 'message' => $subjects, 'position' => null, 'code' => null, 'hint' => null];

            return phpregex_playground_timed($answer, 'exportMs', $started);
        }

        // The engine's answers: the ones handed over (another instance of the
        // engine matched, the one the page shows), else this engine's.
        $checks = [];
        $given = $input['checks'] ?? null;
        foreach ($subjects as $i => $subject) {
            $check = \is_array($given) ? ($given[$i] ?? null) : null;
            $checks[] = \is_array($check) && \array_key_exists('matched', $check) ? $check : phpregex_playground_check_one($regex, $subject);
        }

        $analysis = $library->redos($regex);
        $linear = $analysis->isProvenSafe() && null === $analysis->searchCost;

        // A name owning two numbers, under (?J), is asserted by number alone.
        $names = phpregex_playground_group_names($library->parse($regex)->accept(new JsonDumper()));
        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1));

        $rows = [];
        $refused = 0;
        $assertions = 1;
        foreach ($subjects as $i => $subject) {
            $check = $checks[$i];
            if (null === $check['matched']) {
                $refused++;
                // A line break or a PHP close tag would end the comment: both are
                // spelled as escapes, so the exported file always parses.
                $rows[] = \sprintf('        // %s: the engine answered "%s"; nothing to assert', phpregex_playground_comment_text(var_export($subject, true)), phpregex_playground_comment_text((string) $check['error']));

                continue;
            }
            $assertions++;
            $expectation = 1 === $check['matched'] ? phpregex_playground_expectation((array) ($check['groups'] ?? []), $names, $duplicates) : '[]';
            $rows[] = \sprintf(
                "        yield 'subject %d' => ['subject' => %s, 'matches' => %s, 'expectation' => %s];",
                $i + 1,
                var_export($subject, true),
                1 === $check['matched'] ? 'true' : 'false',
                $expectation,
            );
        }

        // The docblock openers are spelled in two pieces: the bundle holds no
        // file with one, the stripped driver included.
        $docblock = '    /'.str_repeat('*', 2);
        // A provider without a row would not be a generator, and PHPUnit
        // would get nothing of an iterable from it: when no subject is
        // asserted, the refusals stand as comments before the compile test,
        // which is then the test alone.
        $dataDriven = $assertions > 1 ? [
            $docblock,
            '     * @return iterable<string, array{subject: string, matches: bool, expectation: array<int|string, mixed>}>',
            '     */',
            '    public static function providePatternSubjects(): iterable',
            '    {',
            ...$rows,
            '    }',
            '',
            $docblock,
            '     * @param array<int|string, mixed> $expectation',
            '     */',
            "    #[DataProvider('providePatternSubjects')]",
            '    public function test_the_pattern_matches_as_the_engine_answered(string $subject, bool $matches, array $expectation): void',
            '    {',
            '        if ($matches) {',
            '            self::assertPatternMatches(self::PATTERN, $subject, $expectation);',
            '',
            '            return;',
            '        }',
            '        self::assertPatternDoesNotMatch(self::PATTERN, $subject);',
            '    }',
            '',
        ] : array_map(static fn (string $row): string => substr($row, 4), $rows);
        $method = implode("\n", [
            '    private const PATTERN = '.var_export($regex, true).';',
            '',
            ...$dataDriven,
            '    public function test_the_pattern_compiles(): void',
            '    {',
            '        self::assertPatternCompiles(self::PATTERN);',
            ...($linear ? ['        self::assertPatternIsLinear(self::PATTERN);'] : []),
            '    }',
        ]);

        // The engine whose answers the file asserts: the one the request
        // names when another analysed, else this one.
        $engine = phpregex_playground_target($input);
        $class = implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '/*',
            \sprintf(' * Written by the PHPRegex playground on %s from the answers of PHP %s and PCRE2 %s.', date('Y-m-d'), $engine['php'], $engine['pcre']),
            ' * Needs php-regex/regex-phpunit and PHPUnit 10 or newer (on 9.6, spell the provider as @dataProvider).',
            ' */',
            '',
            'use PHPRegex\PHPUnit\RegexAssertions;',
            'use PHPUnit\Framework\Attributes\DataProvider;',
            'use PHPUnit\Framework\TestCase;',
            '',
            'final class PatternTest extends TestCase',
            '{',
            '    use RegexAssertions;',
            '',
            $method,
            '}',
            '',
        ]);

        $answer['export'] = ['method' => $method, 'class' => $class, 'assertions' => $assertions, 'linear' => $linear, 'refused' => $refused];

        return phpregex_playground_timed($answer, 'exportMs', $started);
    }

    /**
     * The expectation regex-phpunit takes, spelled as PHP: by name when the
     * group has one, by number otherwise; a group that did not participate as
     * false; group 0 left out, the assertion being the match. A name used
     * twice is asserted by its numbers.
     *
     * @param array<string, array{string, int}|null> $groups
     * @param array<string, string>                  $names      the group names of the pattern, by number
     * @param list<string>                           $duplicates
     */
    function phpregex_playground_expectation(array $groups, array $names, array $duplicates): string
    {
        // The engine writes a group under its name and its number; a JSON
        // round-trip may put the number first. The expectation goes by
        // number, and names the group when the pattern names it once.
        $numbered = [];
        $present = [];
        foreach ($groups as $key => $capture) {
            if (\is_numeric($key)) {
                $numbered[(int) $key] = $capture;
            } else {
                $present[(string) $key] = true;
            }
        }
        ksort($numbered);

        $parts = [];
        foreach ($numbered as $number => $capture) {
            if (0 === $number) {
                continue;
            }
            $name = $names[(string) $number] ?? null;
            if (\is_string($name) && isset($present[$name]) && !\in_array($name, $duplicates, true)) {
                $parts[] = var_export($name, true).' => '.phpregex_playground_capture($capture);

                continue;
            }
            $parts[] = $number.' => '.phpregex_playground_capture($capture);
        }

        return '['.implode(', ', $parts).']';
    }

    /**
     * A capture as PHP: the text by var_export(); a capture that is not text
     * (part of a character, taken without u) by its bytes, in hex beside the
     * text when it went through JSON, spelled as "\xNN" escapes so the
     * exported file is ASCII and asserts what preg_match() writes.
     *
     * @param array{string, int, 2?: string}|null $capture
     */
    function phpregex_playground_capture($capture): string
    {
        if (!\is_array($capture)) {
            return 'false';
        }
        $text = (string) $capture[0];
        if (isset($capture[2]) && \is_string($capture[2]) && 1 === preg_match('/^(?:[0-9a-f]{2})+$/', $capture[2])) {
            $text = (string) hex2bin($capture[2]);
        }
        $spelled = 1 === preg_match('//u', $text) ? var_export($text, true) : phpregex_playground_bytes($text);

        return '['.$spelled.', '.(int) $capture[1].']';
    }

    /**
     * A string of bytes as a double-quoted PHP literal, every byte outside
     * printable ASCII as a "\xNN" escape.
     */
    function phpregex_playground_bytes(string $bytes): string
    {
        return '"'.preg_replace_callback('/[^\x20-\x7E]|["$\\\\]/', static function (array $match): string {
            return \sprintf('\\x%02X', \ord($match[0]));
        }, $bytes).'"';
    }

    /**
     * The first fault of a pattern that does not parse, else null.
     *
     * @return array<string, mixed>|null
     */
    function phpregex_playground_parse_fault(Regex $library, string $regex): ?array
    {
        $parsed = $library->parseTolerant($regex);
        foreach ($parsed->errors as $error) {
            return phpregex_playground_error($error, 'parse');
        }

        return null;
    }

    /**
     * The answer with its timing written: hrtime() is an int on a server, a
     * float in the browser engine.
     *
     * @param array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    function phpregex_playground_timed(array $answer, string $key, int|float $started): array
    {
        $answer['timing'][$key] = round((hrtime(true) - $started) / 1e6, 2);

        return $answer;
    }
}
