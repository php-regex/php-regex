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

namespace RegexParser\Tests\TestUtils;

/**
 * Extracts one compilation case per pattern from a pair of vendored PCRE2
 * testdata files (testinputN + testoutputN).
 *
 * The input side is parsed with pcre2test's own rules, verified against the
 * real tool: a pattern line starts with a delimiter at column 0, runs until
 * the terminating delimiter (newlines inside the pattern are kept), and is
 * followed by a comma-separated modifier list in which only the first item
 * may be a run of one-letter abbreviations. A terminator immediately
 * followed by a backslash ends the pattern and hands that backslash to it.
 * Once a pattern is complete, every following line up to the next blank
 * (or whitespace-only) line is subject data for it, whatever byte the line
 * starts with: pcre2test never reads a pattern or a command inside a data
 * block. The output side echoes every input line, so both files are walked
 * in lockstep and every non-echo line is attached to the case it belongs
 * to; the compilation verdict comes from the rejection lines that appear
 * between a pattern's echo and its first subject echo, outside any
 * bytecode dump.
 *
 * Every case that cannot be asserted against Regex::validate() carries a
 * skip category and reason instead of silently disappearing from the set.
 * Cases whose verdict differs between the PCRE2 floor release and the pin
 * are found by compiling them on a real floor pcre2test (applyFloor), never
 * by recognising constructs.
 */
final class Pcre2TestdataExtractor
{
    /**
     * Oldest PCRE2 shipped with the oldest supported PHP (8.2.0). A case
     * whose verdict on a real pcre2test of this release differs from PHP's
     * on the pin is skipped (newer-than-floor or stricter-than-floor).
     */
    public const PCRE2_FLOOR = '10.40';

    /**
     * The PCRE2 release the vendored testdata files come from. Extraction
     * cross-checks every case against the running preg_match, so it must
     * run on a PHP linked to exactly this release.
     */
    public const PCRE2_PIN = '10.48';

    /**
     * Regex::DEFAULT_MAX_PATTERN_LENGTH, checked against the reconstructed
     * delimiter + body + delimiter string.
     */
    private const MAX_RECONSTRUCTED_LENGTH = 100_000;

    /**
     * The delimiters pcre2test accepts to start a pattern line. Bracket
     * pairs are accepted as well for the PHP-facing harness; pcre2test
     * itself does not accept them, and no vendored pattern uses them.
     */
    private const DELIMITERS = ['/', '!', '"', "'", '`', '-', '=', '_', ':', ';', ',', '%', '&', '@', '~'];

    private const BRACKET_CLOSERS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    private const SKIP_ORDER = [
        'pcre2test-api',
        'modifier-inexpressible',
        'php-inexpressible',
        'newline-command',
        'newer-than-floor',
        'length-limit',
        'ambiguous',
        'engine-skipped',
    ];

    /**
     * Default modifiers set by a "#pattern" command for the patterns that
     * follow it, until removed again with a "-name" item.
     *
     * @var list<string>
     */
    private array $defaultPatternModifiers = [];

    private bool $explicitNewlineCommand = false;

    /**
     * pcre2test modifier to PHP pattern modifier. One-letter pcre2test
     * abbreviations are included as keys.
     *
     * @return array<string, string>
     */
    public static function modifierMap(): array
    {
        return [
            'caseless' => 'i',
            'multiline' => 'm',
            'dotall' => 's',
            'extended' => 'x',
            'anchored' => 'A',
            'dollar_endonly' => 'D',
            'dupnames' => 'J',
            'ungreedy' => 'U',
            'utf' => 'u',
            'extra' => 'X',
            'no_auto_capture' => 'n',
            'i' => 'i',
            'm' => 'm',
            's' => 's',
            'x' => 'x',
            'n' => 'n',
        ];
    }

    /**
     * Modifiers with no effect on the compilation verdict under PHP: display
     * controls (information, bytecode dumps), match-loop controls,
     * match-time options and substitution settings, plus
     * allow_lookaround_bsk, which asks for what PHP's compile context
     * always does (php-src sets PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK by default
     * since PHP 8.1.0). They are dropped without skipping the case; every
     * "substitute_*" name is dropped too.
     *
     * @return list<string>
     */
    public static function droppedModifiers(): array
    {
        return [
            'B',
            'a',
            'aftertext',
            'allaftertext',
            'allcaptures',
            'allow_lookaround_bsk',
            'allusedtext',
            'allvector',
            'alt_circumflex',
            'altglobal',
            'ascii_all',
            'ascii_bsd',
            'ascii_bss',
            'ascii_bsw',
            'ascii_digit',
            'ascii_posix',
            'auto_possess',
            'auto_possess_off',
            'bincode',
            'bsr',
            'callout_info',
            'debug',
            'dotstar_anchor',
            'dotstar_anchor_off',
            'endanchored',
            'escaped_cr_is_lf',
            'firstline',
            'fullbincode',
            'g',
            'global',
            'I',
            'info',
            'jit',
            'jitstack',
            'locale',
            'mark',
            'match_line',
            'match_unset_backref',
            'match_word',
            'no_auto_possess',
            'no_dotstar_anchor',
            'no_jit',
            'no_start_optimize',
            'no_utf_check',
            'optimization_full',
            'optimization_none',
            'ph',
            'ps',
            'replace',
            'start_optimize',
            'start_optimize_off',
            'startchar',
            'study',
            'subject_literal',
            'tables',
            'ucp',
            'use_offset_limit',
        ];
    }

    /**
     * Compile-visible modifiers with no PHP-expressible equivalent: they can
     * change the pattern text or whether PCRE2 accepts it, so their cases
     * are skipped rather than asserted with a wrong modifier set. Every
     * "convert*" name (pattern conversion before compiling) belongs here
     * too.
     *
     * @return list<string>
     */
    public static function inexpressibleModifiers(): array
    {
        return [
            'allow_empty_class',
            'allow_surrogate_escapes',
            'alt_bsux',
            'alt_extended_class',
            'alt_verbnames',
            'auto_callout',
            'bad_escape_is_literal',
            'extended_more',
            'extra_alt_bsux',
            'literal',
            'max_pattern_compiled_length',
            'max_pattern_length',
            'max_varlookbehind',
            'never_backslash_c',
            'never_callout',
            'never_ucp',
            'never_utf',
            'no_bs0',
            'parens_nest_limit',
            'python_octal',
            'turkish_casing',
            'xx',
        ];
    }

    /**
     * Modifiers introduced after PCRE2_FLOOR that newer PHP releases can
     * spell ("r", PHP 8.4). The oldest supported PHP cannot express them, so
     * their cases are excluded from the fix plan by design.
     *
     * @return list<string>
     */
    public static function newerThanFloorModifiers(): array
    {
        return [
            'caseless_restrict',
            'r',
        ];
    }

    /**
     * Extracts the cases of one testinput/testoutput pair given as strings.
     *
     * @return list<array<string, mixed>> cases in file order, each keyed
     *                                    id, pattern, delimiter, flags,
     *                                    verdict, offset, error,
     *                                    pcre2Code, phpOverride and floor
     *                                    (always null here: they come from
     *                                    the live cross-check and from
     *                                    applyFloor), skipCategory,
     *                                    skipReason
     */
    public function extract(string $input, string $output, string $name): array
    {
        $this->defaultPatternModifiers = [];
        $this->explicitNewlineCommand = false;

        $inputLines = self::splitLines($input);
        [$cases, $events] = $this->parseInput($inputLines);
        $cases = $this->pairOutput($inputLines, $events, $cases, self::splitLines($output));

        $rows = [];

        foreach ($cases as $case) {
            $rows[] = $this->buildRow($case, $name);
        }

        return $rows;
    }

    /**
     * Records what the PCRE2 floor release does with every assertable row
     * and skips the rows whose verdict differs from PHP's: as
     * newer-than-floor when the floor rejects a pattern PHP compiles, as
     * stricter-than-floor when the floor compiles a pattern PHP rejects. No
     * library verdict can be right on both supported engines for those.
     *
     * PHP's verdict is the phpOverride's when there is one, the suite's
     * otherwise. Skipped rows are not observed and keep a null floor.
     *
     * @param list<array<string, mixed>>                                                                    $rows          rows in the canonical shape
     * @param callable(array<string, mixed>): array{verdict: string, offset: int|null, pcre2Code: int|null} $floorObserver compiles one row on the floor release
     *
     * @return list<array<string, mixed>>
     */
    public static function applyFloor(array $rows, callable $floorObserver): array
    {
        foreach ($rows as $index => $row) {
            if (null !== ($row['skipCategory'] ?? null)) {
                continue;
            }

            $floor = $floorObserver($row);
            $override = $row['phpOverride'] ?? null;
            $phpVerdict = \is_array($override) ? ($override['verdict'] ?? null) : ($row['verdict'] ?? null);
            $row['floor'] = $floor;

            if ($floor['verdict'] !== $phpVerdict) {
                $newer = 'reject' === $floor['verdict'];
                $row['verdict'] = null;
                $row['offset'] = null;
                $row['error'] = null;
                $row['pcre2Code'] = null;
                $row['skipCategory'] = $newer ? 'newer-than-floor' : 'stricter-than-floor';
                $row['skipReason'] = $newer
                    ? \sprintf('PCRE2 %s (the floor) rejects this pattern with error %s at offset %s; PHP on PCRE2 %s compiles it', self::PCRE2_FLOOR, $floor['pcre2Code'] ?? '?', $floor['offset'] ?? '?', self::PCRE2_PIN)
                    : \sprintf('PCRE2 %s (the floor) compiles this pattern; PHP on PCRE2 %s rejects it', self::PCRE2_FLOOR, self::PCRE2_PIN);
            }

            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Extracts the cases of one vendored testinput/testoutput file pair.
     *
     * @param string $inputPath  path to the testinput file
     * @param string $outputPath path to the matching testoutput file
     * @param string $name       case-id prefix, the testinput file name
     *
     * @return list<array<string, mixed>> cases in file order
     */
    public function extractFilePair(string $inputPath, string $outputPath, string $name): array
    {
        $input = file_get_contents($inputPath);
        $output = file_get_contents($outputPath);

        if (false === $input || false === $output) {
            throw new \RuntimeException(\sprintf('Unable to read the testdata pair %s / %s.', $inputPath, $outputPath));
        }

        return $this->extract($input, $output, $name);
    }

    /**
     * Splits on "\n" only: the vendored files keep CR and NEL bytes as
     * pattern data, and a trailing newline must not produce a phantom line.
     *
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        $lines = explode("\n", $text);

        if (str_ends_with($text, "\n") && '' === $lines[\count($lines) - 1]) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Parses the testinput lines into cases, one per pattern line start,
     * snapshotting the command context each pattern was parsed under, and
     * records per input line whether it is pattern text (and of which
     * case), a command, or subject data.
     *
     * @param list<string> $lines
     *
     * @return array{0: list<Pcre2ParsedCase>, 1: list<array{type: string, caseIdx: int|null}>}
     */
    private function parseInput(array $lines): array
    {
        $cases = [];
        $events = [];
        $open = null;
        $inData = false;

        foreach ($lines as $index => $line) {
            if (null !== $open) {
                $events[] = ['type' => 'pattern', 'caseIdx' => $open];
                $case = $cases[$open];
                $terminator = $this->findTerminator($line, $case->delimiter, 0);

                if (null === $terminator) {
                    $case->body .= "\n".$line;

                    continue;
                }

                $case->body .= "\n".substr($line, 0, $terminator[0]).$this->trailingBackslash($terminator);
                $case->modsRaw = $this->modsAfterTerminator($line, $terminator);
                $open = null;
                $inData = true;

                continue;
            }

            // A blank or whitespace-only line ends the data block of the
            // current pattern.
            if ('' === rtrim($line, " \t\r\v\f")) {
                $inData = false;
                $events[] = ['type' => 'data', 'caseIdx' => null];

                continue;
            }

            if ($inData) {
                $events[] = ['type' => 'data', 'caseIdx' => null];

                continue;
            }

            $first = $line[0];

            if ('#' === $first) {
                $this->handleCommand($line);
                $events[] = ['type' => 'command', 'caseIdx' => null];

                continue;
            }

            if ($this->isPatternDelimiter($first)) {
                $caseIdx = \count($cases);
                $case = new Pcre2ParsedCase(
                    $index + 1,
                    $first,
                    $this->defaultPatternModifiers,
                    $this->explicitNewlineCommand,
                );
                $cases[] = $case;
                $events[] = ['type' => 'pattern', 'caseIdx' => $caseIdx];

                $terminator = $this->findTerminator($line, $first, 1);

                if (null === $terminator) {
                    $case->body = substr($line, 1);
                    $open = $caseIdx;

                    continue;
                }

                $case->body = substr($line, 1, $terminator[0] - 1).$this->trailingBackslash($terminator);
                $case->modsRaw = $this->modsAfterTerminator($line, $terminator);
                $inData = true;

                continue;
            }

            // Anything else outside a data block (an indented line, a "\="
            // modifier line) is not a pattern.
            $events[] = ['type' => 'data', 'caseIdx' => null];
        }

        return [$cases, $events];
    }

    /**
     * Walks the testoutput in lockstep with the echoed testinput lines and
     * attaches every output-only line to the case it belongs to. Rejection
     * lines only count while no subject of the case has been echoed yet,
     * which is where pcre2test reports compilation failures.
     *
     * @param list<string>                                 $inputLines
     * @param list<array{type: string, caseIdx: int|null}> $events      one per input line
     * @param list<Pcre2ParsedCase>                        $cases
     * @param list<string>                                 $outputLines
     *
     * @return list<Pcre2ParsedCase> the cases, with their output lines attached
     */
    private function pairOutput(array $inputLines, array $events, array $cases, array $outputLines): array
    {
        $inputCount = \count($inputLines);
        $current = null;
        $sawSubject = array_fill(0, \count($cases), false);
        $inputIndex = 0;

        foreach ($outputLines as $outputLine) {
            if ($inputIndex < $inputCount && $outputLine === $inputLines[$inputIndex]) {
                $event = $events[$inputIndex];
                $eventCase = $event['caseIdx'];

                if ('pattern' === $event['type']) {
                    $current = $eventCase;
                } elseif ('data' === $event['type'] && null !== $current) {
                    $sawSubject[$current] = true;
                }

                $inputIndex++;

                continue;
            }

            if (null !== $current && !$sawSubject[$current]) {
                $cases[$current]->patternOutputLines[] = $outputLine;
            }
        }

        return $cases;
    }

    /**
     * Tells whether a byte may open a pattern line.
     */
    private function isPatternDelimiter(string $byte): bool
    {
        return \in_array($byte, self::DELIMITERS, true) || isset(self::BRACKET_CLOSERS[$byte]);
    }

    /**
     * Finds the terminating delimiter of a pattern in one line, applying the
     * rules of pcre2test: an escaped delimiter stays part of the pattern, an
     * unescaped delimiter immediately followed by a backslash terminates and
     * hands that backslash to the pattern, and bracket delimiters close at
     * the balanced match.
     *
     * @param string $delimiter the opening delimiter byte
     * @param int    $from      first offset to scan (1 on a pattern's first line)
     *
     * @return array{0: int, 1: bool}|null terminator position and whether the
     *                                     following backslash joins the pattern
     */
    private function findTerminator(string $line, string $delimiter, int $from): ?array
    {
        if (isset(self::BRACKET_CLOSERS[$delimiter])) {
            $position = $this->findBracketTerminator($line, $delimiter, self::BRACKET_CLOSERS[$delimiter], $from);

            return null === $position ? null : [$position, false];
        }

        $length = \strlen($line);

        // Escapes are consumed pairwise from the left, exactly as pcre2test
        // does, so the parity of a backslash run is settled before a
        // delimiter is considered: in "\d\/\d" the "/" is escaped, and the
        // backslash after it opens the next escape.
        for ($k = $from; $k < $length; $k++) {
            $char = $line[$k];

            if ('\\' === $char) {
                $k++;

                continue;
            }

            if ($delimiter === $char) {
                return [$k, $k + 1 < $length && '\\' === $line[$k + 1]];
            }
        }

        return null;
    }

    /**
     * Bracket delimiters close at the delimiter that balances the opener,
     * honouring backslash escapes.
     */
    private function findBracketTerminator(string $line, string $opener, string $closer, int $from): ?int
    {
        $depth = 1;
        $length = \strlen($line);

        for ($k = $from; $k < $length; $k++) {
            $char = $line[$k];

            if ('\\' === $char) {
                $k++;

                continue;
            }

            if ($char === $opener) {
                $depth++;
            } elseif ($char === $closer && 0 === --$depth) {
                return $k;
            }
        }

        return null;
    }

    /**
     * The modifier text after a terminator, skipping the backslash when the
     * terminator handed one to the pattern.
     *
     * @param array{0: int, 1: bool} $terminator
     */
    private function modsAfterTerminator(string $line, array $terminator): string
    {
        return substr($line, $terminator[0] + ($terminator[1] ? 2 : 1));
    }

    /**
     * The backslash a terminator hands to the pattern, if any.
     *
     * @param array{0: int, 1: bool} $terminator
     */
    private function trailingBackslash(array $terminator): string
    {
        return $terminator[1] ? '\\' : '';
    }

    /**
     * Handles a "#" command line: "#pattern" and "#newline" change
     * extraction state, and "#newline_default" resets the newline state
     * without skipping anything.
     */
    private function handleCommand(string $line): void
    {
        if (1 === preg_match('/^#pattern\s+(.+)$/', $line, $matches)) {
            foreach ($this->splitModifierItems($matches[1]) as $item) {
                if (str_starts_with($item, '-')) {
                    $this->defaultPatternModifiers = array_values(array_diff(
                        $this->defaultPatternModifiers,
                        [substr($item, 1)],
                    ));

                    continue;
                }

                $this->defaultPatternModifiers[] = $item;
            }

            return;
        }

        if (1 === preg_match('/^#newline\s+/', $line)) {
            $this->explicitNewlineCommand = true;

            return;
        }

        if (str_starts_with($line, '#newline_default')) {
            $this->explicitNewlineCommand = false;
        }
    }

    /**
     * Splits a modifier list into its comma-separated items.
     *
     * @return list<string>
     */
    private function splitModifierItems(string $list): array
    {
        $items = [];

        foreach (explode(',', $list) as $item) {
            $item = trim($item);

            if ('' !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Builds the canonical case row from one parsed case.
     *
     * @return array<string, mixed> the canonical case row
     */
    private function buildRow(Pcre2ParsedCase $case, string $name): array
    {
        $modifierItems = array_merge($case->defaultModifiers, $this->splitModifierItems($case->modsRaw));
        [$flags, $skips] = $this->classifyPatternModifiers($modifierItems);

        $closer = self::BRACKET_CLOSERS[$case->delimiter] ?? $case->delimiter;
        $length = \strlen($case->delimiter.$case->body.$closer);

        if ($length > self::MAX_RECONSTRUCTED_LENGTH) {
            $skips[] = [
                'category' => 'length-limit',
                'reason' => \sprintf(
                    'reconstructed pattern is %d characters, over the %d limit',
                    $length,
                    self::MAX_RECONSTRUCTED_LENGTH,
                ),
            ];
        }

        if ($case->newlineCommand) {
            $skips[] = [
                'category' => 'newline-command',
                'reason' => 'an explicit #newline command set the newline convention for this case',
            ];
        }

        // pcre2test hands the backslash after a closing delimiter to the
        // pattern ("/abc/\\"), so a body can end in an odd backslash run. No
        // PHP pattern string can carry that body: PHP reads the trailing
        // backslash as escaping whatever closing delimiter follows it.
        $trailingBackslashes = \strlen($case->body) - \strlen(rtrim($case->body, '\\'));

        if (1 === $trailingBackslashes % 2) {
            $skips[] = [
                'category' => 'php-inexpressible',
                'reason' => 'pattern body ends with an unescaped backslash, which no PHP pattern delimiter can close',
            ];
        }

        [$verdict, $offset, $error, $code, $outputSkips] = $this->readOutputVerdict($case->patternOutputLines);
        $skips = array_merge($skips, $outputSkips);

        // PCRE 8-bit pattern bodies are byte strings and may legally hold
        // bytes that are not valid UTF-8. The committed JSON fixture is the
        // canonical serialization of the case set, and it cannot carry such
        // bytes, so those cases are skipped rather than stored lossy, and
        // their body is dropped from the row (the vendored file still has
        // it, under the case id's line number).
        $bodyIsBinary = 1 !== preg_match('//u', $case->body);

        if ($bodyIsBinary) {
            $skips[] = [
                'category' => 'ambiguous',
                'reason' => 'pattern body contains bytes that cannot round-trip through the JSON case fixture',
            ];
        }

        $skip = null;

        foreach (self::SKIP_ORDER as $category) {
            foreach ($skips as $candidate) {
                if ($category === $candidate['category']) {
                    $skip = $candidate;

                    break 2;
                }
            }
        }

        $flags = array_values(array_unique($flags));
        sort($flags, \SORT_STRING);

        return [
            'id' => $name.':'.$case->line,
            'pattern' => $bodyIsBinary ? '' : $case->body,
            'delimiter' => $case->delimiter,
            'flags' => implode('', $flags),
            'verdict' => null !== $skip ? null : $verdict,
            'offset' => null !== $skip ? null : $offset,
            'error' => null !== $skip ? null : $error,
            'pcre2Code' => null !== $skip ? null : $code,
            'phpOverride' => null,
            'floor' => null,
            'skipCategory' => $skip['category'] ?? null,
            'skipReason' => $skip['reason'] ?? null,
        ];
    }

    /**
     * Classifies the modifier items of a pattern line into PHP flags and
     * skip reasons.
     *
     * @param list<string> $items
     *
     * @return array{0: list<string>, 1: list<array{category: string, reason: string}>}
     */
    private function classifyPatternModifiers(array $items): array
    {
        $flags = [];
        $skips = [];

        foreach ($items as $index => $item) {
            $name = $item;
            $hasValue = false;

            $equals = strpos($item, '=');

            if (false !== $equals) {
                $name = substr($item, 0, $equals);
                $hasValue = true;
            }

            // Only the first item of a list may be a run of one-letter
            // abbreviations; every other item must be a long modifier name.
            if (0 === $index && !$hasValue && !self::isKnownLongName($name)) {
                foreach ($this->splitAbbreviations($item) as $abbreviation) {
                    [$letterFlags, $letterSkips] = $this->classifyLongName($abbreviation);
                    $flags = array_merge($flags, $letterFlags);
                    $skips = array_merge($skips, $letterSkips);
                }

                continue;
            }

            [$itemFlags, $itemSkips] = $this->classifyLongName($name, $item);
            $flags = array_merge($flags, $itemFlags);
            $skips = array_merge($skips, $itemSkips);
        }

        return [$flags, $skips];
    }

    /**
     * Tells whether a name is one of the committed long modifier names.
     */
    private static function isKnownLongName(string $name): bool
    {
        return isset(self::modifierMap()[$name])
            || \in_array($name, self::droppedModifiers(), true)
            || \in_array($name, self::inexpressibleModifiers(), true)
            || \in_array($name, self::newerThanFloorModifiers(), true)
            || \in_array($name, self::apiModifiers(), true)
            || 'newline' === $name
            || str_starts_with($name, 'substitute')
            || str_starts_with($name, 'convert');
    }

    /**
     * Splits a run of one-letter abbreviations; "xx" is the two-letter
     * abbreviation of extended_more and is taken first.
     *
     * @return list<string>
     */
    private function splitAbbreviations(string $run): array
    {
        $abbreviations = [];

        while ('' !== $run) {
            if (str_starts_with($run, 'xx')) {
                $abbreviations[] = 'xx';
                $run = substr($run, 2);

                continue;
            }

            $abbreviations[] = $run[0];
            $run = substr($run, 1);
        }

        return $abbreviations;
    }

    /**
     * Classifies one modifier name (or abbreviation) into flags and skips.
     *
     * @return array{0: list<string>, 1: list<array{category: string, reason: string}>}
     */
    private function classifyLongName(string $name, ?string $rawItem = null): array
    {
        $raw = $rawItem ?? $name;
        $map = self::modifierMap();

        if (isset($map[$name])) {
            return [[$map[$name]], []];
        }

        if ('newline' === $name) {
            return [[], [
                ['category' => 'newline-command', 'reason' => \sprintf('newline convention override: %s', $raw)],
            ]];
        }

        if (\in_array($name, self::droppedModifiers(), true) || str_starts_with($name, 'substitute')) {
            return [[], []];
        }

        if (\in_array($name, self::inexpressibleModifiers(), true) || str_starts_with($name, 'convert')) {
            return [[], [[
                'category' => 'modifier-inexpressible',
                'reason' => \sprintf('compile-visible modifier with no PHP pattern-modifier equivalent: %s', $name),
            ]]];
        }

        if (\in_array($name, self::newerThanFloorModifiers(), true)) {
            return [[], [[
                'category' => 'newer-than-floor',
                'reason' => \sprintf('modifier introduced after the PCRE2 floor %s: %s', self::PCRE2_FLOOR, $name),
            ]]];
        }

        if (\in_array($name, self::apiModifiers(), true)) {
            return [[], [['category' => 'pcre2test-api', 'reason' => \sprintf('pcre2test API modifier: %s', $name)]]];
        }

        return [[], [[
            'category' => 'ambiguous',
            'reason' => \sprintf('unrecognized pcre2test modifier item: "%s"', $raw),
        ]]];
    }

    /**
     * Modifiers that drive pcre2test machinery PHP does not expose, or that
     * rewrite the pattern text before compiling it (hex, expand).
     *
     * @return list<string>
     */
    private static function apiModifiers(): array
    {
        return [
            'expand',
            'find_limits',
            'hex',
            'null_context',
            'null_pattern',
            'null_substitute_match_data',
            'pop',
            'popcopy',
            'push',
            'pushcopy',
            'pushtablescopy',
            'repmark',
            'stackguard',
            'use_length',
            'zero_terminate',
        ];
    }

    /**
     * Reads the verdict of one case from the output lines that appeared
     * between its pattern echo and its first subject echo. Bytecode dumps
     * (B, bincode, fullbincode, debug) sit between two dash rules and are
     * never read: a literal in the dump can spell anything. A "** ..."
     * diagnostic there means pcre2test did not compile the pattern as
     * written, so no verdict can be read.
     *
     * @param list<string> $lines
     *
     * @return array{0: string|null, 1: int|null, 2: string|null, 3: int|null, 4: list<array{category: string, reason: string}>}
     */
    private function readOutputVerdict(array $lines): array
    {
        $inDump = false;

        foreach ($lines as $line) {
            if (1 === preg_match('/^-{10,}$/', $line)) {
                $inDump = !$inDump;

                continue;
            }

            if ($inDump) {
                continue;
            }

            if (1 === preg_match('/^Failed: error (\d+) at offset (\d+): (.*)$/', $line, $matches)) {
                $code = (int) $matches[1];

                if ($code > 0) {
                    return ['reject', (int) $matches[2], $matches[3], $code, []];
                }

                return [null, null, null, null, self::ambiguousOutputSkip($line)];
            }

            if (str_starts_with($line, 'Failed:') || 1 === preg_match('/^Error /', $line)) {
                return [null, null, null, null, self::ambiguousOutputSkip($line)];
            }

            if (1 === preg_match('/^\s*\*\*\s+Test skipped/', $line)) {
                return [null, null, null, null, [[
                    'category' => 'engine-skipped',
                    'reason' => \sprintf('testoutput skipped this case: %s', trim($line)),
                ]]];
            }

            if (1 === preg_match('/^\s*\*\* /', $line)) {
                return [null, null, null, null, [[
                    'category' => 'ambiguous',
                    'reason' => \sprintf('pcre2test diagnostic instead of a compile verdict: "%s"', trim($line)),
                ]]];
            }
        }

        return ['accept', null, null, null, []];
    }

    /**
     * @return list<array{category: string, reason: string}>
     */
    private static function ambiguousOutputSkip(string $line): array
    {
        return [[
            'category' => 'ambiguous',
            'reason' => \sprintf('unparseable testoutput rejection line: "%s"', trim($line)),
        ]];
    }
}
