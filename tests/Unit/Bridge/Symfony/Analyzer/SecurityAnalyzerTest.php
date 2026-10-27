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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Analyzer;

use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\AnalysisIssue;
use PHPRegex\Symfony\Analyzer\AnalysisNotice;
use PHPRegex\Symfony\Analyzer\ReportSection;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The security sections of regex:analyze, as the analyzer builds them from
 * the access_control rules and the firewalls of a security file.
 *
 * Symfony's access map tries the rules in order and stops at the first
 * whose path, the regex "{".$path."}", matches: the engine says which rule
 * takes each example below.
 */
final class SecurityAnalyzerTest extends TestCase
{
    use TemporaryProject;

    private const ACCESS_CONTROL = "security:\n"
        ."    access_control:\n"
        ."        - { path: ^/admin, roles: PUBLIC_ACCESS }\n"
        ."        - { path: ^/admin/users, roles: ROLE_ADMIN }\n"
        ."        - { path: ^/shop, roles: ROLE_A }\n"
        ."        - { path: ^/shop/cart, roles: ROLE_B }\n"
        ."        - { path: '^/a[0-5]', roles: ROLE_A }\n"
        ."        - { path: '^/a[3-9]', roles: ROLE_B }\n"
        ."        - { path: ^/legacy, allow_if: \"is_granted('X')\" }\n"
        ."        - { path: ^/office, ips: [127.0.0.1] }\n"
        ."        - { roles: ROLE_USER }\n"
        ."        - { path: ^/api, host: 'admin{', roles: ROLE_API }\n"
        ."        - { request_matcher: app.matcher, roles: ROLE_M }\n";

    private const FIREWALLS = "security:\n"
        ."    firewalls:\n"
        ."        exp: { pattern: '^/api/(a+)+$' }\n"
        ."        deg3: { pattern: '^/x/a*a*a*b$' }\n"
        ."        deg2: { pattern: '^/y/a*a*b$' }\n"
        ."        matcher: { request_matcher: app.fw }\n"
        ."        broken: { pattern: '^/z/(' }\n"
        ."        plain: { pattern: ^/static }\n";

    #[Test]
    public function test_the_section_warns_when_there_is_no_security_configuration(): void
    {
        $analyzer = self::analyzer();

        $sections = $analyzer->analyze(new AnalysisContext(null, null));

        $this->assertCount(1, $sections);
        $this->assertSame('security_access', $sections[0]->id);
        $this->assertSame('Security Access Control', $sections[0]->title);
        $this->assertSame([['warn', 'No security configuration found.']], self::notices($sections[0]->summary));
        $this->assertSame('security', $analyzer->getId());
        $this->assertSame('Security', $analyzer->getLabel());
    }

    #[Test]
    public function test_the_engine_sends_each_example_to_the_rule_the_report_names(): void
    {
        $this->assertSame(1, preg_match('{^/admin}', '/admin/users'), 'The PUBLIC_ACCESS rule takes /admin/users first.');
        $this->assertSame(1, preg_match('{^/shop}', '/shop/cart'));
        $this->assertSame(1, preg_match('{^/a[0-5]}', '/a3'));
        $this->assertSame(1, preg_match('{^/a[3-9]}', '/a3'));
        $this->assertSame(1, preg_match('{^/a[3-9]}', '/a9'), 'The later rule keeps paths of its own.');
        $this->assertSame(0, preg_match('{^/a[0-5]}', '/a9'));
        $this->assertSame(1, preg_match('{^/a[0-5]}', '/a1'), 'The earlier rule keeps paths of its own.');
        $this->assertSame(0, preg_match('{^/a[3-9]}', '/a1'));
    }

    /**
     * A public rule before a restricted one it covers is critical, a rule
     * covering a later one fails, a partial overlap warns; the overlaps are
     * listed only when asked for, and counted either way.
     */
    #[Test]
    #[DataProvider('provideOverlapModes')]
    public function test_the_access_summary_counts_each_kind_of_conflict(bool $includeOverlaps, string $mode, string $overlapNotice, int $issues): void
    {
        $section = $this->sections(self::ACCESS_CONTROL, includeOverlaps: $includeOverlaps)[0];

        $this->assertSame(['Rules' => 11, 'Mode' => $mode, 'Shadowed' => 3, 'Overlaps' => 1, 'Critical' => 1], $section->meta);
        $this->assertSame([
            ['critical', '1 critical shadowing conflicts detected.'],
            ['fail', '3 shadowed rules detected.'],
            ['warn', '1 overlapping rules detected. '.$overlapNotice],
        ], self::notices($section->summary));
        $this->assertCount($issues, $section->issues);
    }

    /**
     * @return iterable<string, array{includeOverlaps: bool, mode: string, overlapNotice: string, issues: int}>
     */
    public static function provideOverlapModes(): iterable
    {
        yield 'shadowed only' => ['includeOverlaps' => false, 'mode' => 'Shadowed only', 'overlapNotice' => 'Use --show-overlaps to include them.', 'issues' => 3];
        yield 'with the overlaps' => ['includeOverlaps' => true, 'mode' => 'Shadowed + overlaps', 'overlapNotice' => 'Listed below.', 'issues' => 4];
    }

    #[Test]
    public function test_each_access_conflict_is_an_issue_naming_both_rules(): void
    {
        $section = $this->sections(self::ACCESS_CONTROL, includeOverlaps: true)[0];

        $this->assertSame([
            ['shadowed', 'critical', "#1 (security.yaml:3) \u{21B3} #2 (security.yaml:4)", '/admin/users', []],
            ['shadowed', 'fail', "#3 (security.yaml:5) \u{21B3} #4 (security.yaml:6)", '/shop/cart', []],
            ['overlap', 'warn', "#5 (security.yaml:7) \u{21B3} #6 (security.yaml:8)", '/a3', []],
            ['shadowed', 'fail', "#9 (security configuration) \u{21B3} #10 (security.yaml:12)", '/api', ['Rule has no path; it matches all requests.', 'Host restrictions are not evaluated.']],
        ], array_map(self::issue(...), $section->issues));
        $this->assertSame(['Rule', '#1 security.yaml:3  path=^/admin  roles=PUBLIC_ACCESS'], [$section->issues[0]->details[0]->label, $section->issues[0]->details[0]->value]);
        $this->assertSame(['Conflict', '#2 security.yaml:4  path=^/admin/users  roles=ROLE_ADMIN'], [$section->issues[0]->details[1]->label, $section->issues[0]->details[1]->value]);
        $this->assertSame([
            'Reorder access_control: move rule #2 (security.yaml:4) before #1.',
            'Narrow the PUBLIC_ACCESS rule or move the restrictive rule above it.',
            'Reorder access_control: move rule #4 (security.yaml:6) before #3.',
            'Reorder access_control: move rule #10 (security.yaml:12) before #9.',
        ], $section->suggestions);
    }

    /**
     * What the analysis does not evaluate is a warning each: the rules it
     * left out, and those whose allow_if, IPs, missing path or host it did
     * not read.
     */
    #[Test]
    public function test_what_the_access_analysis_does_not_evaluate_is_warned_about(): void
    {
        $section = $this->sections(self::ACCESS_CONTROL)[0];

        $this->assertSame([
            ['warn', '1 access_control rules skipped due to unsupported regex features.'],
            ['warn', '1 rules use allow_if; conditions are not evaluated during analysis.'],
            ['warn', '1 rules use IP restrictions; IPs are not evaluated during analysis.'],
            ['warn', '1 rules have no path and match all requests.'],
            ['warn', '1 rules use host restrictions that could not be analyzed.'],
        ], self::notices($section->warnings));
    }

    /**
     * The debug rows: the counts of the comparison, and why each rule left
     * out was left out. Ten rules are compared: forty-five pairs.
     */
    #[Test]
    public function test_the_access_debug_rows_count_the_pairs_and_name_the_skipped_rules(): void
    {
        $debug = $this->sections(self::ACCESS_CONTROL, debug: true)[0]->debug;

        $this->assertSame(45, $debug['Pairs total'] ?? null);
        $this->assertSame(0, $debug['Pairs filtered (methods)'] ?? null);
        $this->assertSame(0, $debug['Host checks'] ?? null);
        $this->assertSame(0, $debug['Pairs skipped (complexity)'] ?? null);
        $this->assertArrayHasKey('Path intersections', $debug);
        $this->assertArrayHasKey('Path subset checks', $debug);
        $this->assertArrayHasKey('Host intersections', $debug);
        $this->assertArrayHasKey('Carve-outs (narrower rule first, not reported)', $debug);
        $this->assertSame('request_matcher rules cannot be analyzed.', $debug['Skipped rule #11 (security.yaml:13)'] ?? null);
    }

    /**
     * Each firewall regex at or above the threshold is an issue, critical,
     * failing or warning as the analysis grades it, with its verdict, the
     * part that backtracks and the attack; a firewall left out is a
     * warning, and the debug rows say why.
     */
    #[Test]
    public function test_each_risky_firewall_regex_is_an_issue_graded_by_its_severity(): void
    {
        $sections = $this->sections(self::FIREWALLS, threshold: RedosSeverity::Medium, debug: true);
        $this->assertCount(2, $sections);
        $firewall = $sections[1];

        $this->assertSame('security_firewall', $firewall->id);
        $this->assertSame('Security Firewall Regex', $firewall->title);
        $this->assertSame(['Firewalls' => 6, 'ReDoS >=' => 'medium', 'Flagged' => 3], $firewall->meta);
        $this->assertSame([['fail', '3 firewall regex patterns exceed the ReDoS threshold.']], self::notices($firewall->summary));
        $this->assertSame([['warn', '2 firewalls skipped during ReDoS analysis.']], self::notices($firewall->warnings));
        $this->assertSame([
            ['redos', 'critical', 'exp (security.yaml:3)'],
            ['redos', 'fail', 'deg3 (security.yaml:4)'],
            ['redos', 'warn', 'deg2 (security.yaml:5)'],
        ], array_map(static fn (AnalysisIssue $issue): array => [$issue->kind, $issue->severity->value, $issue->title], $firewall->issues));

        $details = [];
        foreach ($firewall->issues[1]->details as $detail) {
            $details[$detail->label] = [$detail->value, $detail->kind];
        }
        $this->assertSame([
            'Severity' => ['HIGH', 'text'],
            'Verdict' => ['Polynomial backtracking, degree 3 (proven)', 'text'],
            'Score' => ['8', 'text'],
            'Pattern' => ['^/x/a*a*a*b$', 'pattern'],
            'Vulnerable' => ['a*a*', 'pattern'],
            'Trigger' => ['adjacent quantifiers', 'example'],
            'Attack' => [(string) Regex::create()->redos('#^/x/a*a*a*b$#')->witness?->render(), 'text'],
        ], $details);

        $this->assertSame('request_matcher patterns cannot be analyzed.', $firewall->debug['Skipped firewall matcher (security.yaml:6)'] ?? null);
        $this->assertStringContainsString('Expected )', (string) ($firewall->debug['Skipped firewall broken (security.yaml:7)'] ?? ''));
    }

    /**
     * The engine agrees the degree-3 firewall regex works hard on the
     * attack: under PHP's default backtrack limit, the attack of 2,000
     * letters exhausts it, where a linear regex reads the same subject.
     * Oracle (PCRE2 10.49, JIT off): about 20,000 steps at 200 letters,
     * 80,000 at 400, past a million at 2,000.
     */
    #[Test]
    public function test_the_engine_gives_up_on_the_degree_three_attack(): void
    {
        $subject = '/x/'.str_repeat('a', 2000).'!b';
        $saved = (string) ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '1000000');
            $attacked = [@preg_match('{(*NO_JIT)^/x/a*a*a*b$}', $subject), preg_last_error()];
            $linear = [@preg_match('{(*NO_JIT)^/x/a*b$}', $subject), preg_last_error()];
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
        }

        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], $attacked);
        $this->assertSame([0, \PREG_NO_ERROR], $linear);
    }

    /**
     * Under the default threshold the medium firewall regex passes, and
     * without any risky regex the section passes.
     */
    #[Test]
    public function test_the_firewall_section_passes_what_stays_under_the_threshold(): void
    {
        $firewall = $this->sections("security:\n    firewalls:\n        deg2: { pattern: '^/y/a*a*b$' }\n        plain: { pattern: ^/static }\n")[1];

        $this->assertSame(['Firewalls' => 2, 'ReDoS >=' => 'high', 'Flagged' => 0], $firewall->meta);
        $this->assertSame([['pass', 'No risky firewall regex detected.']], self::notices($firewall->summary));
        $this->assertSame([], $firewall->warnings);
        $this->assertSame([], $firewall->issues);
        $this->assertSame([], $firewall->debug);
    }

    #[Test]
    public function test_skipping_the_firewalls_leaves_the_access_section_alone(): void
    {
        $sections = $this->sections(self::FIREWALLS."    access_control:\n        - { path: ^/admin, roles: ROLE_ADMIN }\n", skipFirewalls: true);

        $this->assertSame(['security_access'], array_map(static fn (ReportSection $section): string => $section->id, $sections));
        $this->assertSame([['pass', 'No access_control conflicts detected.']], self::notices($sections[0]->summary));
    }

    /**
     * @return array<int, ReportSection>
     */
    private function sections(
        string $yaml,
        bool $includeOverlaps = false,
        RedosSeverity $threshold = RedosSeverity::High,
        bool $skipFirewalls = false,
        bool $debug = false,
    ): array {
        $project = $this->makeProject(['security.yaml' => $yaml]);

        return self::analyzer()->analyze(new AnalysisContext(
            $project,
            null,
            $includeOverlaps,
            [$project.'/security.yaml'],
            $threshold,
            $skipFirewalls,
            $debug,
        ));
    }

    private static function analyzer(): SecurityAnalyzer
    {
        return new SecurityAnalyzer(
            new SecurityConfigExtractor(),
            new SecurityConfigLocator(),
            new SecurityAccessControlAnalyzer(Regex::create()),
            new SecurityFirewallAnalyzer(Regex::create()),
        );
    }

    /**
     * @param array<AnalysisNotice> $notices
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function notices(array $notices): array
    {
        return array_values(array_map(
            static fn (AnalysisNotice $notice): array => [$notice->severity->value, $notice->message],
            $notices,
        ));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string|null, 4: array<string>}
     */
    private static function issue(AnalysisIssue $issue): array
    {
        $example = null;
        foreach ($issue->details as $detail) {
            if ('Example' === $detail->label) {
                self::assertSame('example', $detail->kind);
                $example = $detail->value;
            }
        }

        return [$issue->kind, $issue->severity->value, $issue->title, $example, $issue->notes];
    }
}
