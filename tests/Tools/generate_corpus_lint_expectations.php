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

/*
 * Rebuilds the corpus lint expectations from corpus/corpus.log.
 *
 * The log is a rendered report: reading a pattern back out of it is lossy,
 * and the linter's own rules would have to be re-implemented to decide which
 * of the rendered warnings a bare pattern still deserves. Doing that inside
 * the test made the test agree with the linter by construction.
 *
 * So it happens here, once, and what comes out is committed: the fixture says
 * which rules each pattern raises today, and a change to the linter shows up
 * as a diff to review rather than as a heuristic quietly dropping a case.
 *
 * Usage: php tests/Tools/generate_corpus_lint_expectations.php
 */

require_once __DIR__.'/../../vendor/autoload.php';

use RegexParser\Exception\ParserException;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\Regex;

const ARROW = "\xE2\x86\x92";

$logPath = \dirname(__DIR__, 2).'/corpus/corpus.log';
$fixturePath = \dirname(__DIR__).'/Fixtures/Corpus/lint-expectations.json';

$patterns = readPatterns($logPath);
$regex = Regex::create(['max_recursion_depth' => 4096]);

$cases = [];
$unreadable = 0;

foreach ($patterns as $pattern) {
    try {
        $ast = $regex->parse($pattern);
    } catch (ParserException $e) {
        set_error_handler(static fn (): bool => true);
        $pcreAccepts = false !== @preg_match($pattern, '');
        restore_error_handler();

        if ($pcreAccepts) {
            fwrite(\STDERR, \sprintf("PCRE accepts a pattern the parser rejects:\n  %s\n  %s\n", $pattern, $e->getMessage()));

            exit(1);
        }

        // The log rendering lost something; PCRE will not take it either.
        $unreadable++;

        continue;
    }

    $linter = new LinterNodeVisitor();
    $ast->accept($linter);

    $issues = array_values(array_unique(array_map(
        static fn (object $issue): string => $issue->id,
        $linter->getIssues(),
    )));
    sort($issues);

    if ([] === $issues) {
        continue;
    }

    $cases[] = ['pattern' => $pattern, 'issues' => $issues];
}

file_put_contents(
    $fixturePath,
    json_encode($cases, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE).\PHP_EOL,
);

printf(
    "%d patterns read, %d unreadable, %d with issues written to %s\n",
    \count($patterns),
    $unreadable,
    \count($cases),
    $fixturePath,
);

/**
 * The patterns of the rendered report, one per "→" line.
 *
 * @return array<int, string>
 */
function readPatterns(string $path): array
{
    $lines = file($path, \FILE_IGNORE_NEW_LINES);
    if (false === $lines) {
        throw new \RuntimeException(\sprintf('Unable to read corpus log at %s', $path));
    }

    $patterns = [];
    $collecting = false;
    $patternLines = [];
    $indent = 0;

    $arrow = preg_quote(ARROW, '/');

    foreach ($lines as $line) {
        if ($collecting) {
            $trimmed = ltrim($line);
            $continues = '' !== $trimmed
                && !preg_match('/^(WARN|FAIL|TIP)\b/', $trimmed)
                && !str_starts_with($trimmed, 'corpus/')
                && !str_starts_with($trimmed, ARROW);

            if ($continues) {
                $patternLines[] = stripIndent($line, $indent);

                continue;
            }

            $pattern = readable(implode("\n", $patternLines));
            if (null !== $pattern) {
                $patterns[$pattern] = true;
            }

            $collecting = false;
            $patternLines = [];
            $indent = 0;
        }

        if (preg_match('/^(\s*)'.$arrow.'\s+(.*)$/', $line, $matches)) {
            $indent = \strlen($matches[1]);
            $patternLines = [$matches[2]];
            $collecting = true;
        }
    }

    if ($collecting) {
        $pattern = readable(implode("\n", $patternLines));
        if (null !== $pattern) {
            $patterns[$pattern] = true;
        }
    }

    return array_keys($patterns);
}

function stripIndent(string $line, int $indent): string
{
    if (0 === $indent) {
        return $line;
    }

    $prefix = str_repeat(' ', $indent);

    return str_starts_with($line, $prefix) ? substr($line, $indent) : ltrim($line, ' ');
}

/**
 * Null when the rendering is ambiguous: under /x the log cannot tell a real
 * newline, which ends a comment, from the two characters "\n".
 */
function readable(string $pattern): ?string
{
    if (!str_contains($pattern, '\n')) {
        return $pattern;
    }

    // Only the modifiers matter here, and the last delimiter tells them apart
    // without asking the library to parse a pattern it may well refuse.
    $delimiter = $pattern[0] ?? '';
    $closing = match ($delimiter) {
        '(' => ')', '[' => ']', '{' => '}', '<' => '>',
        default => $delimiter,
    };

    $end = strrpos($pattern, $closing);
    if ('' === $delimiter || false === $end || 0 === $end) {
        return null;
    }

    $body = substr($pattern, 1, $end - 1);
    $flags = substr($pattern, $end + 1);

    return str_contains($flags, 'x') && str_contains($body, '#') ? null : $pattern;
}
