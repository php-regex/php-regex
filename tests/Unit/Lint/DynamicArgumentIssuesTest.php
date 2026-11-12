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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\InlineIgnores;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern argument that holds a part known at run time only is one no
 * rule runs on. A run asked to report them says so where each stands: an
 * info, under an identifier of its state, on a result that holds no
 * pattern and gives the argument as the source writes it. A run that is
 * not asked reports none. An argument whose patterns are all known is
 * never reported: its patterns are.
 *
 * An info fails no run, and an inline marker silences these as it
 * silences any issue of its line.
 */
final class DynamicArgumentIssuesTest extends TestCase
{
    private const FILE = 'src/a.php';

    /**
     * Five calls, the fourth line holding two: the markers of the lines 3,
     * 4 and 6 silence, whole or by name, the arguments built at run time
     * that stand there.
     */
    private const MARKED = <<<'PHP'
        <?php

        preg_match($a, $s); // @regex-ignore
        preg_match($b, $s); // @regex-ignore regex.lint.source.dynamic
        preg_match('/' . $c . '/', $s); // @regex-ignore regex.lint.source.dynamicPart
        preg_match($d, $s); preg_match('/^' . $w . '$/', $s); // @regex-ignore regex.lint.source.dynamic
        preg_match($e, $s);

        PHP;

    /**
     * @return iterable<string, array{state: string, issueId: string, message: string}>
     */
    public static function provideStatesNotVerified(): iterable
    {
        yield 'nothing static' => [
            'state' => 'opaque',
            'issueId' => 'regex.lint.source.dynamic',
            'message' => 'The pattern is built at run time: it is not verified.',
        ];
        yield 'a static pattern around a part built at run time' => [
            'state' => 'raw_shell',
            'issueId' => 'regex.lint.source.dynamicPart',
            'message' => 'The pattern holds a part built at run time: it is not verified.',
        ];
        yield 'a static pattern around preg_quote() texts' => [
            'state' => 'quoted_shell',
            'issueId' => 'regex.lint.source.quotedPart',
            'message' => 'The pattern holds a preg_quote() text: it is not verified.',
        ];
    }

    #[Test]
    #[DataProvider('provideStatesNotVerified')]
    public function test_an_argument_not_verified_is_an_info_where_it_stands(string $state, string $issueId, string $message): void
    {
        $expression = "'/^été' . \$word . '$/u'";
        $report = $this->lint([PatternOccurrence::argument($state, self::FILE, 7, 22, 211, 'preg_match()', $expression)], self::asked());

        $this->assertCount(1, $report->results);
        $issues = array_values($report->results)[0]['issues'];
        $this->assertCount(1, $issues);
        $this->assertInstanceOf(LintIssue::class, $issues[0]);
        $this->assertFalse($issues[0]->severity->failsRun());
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'infos' => 1], ['errors' => $report->stats['errors'], 'warnings' => $report->stats['warnings'], 'infos' => $report->stats['infos'] ?? 0]);

        // A formatter given no target writes none: the stats and the results are what is checked here.
        $document = self::document($report);
        JsonContract::assertShape('lint.stats', $document['stats']);
        JsonContract::assertShape('lint.results', $document['results']);
        $result = JsonContract::asArray(JsonContract::asArray($document['results'])[0] ?? null);
        $this->assertSame(
            ['file' => self::FILE, 'line' => 7, 'column' => 22, 'file_offset' => 211, 'pattern' => null, 'expression' => $expression],
            ['file' => $result['file'], 'line' => $result['line'], 'column' => $result['column'], 'file_offset' => $result['file_offset'], 'pattern' => $result['pattern'], 'expression' => $result['expression']],
        );
        $this->assertSame([], $result['optimizations']);

        $issue = JsonContract::asArray(JsonContract::asArray($result['issues'])[0] ?? null);
        $this->assertSame(
            ['severity' => 'info', 'file' => self::FILE, 'line' => 7, 'column' => 22, 'file_offset' => 211, 'issue_id' => $issueId, 'message' => $message, 'validation' => null, 'analysis' => null],
            [
                'severity' => $issue['severity'], 'file' => $issue['file'], 'line' => $issue['line'], 'column' => $issue['column'], 'file_offset' => $issue['file_offset'],
                'issue_id' => $issue['issue_id'], 'message' => $issue['message'], 'validation' => $issue['validation'], 'analysis' => $issue['analysis'],
            ],
        );
        $this->assertIsString($issue['docs_url']);
        $this->assertStringStartsWith('https://php-regex.com/reference/rules/#', $issue['docs_url']);
    }

    /**
     * A run that does not ask reports no argument: it counts them.
     */
    #[Test]
    public function test_a_run_that_does_not_ask_reports_no_argument(): void
    {
        $report = $this->lint(ArgumentCarriers::of(['quoted_shell' => 2, 'raw_shell' => 3, 'opaque' => 4], self::FILE), new LintRequest(['.'], [], 0, checkOptimizations: false));

        $this->assertSame([], $report->results);
        $this->assertSame(0, $report->stats['infos'] ?? 0);
        $this->assertSame(['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 2, 'raw_shell' => 3, 'opaque' => 4], ArgumentCarriers::counts($report->stats));
    }

    /**
     * An argument whose patterns are all known is not one of these: asked
     * or not, nothing is reported of it. The counts are those of a run
     * that does not ask.
     */
    #[Test]
    public function test_an_argument_whose_patterns_are_known_is_never_reported(): void
    {
        $report = $this->lint(ArgumentCarriers::of(['constant' => 2, 'finite_set' => 3], self::FILE), self::asked());

        $this->assertSame([], $report->results);
        $this->assertSame(0, $report->stats['infos'] ?? 0);
        $this->assertSame(['constant' => 2, 'finite_set' => 3, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 0], ArgumentCarriers::counts($report->stats));
    }

    /**
     * Each argument is reported, the copies of one expression included:
     * as many infos as arguments not verified, each on a result of its
     * own, in the order of the file. Reporting them changes no count of
     * the arguments.
     */
    #[Test]
    public function test_each_argument_not_verified_is_reported_once(): void
    {
        $report = $this->lint([
            PatternOccurrence::argument('opaque', self::FILE, 3, 12, 18, 'preg_match()', '$re'),
            PatternOccurrence::argument('opaque', self::FILE, 3, 32, 38, 'preg_match()', '$re'),
            PatternOccurrence::argument('constant', self::FILE, 4, 12, 58, 'preg_match()', "'/a/'"),
            new PatternOccurrence('/a/', self::FILE, 4, 'preg_match()', column: 12, fileOffset: 58),
            PatternOccurrence::argument('raw_shell', self::FILE, 5, 12, 80, 'preg_match()', "'/^' . \$w . '$/'"),
            PatternOccurrence::argument('quoted_shell', self::FILE, 9, 5, 140, 'preg_match()', "'/^'\n    . preg_quote(\$w, '/')\n    . '$/'"),
        ], self::asked());

        $this->assertSame(
            [
                [3, 12, null, '$re', ['regex.lint.source.dynamic']],
                [3, 32, null, '$re', ['regex.lint.source.dynamic']],
                [5, 12, null, "'/^' . \$w . '$/'", ['regex.lint.source.dynamicPart']],
                [9, 5, null, "'/^'\n    . preg_quote(\$w, '/')\n    . '$/'", ['regex.lint.source.quotedPart']],
            ],
            self::reported($report),
        );
        $this->assertSame(4, $report->stats['infos'] ?? null);
        $this->assertSame(['constant' => 1, 'finite_set' => 0, 'quoted_shell' => 1, 'raw_shell' => 1, 'opaque' => 2], ArgumentCarriers::counts($report->stats));
    }

    /**
     * A result that has a pattern gives it, and no expression.
     */
    #[Test]
    public function test_the_result_of_a_pattern_carries_no_expression(): void
    {
        // The engine: a{1} is a, "/a{1}/" matches "a".
        $this->assertSame(1, preg_match('/^a{1}$/', 'a'));

        $report = $this->lint([
            PatternOccurrence::argument('constant', self::FILE, 3, 12, 18, 'preg_match()', "'/a{1}/'"),
            new PatternOccurrence('/a{1}/', self::FILE, 3, 'preg_match()', column: 12, fileOffset: 18),
        ], self::asked());

        $this->assertSame([[3, 12, '/a{1}/', null, ['regex.lint.quantifier.useless']]], self::reported($report));
    }

    /**
     * A source may place a pattern where an argument that is not verified
     * starts, a list whose first item is known and whose second is not:
     * the argument is a result of its own, with its expression, and its
     * info is not listed under the pattern.
     */
    #[Test]
    public function test_an_argument_not_verified_at_the_place_of_a_pattern_is_a_result_of_its_own(): void
    {
        // The engine: a{1} is a, "/a{1}/" matches "a".
        $this->assertSame(1, preg_match('/^a{1}$/', 'a'));

        $report = $this->lint([
            PatternOccurrence::argument('opaque', self::FILE, 3, 14, 20, 'preg_replace()', '$list'),
            new PatternOccurrence('/a{1}/', self::FILE, 3, 'preg_replace()', column: 14, fileOffset: 20),
        ], self::asked());

        $this->assertSame([
            [3, 14, null, '$list', ['regex.lint.source.dynamic']],
            [3, 14, '/a{1}/', null, ['regex.lint.quantifier.useless']],
        ], self::reported($report));
    }

    /**
     * A marker silences the arguments of its line as it silences a
     * pattern: every one when it names no issue, those of the issue it
     * names otherwise. Each marker here silences something: none is
     * reported as silencing nothing.
     */
    #[Test]
    public function test_an_inline_marker_silences_the_argument_of_its_line(): void
    {
        $markers = InlineIgnores::markers(self::MARKED);
        $this->assertCount(4, $markers);

        $report = $this->lint([
            self::argumentIn(self::MARKED, 'opaque', '$a'),
            self::argumentIn(self::MARKED, 'opaque', '$b'),
            self::argumentIn(self::MARKED, 'raw_shell', "'/' . \$c . '/'"),
            self::argumentIn(self::MARKED, 'opaque', '$d'),
            self::argumentIn(self::MARKED, 'raw_shell', "'/^' . \$w . '$/'"),
            self::argumentIn(self::MARKED, 'opaque', '$e'),
            PatternOccurrence::scanned(self::FILE, $markers),
        ], self::asked());

        $this->assertSame(
            [
                [6, 32, null, "'/^' . \$w . '$/'", ['regex.lint.source.dynamicPart']],
                [7, 12, null, '$e', ['regex.lint.source.dynamic']],
            ],
            self::reported($report),
        );
        $this->assertSame(0, $report->stats['errors']);
        $this->assertSame(2, $report->stats['infos'] ?? null);
        // A silenced argument is still an argument of the run.
        $this->assertSame(['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 0, 'raw_shell' => 2, 'opaque' => 4], ArgumentCarriers::counts($report->stats));
    }

    /**
     * A marker naming another issue than the one of the argument leaves it
     * reported.
     */
    #[Test]
    public function test_a_marker_naming_another_issue_leaves_the_argument_reported(): void
    {
        $source = "<?php\n\npreg_match(\$a, \$s); // @regex-ignore regex.lint.source.quotedPart\n";

        $report = $this->lint([
            self::argumentIn($source, 'opaque', '$a'),
            PatternOccurrence::scanned(self::FILE, InlineIgnores::markers($source)),
        ], new LintRequest(['.'], [], 0, checkOptimizations: false, reportUnmatchedIgnores: false, reportDynamic: true));

        $this->assertSame([[3, 12, null, '$a', ['regex.lint.source.dynamic']]], self::reported($report));
    }

    /**
     * A run whose pattern arguments are all built at run time holds no
     * pattern: it is an empty run, and reports nothing. Asked for them, it
     * reports each all the same: as many issues under each identifier as
     * it counts arguments in that state.
     */
    #[Test]
    public function test_a_run_holding_only_arguments_built_at_run_time_reports_each_when_asked(): void
    {
        $occurrences = [
            ...ArgumentCarriers::of(['quoted_shell' => 2, 'raw_shell' => 3, 'opaque' => 4], self::FILE),
            PatternOccurrence::scanned(self::FILE, []),
        ];
        $counts = ['constant' => 0, 'finite_set' => 0, 'quoted_shell' => 2, 'raw_shell' => 3, 'opaque' => 4];

        $this->assertTrue(LintService::holdsNothing($occurrences, true));
        $unasked = $this->lint($occurrences, new LintRequest(['.'], [], 0, checkOptimizations: false));
        $this->assertSame([], $unasked->results);
        $this->assertSame($counts, ArgumentCarriers::counts($unasked->stats));

        $report = $this->lint($occurrences, self::asked());

        $this->assertSame(
            ['regex.lint.source.dynamic' => 4, 'regex.lint.source.dynamicPart' => 3, 'regex.lint.source.quotedPart' => 2],
            self::tally(array_column(self::issues($report), 'issue_id')),
        );
        $this->assertCount(9, $report->results);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'infos' => 9], ['errors' => $report->stats['errors'], 'warnings' => $report->stats['warnings'], 'infos' => $report->stats['infos'] ?? 0]);
        $this->assertSame($counts, ArgumentCarriers::counts($report->stats));
    }

    /**
     * @return iterable<string, array{issueId: string, state: string, argument: string}>
     */
    public static function provideIdentifiersAMarkerMayName(): iterable
    {
        yield 'nothing static' => ['issueId' => 'regex.lint.source.dynamic', 'state' => 'opaque', 'argument' => '$a'];
        yield 'a part built at run time' => ['issueId' => 'regex.lint.source.dynamicPart', 'state' => 'raw_shell', 'argument' => "'/^' . \$a . '$/'"];
        yield 'a preg_quote() text' => ['issueId' => 'regex.lint.source.quotedPart', 'state' => 'quoted_shell', 'argument' => "'/^' . preg_quote(\$a, '/') . '$/'"];
    }

    /**
     * A run that does not ask for the arguments leaves their three checks
     * off, as it leaves off the optimizations or the complexity report: a
     * marker naming one of them, on the line of such an argument or on any
     * other, is not said to silence nothing. The run cannot tell.
     */
    #[Test]
    #[DataProvider('provideIdentifiersAMarkerMayName')]
    public function test_a_marker_naming_an_issue_of_an_argument_is_left_alone_by_a_run_that_does_not_ask(string $issueId, string $state, string $argument): void
    {
        $source = "<?php\n\npreg_match(".$argument.', $s); // @regex-ignore '.$issueId."\necho 1; // @regex-ignore ".$issueId."\n";
        $markers = InlineIgnores::markers($source);
        $this->assertCount(2, $markers);

        $report = $this->lint([
            self::argumentIn($source, $state, $argument),
            PatternOccurrence::scanned(self::FILE, $markers),
        ], new LintRequest(['.'], [], 0, checkOptimizations: false, reportUnmatchedIgnores: true));

        $this->assertSame([], self::issues($report));
        $this->assertSame(0, $report->stats['errors']);
    }

    /**
     * The check left off is passed over identifier by identifier: beside
     * it, the name of a rule that runs and reports nothing on the line is
     * still said to silence nothing.
     */
    #[Test]
    public function test_a_marker_naming_a_rule_that_runs_beside_an_issue_of_an_argument_is_unmatched_for_the_rule_alone(): void
    {
        $source = "<?php\n\npreg_match(\$a, \$s); // @regex-ignore regex.lint.source.dynamic, regex.lint.quantifier.useless\n";

        $report = $this->lint([
            self::argumentIn($source, 'opaque', '$a'),
            PatternOccurrence::scanned(self::FILE, InlineIgnores::markers($source)),
        ], new LintRequest(['.'], [], 0, checkOptimizations: false, reportUnmatchedIgnores: true));

        $this->assertSame(
            [['line' => 3, 'issue_id' => 'regex.ignore.unmatched', 'severity' => 'error', 'message' => 'The @regex-ignore marker names regex.lint.quantifier.useless, which no issue reports on line 3. Remove the identifier.']],
            self::issues($report),
        );
    }

    /**
     * @return iterable<string, array{line: string, argument: array{string, string}|null, named: string, reported: list<string>}>
     */
    public static function provideMarkersThatSilenceNothingWhenAsked(): iterable
    {
        yield 'another issue than the one of the argument' => [
            'line' => 'preg_match($a, $s);',
            'argument' => ['opaque', '$a'],
            'named' => 'regex.lint.source.quotedPart',
            'reported' => ['regex.ignore.unmatched', 'regex.lint.source.dynamic'],
        ];
        yield 'nothing static, around a part' => [
            'line' => "preg_match('/^' . \$a . '$/', \$s);",
            'argument' => ['raw_shell', "'/^' . \$a . '$/'"],
            'named' => 'regex.lint.source.dynamic',
            'reported' => ['regex.ignore.unmatched', 'regex.lint.source.dynamicPart'],
        ];
        yield 'a part built at run time, around a preg_quote() text' => [
            'line' => "preg_match('/^' . preg_quote(\$a, '/') . '$/', \$s);",
            'argument' => ['quoted_shell', "'/^' . preg_quote(\$a, '/') . '$/'"],
            'named' => 'regex.lint.source.dynamicPart',
            'reported' => ['regex.ignore.unmatched', 'regex.lint.source.quotedPart'],
        ];
        yield 'an argument whose pattern is known' => [
            'line' => "preg_match('/^a$/', \$s);",
            'argument' => ['constant', "'/^a$/'"],
            'named' => 'regex.lint.source.dynamic',
            'reported' => ['regex.ignore.unmatched'],
        ];
        yield 'a line that holds no argument' => [
            'line' => 'echo 1;',
            'argument' => null,
            'named' => 'regex.lint.source.dynamic',
            'reported' => ['regex.ignore.unmatched'],
        ];
    }

    /**
     * A run that asks runs the three checks: a marker naming one of them
     * on a line where nothing is reported under that identifier silences
     * nothing, and is reported as any such marker is. The argument of the
     * line, reported under another identifier, stays reported.
     *
     * @param array{string, string}|null $argument the state and the expression of the argument of the line
     * @param list<string>               $reported the identifiers reported on the line, sorted
     */
    #[Test]
    #[DataProvider('provideMarkersThatSilenceNothingWhenAsked')]
    public function test_a_marker_naming_an_issue_its_line_does_not_report_is_unmatched_when_asked(string $line, ?array $argument, string $named, array $reported): void
    {
        $source = "<?php\n\n".$line.' // @regex-ignore '.$named."\n";

        $report = $this->lint([
            ...(null === $argument ? [] : [self::argumentIn($source, $argument[0], $argument[1])]),
            PatternOccurrence::scanned(self::FILE, InlineIgnores::markers($source)),
        ], new LintRequest(['.'], [], 0, checkOptimizations: false, reportUnmatchedIgnores: true, reportDynamic: true));

        $issues = self::issues($report);
        $identifiers = array_column($issues, 'issue_id');
        sort($identifiers);
        $this->assertSame($reported, $identifiers);

        $unmatched = array_values(array_filter($issues, static fn (array $issue): bool => 'regex.ignore.unmatched' === $issue['issue_id']));
        $this->assertSame(
            [['line' => 3, 'issue_id' => 'regex.ignore.unmatched', 'severity' => 'error', 'message' => 'The @regex-ignore marker names '.$named.', which no issue reports on line 3. Remove the identifier.']],
            $unmatched,
        );
        $this->assertSame(1, $report->stats['errors']);
    }

    /**
     * The issues of the JSON report, in its order: the line, the
     * identifier, the level and the message of each.
     *
     * @return list<array{line: mixed, issue_id: mixed, severity: mixed, message: mixed}>
     */
    private static function issues(LintReport $report): array
    {
        $issues = [];
        foreach (JsonContract::asArray(self::document($report)['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                $issues[] = ['line' => $issue['line'] ?? null, 'issue_id' => $issue['issue_id'] ?? null, 'severity' => $issue['severity'] ?? null, 'message' => $issue['message'] ?? null];
            }
        }

        return $issues;
    }

    /**
     * How many times each identifier stands in a list, by identifier.
     *
     * @param list<mixed> $identifiers
     *
     * @return array<string, int>
     */
    private static function tally(array $identifiers): array
    {
        $tally = [];
        foreach ($identifiers as $identifier) {
            self::assertIsString($identifier);
            $tally[$identifier] = ($tally[$identifier] ?? 0) + 1;
        }
        ksort($tally);

        return $tally;
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     */
    private function lint(array $occurrences, LintRequest $request): LintReport
    {
        $service = new LintService(new AnalysisService(RegexParser::create(['cache' => null])), new PatternSourceCollection([]));

        return $service->analyze($occurrences, $request);
    }

    /**
     * The argument a source writes once, where it stands there: its line,
     * its column counted in bytes from 1 and its byte offset.
     */
    private static function argumentIn(string $source, string $state, string $expression): PatternOccurrence
    {
        self::assertSame(1, substr_count($source, $expression), $expression);
        $offset = (int) strpos($source, $expression);
        $lineStart = (int) strrpos(substr($source, 0, $offset), "\n") + 1;

        return PatternOccurrence::argument($state, self::FILE, substr_count($source, "\n", 0, $offset) + 1, $offset - $lineStart + 1, $offset, 'preg_match()', $expression);
    }

    /**
     * A run asked to report the arguments it could not verify.
     */
    private static function asked(): LintRequest
    {
        return new LintRequest(['.'], [], 0, checkOptimizations: false, reportDynamic: true);
    }

    /**
     * @return array<mixed>
     */
    private static function document(LintReport $report): array
    {
        return JsonContract::decodeDocument((new JsonFormatter())->format($report));
    }

    /**
     * The results of the JSON report: the line, the column, the pattern,
     * the expression and the identifiers of the issues of each.
     *
     * @return list<array{mixed, mixed, mixed, mixed, list<mixed>}>
     */
    private static function reported(LintReport $report): array
    {
        $reported = [];
        foreach (JsonContract::asArray(self::document($report)['results'] ?? null) as $result) {
            $result = JsonContract::asArray($result);
            self::assertArrayHasKey('expression', $result);
            $reported[] = [$result['line'], $result['column'], $result['pattern'], $result['expression'], array_column(JsonContract::asArray($result['issues']), 'issue_id')];
        }

        return $reported;
    }
}
