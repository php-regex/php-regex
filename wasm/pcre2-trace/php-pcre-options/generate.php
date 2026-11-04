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
 * Writes the options table the playground's step debugger reads: what PHP's
 * wrapper (ext/pcre/php_pcre.c) passes to PCRE2 at each exact PHP version
 * the page's engines report. One row per version, read from php-src at the
 * version's tag and naming the commit, so a modifier that joins (n in 8.2,
 * r in 8.4) or an extra option that leaves (BSK in 8.5) is a fact of the
 * source, never a memory.
 *
 * The parser reads five places of php_pcre.c: the modifier switch, the
 * PHP_PCRE_DEFAULT_EXTRA_COPTIONS define, the coptions initialiser, the ini
 * entries wired to the match context, and pcre_handle_exec_error. The
 * remaining facts of a row (UTF check done by PHP, newline LF, \R Unicode)
 * are the bundled library's configuration, the same at every version.
 *
 * Usage:
 *   php generate.php <php-src-dir> <tag>...
 *       reads ext/pcre/php_pcre.c and ext/pcre/pcre2lib/pcre2.h at each tag
 *       (git show), names the peeled commit
 *   php generate.php --from-file <version>=<path>:<commit>:<pcre2>...
 *       reads a saved php_pcre.c instead (the commit and the bundled PCRE2
 *       release given by hand)
 *
 * Both forms write docs/assets/js/php-pcre-options.js; the rows are written
 * in the order given.
 */

if (!\function_exists('phpregex_pcre_options_parse')) {
    /*
     * PCRE2's option bits, stable from 10.3x to 10.49: a row holds the
     * numbers, the worker never sees a name.
     */
    \define('PHPREGEX_PCRE2_COMPILE_OPTIONS', [
        'PCRE2_CASELESS' => 0x8,
        'PCRE2_MULTILINE' => 0x400,
        'PCRE2_NO_AUTO_CAPTURE' => 0x2000,
        'PCRE2_DOTALL' => 0x20,
        'PCRE2_EXTENDED' => 0x80,
        'PCRE2_ANCHORED' => 0x80000000,
        'PCRE2_DOLLAR_ENDONLY' => 0x10,
        'PCRE2_UNGREEDY' => 0x40000,
        'PCRE2_UTF' => 0x80000,
        'PCRE2_UCP' => 0x20000,
        'PCRE2_DUPNAMES' => 0x40,
    ]);
    \define('PHPREGEX_PCRE2_EXTRA_OPTIONS', [
        'PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK' => 0x40,
        'PCRE2_EXTRA_CASELESS_RESTRICT' => 0x80,
    ]);

    /*
     * PHP's PREG_* codes by the name php_pcre.h gives them.
     */
    \define('PHPREGEX_PREG_CODES', [
        'PHP_PCRE_NO_ERROR' => 0,
        'PHP_PCRE_INTERNAL_ERROR' => 1,
        'PHP_PCRE_BACKTRACK_LIMIT_ERROR' => 2,
        'PHP_PCRE_RECURSION_LIMIT_ERROR' => 3,
        'PHP_PCRE_BAD_UTF8_ERROR' => 4,
        'PHP_PCRE_BAD_UTF8_OFFSET_ERROR' => 5,
        'PHP_PCRE_JIT_STACKLIMIT_ERROR' => 6,
    ]);

    /*
     * The table's keys of pcre_handle_exec_error's cases, by the PCRE2
     * error each case names; the UTF8_ERR1..ERR21 range and the fallback
     * live in the default branch.
     */
    \define('PHPREGEX_EXEC_ERROR_KEYS', [
        'PCRE2_ERROR_MATCHLIMIT' => 'matchLimit',
        'PCRE2_ERROR_RECURSIONLIMIT' => 'depthLimit',
        'PCRE2_ERROR_DEPTHLIMIT' => 'depthLimit',
        'PCRE2_ERROR_BADUTFOFFSET' => 'badUtfOffset',
        'PCRE2_ERROR_JIT_STACKLIMIT' => 'jitStackLimit',
    ]);

    /**
     * The facts of one php_pcre.c: what the generator reads before any
     * number is assigned.
     *
     *   modifiers  letter => the PCRE2_* compile options it ORs in ([] for
     *              a no-op such as S or X)
     *   extra      letter => the PCRE2_EXTRA_* options it ORs in
     *   refused    letters the switch rejects (e, the legacy eval)
     *   bsk        PHP_PCRE_DEFAULT_EXTRA_COPTIONS is the BSK define
     *   baseRule   the preprocessor condition under which coptions starts
     *              from PCRE2_NO_START_OPTIMIZE instead of 0
     *   matchLimit pcre.backtrack_limit's default, wired to pcre2_set_match_limit
     *   depthLimit pcre.recursion_limit's default, wired to pcre2_set_depth_limit
     *   errors     pcre_handle_exec_error as PREG_* codes
     *
     * @throws RuntimeException when a place the generator reads is missing
     *
     * @return array{modifiers: array<string, list<string>>, extra: array<string, list<string>>, refused: list<string>, bsk: bool, baseRule: string, matchLimit: int, depthLimit: int, errors: array<string, int>}
     */
    function phpregex_pcre_options_parse(string $source): array
    {
        [$modifiers, $extra, $refused] = phpregex_pcre_options_switch($source);
        [$matchLimit, $depthLimit] = phpregex_pcre_options_limits($source);

        return [
            'modifiers' => $modifiers,
            'extra' => $extra,
            'refused' => $refused,
            'bsk' => 1 === preg_match('/^#define PHP_PCRE_DEFAULT_EXTRA_COPTIONS PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK\b/m', $source),
            'baseRule' => phpregex_pcre_options_base_rule($source),
            'matchLimit' => $matchLimit,
            'depthLimit' => $depthLimit,
            'errors' => phpregex_pcre_options_errors($source),
        ];
    }

    /**
     * The table's row for the facts of one version: the names resolved to
     * PCRE2's numbers, with the version's tag, commit and bundled release.
     *
     * @param array{modifiers: array<string, list<string>>, extra: array<string, list<string>>, refused: list<string>, bsk: bool, baseRule: string, matchLimit: int, depthLimit: int, errors: array<string, int>} $facts
     *
     * @throws RuntimeException on a PCRE2 option name the table does not know
     *
     * @return array{commit: string, tag: string, pcre2: string, base: int, baseRule: string, modifiers: array<string, int>, extra: array<string, int>, refused: list<string>, utfCheck: string, matchLimit: int, depthLimit: int, newline: string, bsr: string, errors: array<string, int>}
     */
    function phpregex_pcre_options_row(array $facts, string $version, string $commit, string $pcre2): array
    {
        $modifiers = [];
        foreach ($facts['modifiers'] as $letter => $names) {
            $modifiers[$letter] = phpregex_pcre_options_bits($names, PHPREGEX_PCRE2_COMPILE_OPTIONS);
        }
        $extra = ['always' => $facts['bsk'] ? PHPREGEX_PCRE2_EXTRA_OPTIONS['PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK'] : 0];
        foreach ($facts['extra'] as $letter => $names) {
            $extra[$letter] = phpregex_pcre_options_bits($names, PHPREGEX_PCRE2_EXTRA_OPTIONS);
        }

        return [
            'commit' => $commit,
            'tag' => 'php-'.$version,
            'pcre2' => $pcre2,
            // The bundled library is never the external 10.37 the rule
            // names: every compile starts from coptions = 0.
            'base' => 0,
            'baseRule' => 'PCRE2_NO_START_OPTIMIZE only for an external PCRE2 10.37 ('.$facts['baseRule'].'); the bundled library starts from 0',
            'modifiers' => $modifiers,
            'extra' => $extra,
            'refused' => $facts['refused'],
            // PHP validates the subject itself under u, and passes
            // PCRE2_NO_UTF_CHECK otherwise; the bundled config.h sets
            // NEWLINE_DEFAULT 2 (LF) and BSR_ANYCRLF off (\R is Unicode).
            'utfCheck' => 'php',
            'matchLimit' => $facts['matchLimit'],
            'depthLimit' => $facts['depthLimit'],
            'newline' => 'lf',
            'bsr' => 'unicode',
            'errors' => $facts['errors'],
        ];
    }

    /**
     * The modifier switch (switch (*pp++) { case 'i': ... }): the compile
     * options and the extra options each letter ORs in, and the letters
     * refused. Whitespace cases are separators, not modifiers. A case that
     * falls through takes the body of the case below it; a case that falls
     * into default, or raises PREG_REPLACE_EVAL, is refused.
     *
     * @return array{array<string, list<string>>, array<string, list<string>>, list<string>}
     */
    function phpregex_pcre_options_switch(string $source): array
    {
        $start = strpos($source, 'switch (*pp++) {');
        if (false === $start) {
            throw new RuntimeException('No modifier switch (switch (*pp++)) in the source.');
        }
        $end = strpos($source, 'default:', $start);
        if (false === $end) {
            throw new RuntimeException('The modifier switch has no default branch.');
        }
        $body = substr($source, $start, $end - $start);
        if (0 === preg_match_all("/case '(\\\\?.)':/", $body, $m, \PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('The modifier switch has no case.');
        }

        // The text of each case: from its label to the next label, or to
        // the default branch for the last one.
        $labels = $m[0];
        $letters = $m[1];
        $cases = [];
        foreach ($labels as $i => [$label, $offset]) {
            $from = $offset + \strlen($label);
            $to = isset($labels[$i + 1]) ? $labels[$i + 1][1] : \strlen($body);
            $cases[] = ['letter' => stripcslashes($letters[$i][0]), 'text' => substr($body, $from, $to - $from), 'last' => !isset($labels[$i + 1])];
        }

        $modifiers = [];
        $extra = [];
        $refused = [];
        $fallthrough = null;
        for ($i = \count($cases) - 1; $i >= 0; $i--) {
            $case = $cases[$i];
            $text = $case['text'];
            $ends = 1 === preg_match('/\b(break|return)\b/', $text);
            if (!$ends && !$case['last'] && null !== $fallthrough) {
                $text .= $fallthrough;
            }
            $fallthrough = $text;
            $letter = $case['letter'];
            if (1 === preg_match('/^\s$/', $letter)) {
                continue;
            }
            if (!$ends && $case['last']) {
                // Falls into default: the "Unknown modifier" refusal.
                $refused[] = $letter;

                continue;
            }
            if (str_contains($text, 'PREG_REPLACE_EVAL')) {
                $refused[] = $letter;

                continue;
            }
            preg_match_all('/coptions \|= (PCRE2_\w+);/', $text, $c);
            preg_match_all('/eoptions \|= (PCRE2_EXTRA_\w+);/', $text, $e);
            if ([] !== $e[1]) {
                $extra[$letter] = $e[1];
            }
            if ([] !== $c[1] || [] === $e[1]) {
                $modifiers[$letter] = $c[1];
            }
        }
        $refused = array_reverse($refused);

        return [array_reverse($modifiers, true), array_reverse($extra, true), $refused];
    }

    /**
     * The condition under which coptions starts from PCRE2_NO_START_OPTIMIZE
     * (an external PCRE2 10.37), as the source spells it.
     */
    function phpregex_pcre_options_base_rule(string $source): string
    {
        if (1 !== preg_match('/^#if (.*)\n\s*uint32_t\s+coptions = PCRE2_NO_START_OPTIMIZE;\n#else\n\s*uint32_t\s+coptions = 0;\n#endif/m', $source, $m)) {
            throw new RuntimeException('No coptions initialiser (PCRE2_NO_START_OPTIMIZE under a condition, else 0) in the source.');
        }

        return 'coptions = PCRE2_NO_START_OPTIMIZE when '.trim($m[1]).', else 0';
    }

    /**
     * The defaults of pcre.backtrack_limit and pcre.recursion_limit, each
     * checked to be the value its ini handler passes to the match context.
     *
     * @return array{int, int}
     */
    function phpregex_pcre_options_limits(string $source): array
    {
        $limits = [];
        foreach (['backtrack_limit' => 'pcre2_set_match_limit', 'recursion_limit' => 'pcre2_set_depth_limit'] as $ini => $setter) {
            if (1 !== preg_match('/STD_PHP_INI_ENTRY\("pcre\.'.$ini.'",\s*"(\d+)"/', $source, $m)) {
                throw new RuntimeException(\sprintf('No ini entry pcre.%s in the source.', $ini));
            }
            if (1 !== preg_match('/'.$setter.'\(mctx, \(uint32_t\)PCRE_G\('.$ini.'\)\)/', $source)) {
                throw new RuntimeException(\sprintf('pcre.%s is not wired to %s in the source.', $ini, $setter));
            }
            $limits[] = (int) $m[1];
        }

        return [$limits[0], $limits[1]];
    }

    /**
     * pcre_handle_exec_error: the PCRE2 match errors to PREG_* codes, in
     * the table's key order.
     *
     * @return array<string, int>
     */
    function phpregex_pcre_options_errors(string $source): array
    {
        $start = strpos($source, 'static void pcre_handle_exec_error(int pcre_code)');
        if (false === $start) {
            throw new RuntimeException('No pcre_handle_exec_error in the source.');
        }
        $end = strpos($source, 'PCRE_G(error_code) = preg_code;', $start);
        if (false === $end) {
            throw new RuntimeException('pcre_handle_exec_error does not end by storing preg_code.');
        }
        $body = substr($source, $start, $end - $start);

        $errors = [];
        preg_match_all('/case (PCRE2_ERROR_\w+):\s*preg_code = (PHP_PCRE_\w+);/', $body, $m, \PREG_SET_ORDER);
        foreach ($m as [, $pcre2, $preg]) {
            if (!isset(PHPREGEX_EXEC_ERROR_KEYS[$pcre2])) {
                throw new RuntimeException(\sprintf('pcre_handle_exec_error maps %s, which the table has no key for.', $pcre2));
            }
            $errors[PHPREGEX_EXEC_ERROR_KEYS[$pcre2]] = phpregex_pcre_options_preg_code($preg);
        }
        if (1 !== preg_match('/if \(pcre_code <= PCRE2_ERROR_UTF8_ERR1 && pcre_code >= PCRE2_ERROR_UTF8_ERR21\) \{\s*preg_code = (PHP_PCRE_\w+);\s*\} else\s*\{\s*preg_code = (PHP_PCRE_\w+);/', $body, $d)) {
            throw new RuntimeException('pcre_handle_exec_error has no default branch for the UTF-8 errors and the rest.');
        }
        $errors['badUtf'] = phpregex_pcre_options_preg_code($d[1]);
        $errors['internal'] = phpregex_pcre_options_preg_code($d[2]);

        $ordered = [];
        foreach (['matchLimit', 'depthLimit', 'badUtfOffset', 'badUtf', 'jitStackLimit', 'internal'] as $key) {
            if (!isset($errors[$key])) {
                throw new RuntimeException(\sprintf('pcre_handle_exec_error has no case for %s.', $key));
            }
            $ordered[$key] = $errors[$key];
        }

        return $ordered;
    }

    function phpregex_pcre_options_preg_code(string $name): int
    {
        if (!isset(PHPREGEX_PREG_CODES[$name])) {
            throw new RuntimeException(\sprintf('Unknown PREG code name %s.', $name));
        }

        return PHPREGEX_PREG_CODES[$name];
    }

    /**
     * @param list<string>       $names
     * @param array<string, int> $known
     */
    function phpregex_pcre_options_bits(array $names, array $known): int
    {
        $bits = 0;
        foreach ($names as $name) {
            if (!isset($known[$name])) {
                throw new RuntimeException(\sprintf('Unknown PCRE2 option %s: add it to the table of numbers.', $name));
            }
            $bits |= $known[$name];
        }

        return $bits;
    }

    /**
     * The table as the page loads it: one classic-script assignment of a
     * JSON object.
     *
     * @param array<string, array<string, mixed>> $rows
     */
    function phpregex_pcre_options_render(array $rows): string
    {
        return 'window.PHPRegexPcreOptions = '.json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR).";\n";
    }

    /**
     * The output of a git command in a checkout, or an exception.
     *
     * @param list<string> $arguments
     */
    function phpregex_pcre_options_git(string $checkout, array $arguments): string
    {
        $command = 'git -C '.escapeshellarg($checkout).' '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1';
        exec($command, $output, $exit);
        if (0 !== $exit) {
            throw new RuntimeException($command."\n".implode("\n", $output));
        }

        return implode("\n", $output);
    }

    /**
     * The bundled PCRE2 release at a tag: PCRE2_MAJOR.PCRE2_MINOR of
     * ext/pcre/pcre2lib/pcre2.h.
     */
    function phpregex_pcre_options_bundled_release(string $checkout, string $tag): string
    {
        $header = phpregex_pcre_options_git($checkout, ['show', $tag.':ext/pcre/pcre2lib/pcre2.h']);
        if (1 !== preg_match('/^#define PCRE2_MAJOR\s+(\d+)$/m', $header, $major) || 1 !== preg_match('/^#define PCRE2_MINOR\s+(\d+)$/m', $header, $minor)) {
            throw new RuntimeException(\sprintf('No PCRE2_MAJOR/PCRE2_MINOR in ext/pcre/pcre2lib/pcre2.h at %s.', $tag));
        }

        return $major[1].'.'.$minor[1];
    }

    /**
     * @param list<string> $argv
     */
    function phpregex_pcre_options_main(array $argv): int
    {
        $usage = "usage: php generate.php <php-src-dir> <tag>...\n       php generate.php --from-file <version>=<path>:<commit>:<pcre2>...\n";
        $rows = [];

        try {
            if (isset($argv[1]) && '--from-file' === $argv[1]) {
                foreach (\array_slice($argv, 2) as $entry) {
                    if (1 !== preg_match('/^([0-9.]+)=(.+):([0-9a-f]{40}):(\d+\.\d+)$/', $entry, $m)) {
                        fwrite(\STDERR, $usage);

                        return 2;
                    }
                    $source = @file_get_contents($m[2]);
                    if (false === $source) {
                        throw new RuntimeException(\sprintf('Cannot read %s.', $m[2]));
                    }
                    $rows[$m[1]] = phpregex_pcre_options_row(phpregex_pcre_options_parse($source), $m[1], $m[3], $m[4]);
                }
            } elseif (\count($argv) >= 3) {
                $checkout = $argv[1];
                foreach (\array_slice($argv, 2) as $tag) {
                    if (1 !== preg_match('/^php-([0-9.]+)$/', $tag, $m)) {
                        throw new RuntimeException(\sprintf('%s is not a php-<version> tag.', $tag));
                    }
                    $commit = phpregex_pcre_options_git($checkout, ['rev-parse', $tag.'^{commit}']);
                    $source = phpregex_pcre_options_git($checkout, ['show', $tag.':ext/pcre/php_pcre.c']);
                    $rows[$m[1]] = phpregex_pcre_options_row(phpregex_pcre_options_parse($source), $m[1], $commit, phpregex_pcre_options_bundled_release($checkout, $tag));
                }
            } else {
                fwrite(\STDERR, $usage);

                return 2;
            }
            if ([] === $rows) {
                // An empty table would disable the step debugger on every
                // engine: the tracked one is kept.
                fwrite(\STDERR, "generate.php: no row to write, the table is left as it is\n".$usage);

                return 2;
            }
            $target = \dirname(__DIR__, 3).'/docs/assets/js/php-pcre-options.js';
            file_put_contents($target, phpregex_pcre_options_render($rows));
            fwrite(\STDOUT, \sprintf("%s: %d rows (%s)\n", $target, \count($rows), implode(', ', array_keys($rows))));
        } catch (RuntimeException $e) {
            fwrite(\STDERR, 'generate.php: '.$e->getMessage()."\n");

            return 1;
        }

        return 0;
    }
}

// Run only when invoked as a script: a require_once from a test or another
// tool declares the functions and nothing else.
if (\PHP_SAPI === 'cli' && isset($argv[0]) && __FILE__ === realpath($argv[0])) {
    exit(phpregex_pcre_options_main($argv));
}
