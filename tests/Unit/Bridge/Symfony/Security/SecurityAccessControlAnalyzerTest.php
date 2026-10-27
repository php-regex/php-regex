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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Security;

use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityAccessControlAnalyzerTest extends TestCase
{
    #[Test]
    public function test_detects_critical_shadowing(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml';
        $extractor = new SecurityConfigExtractor();
        $result = $extractor->extract($path);

        $analyzer = new SecurityAccessControlAnalyzer(Regex::create());
        $report = $analyzer->analyze($result['accessControl']);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['critical']);
        $this->assertCount(1, $report->conflicts);
        $this->assertSame('shadowed', $report->conflicts[0]['type']);
        $this->assertSame('critical', $report->conflicts[0]['severity']);
    }

    /**
     * Symfony matches a host with preg_match('{'.$host.'}i', ...): two hosts
     * that differ in case only overlap, and the second rule is shadowed.
     */
    #[Test]
    public function test_hosts_are_compared_without_case(): void
    {
        $rules = [
            [
                'file' => 'security.yaml',
                'line' => 10,
                'path' => '^/admin',
                'host' => '^ADMIN\\.example\\.com$',
                'roles' => ['PUBLIC_ACCESS'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
            [
                'file' => 'security.yaml',
                'line' => 12,
                'path' => '^/admin',
                'host' => '^admin\\.example\\.com$',
                'roles' => ['ROLE_ADMIN'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
        ];

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame([], $report->skippedRules);
    }

    /**
     * A rule whose every request an earlier rule of the same roles takes
     * first adds nothing: it is suggested for removal, located as the
     * formatter writes it, or as file and line when the formatter gives
     * nothing.
     */
    #[Test]
    public function test_a_rule_an_earlier_rule_of_the_same_roles_covers_is_suggested_for_removal(): void
    {
        // Oracle: Symfony matches the path with preg_match('{'.$path.'}'): the first rule takes /admin/users.
        $this->assertSame(1, preg_match('{^/admin}', '/admin/users'));

        $rule = static fn (int $line, string $path): array => [
            'file' => 'security.yaml', 'line' => $line, 'path' => $path, 'host' => null, 'roles' => ['ROLE_ADMIN'],
            'methods' => [], 'ips' => [], 'allowIf' => null, 'requestMatcher' => null, 'requiresChannel' => null,
        ];
        $report = (new SecurityAccessControlAnalyzer(Regex::create(['cache' => null])))->analyze([$rule(10, '^/admin'), $rule(12, '^/admin/users')]);

        $this->assertSame(1, $report->stats['redundant']);
        $builder = new SecurityAccessSuggestionBuilder();
        $this->assertSame([
            'Reorder access_control: move rule #2 (security.yaml:12) before #1.',
            'Remove redundant rule #2 (security.yaml:12); it does not add new access constraints.',
        ], $builder->collect($report->conflicts, static fn (string $file, int $line): string => ''));
        $this->assertSame([
            'Reorder access_control: move rule #2 (security.yaml line 12) before #1.',
            'Remove redundant rule #2 (security.yaml line 12); it does not add new access constraints.',
        ], $builder->collect($report->conflicts, static fn (string $file, int $line): string => $file.' line '.$line));
    }

    #[Test]
    public function test_detects_prefix_shadowing_with_search_semantics(): void
    {
        $rules = [
            [
                'file' => 'security.yaml',
                'line' => 10,
                'path' => '^/admin',
                'host' => null,
                'roles' => ['PUBLIC_ACCESS'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
            [
                'file' => 'security.yaml',
                'line' => 12,
                'path' => '^/admin/secure',
                'host' => null,
                'roles' => ['ROLE_ADMIN'],
                'methods' => [],
                'ips' => [],
                'allowIf' => null,
                'requestMatcher' => null,
                'requiresChannel' => null,
            ],
        ];

        $analyzer = new SecurityAccessControlAnalyzer(Regex::create());
        $report = $analyzer->analyze($rules);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['critical']);
        $this->assertSame('shadowed', $report->conflicts[0]['type']);
    }

    /**
     * Symfony matches a path with preg_match('{'.$path.'}s', ...): a path
     * that looks delimited is a fragment like any other, "#^/admin#x"
     * starting with a "#", and the rule is analysed, not skipped.
     */
    #[Test]
    public function test_a_path_that_looks_delimited_is_read_as_a_fragment(): void
    {
        $analyzer = new SecurityAccessControlAnalyzer(Regex::create());
        $report = $analyzer->analyze([[
            'file' => 'security.yaml',
            'line' => 3,
            'path' => '#^/admin#x',
            'host' => null,
            'roles' => ['ROLE_ADMIN'],
            'methods' => [],
            'ips' => [],
            'allowIf' => null,
            'requestMatcher' => null,
            'requiresChannel' => null,
        ]]);

        $this->assertCount(0, $report->skippedRules);
        $this->assertSame(1, $report->stats['rules']);
    }

    /**
     * Symfony wraps the path in braces: a lone "}" closes them early, and
     * the parser refuses what follows. The rule is left out, with the
     * reason, and the next rule is still read.
     */
    #[Test]
    public function test_a_path_the_parser_refuses_skips_its_rule(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: 'a}b'),
            self::rule(path: '^/admin', line: 4),
        ]);

        $this->assertSame([['index' => 1, 'file' => 'security.yaml', 'line' => 3, 'reason' => 'Invalid path regex.']], $report->skippedRules);
        $this->assertSame(2, $report->stats['rules']);
    }

    #[Test]
    public function test_a_host_the_parser_refuses_is_not_evaluated(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin', host: 'admin{'),
        ]);

        $this->assertSame([], $report->skippedRules);
        $this->assertSame([1], $report->rulesWithUnsupportedHosts);
    }

    /**
     * A backreference leaves the regular subset the solver reads: the pair
     * is counted as skipped, and the other pairs are still compared.
     */
    #[Test]
    public function test_a_pair_the_solver_cannot_read_is_skipped(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/(a)\\1'),
            self::rule(path: '^/admin', line: 4),
            self::rule(path: '^/admin/users', line: 5),
        ], true);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(2, $report->stats['skipped_pairs']);
        $this->assertSame(['^/admin', '^/admin/users'], [$report->conflicts[0]['rule']['path'], $report->conflicts[0]['conflict']['path']]);
    }

    #[Test]
    public function test_hosts_the_solver_cannot_compare_leave_the_paths_to_decide(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin', host: '^(a)\\1\\.example\\.com$'),
            self::rule(path: '^/admin/users', host: '^b\\.example\\.com$', line: 4),
        ]);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['host_checks']);
    }

    #[Test]
    public function test_paths_that_share_no_request_are_not_compared_further(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin$'),
            self::rule(path: '^/shop$', line: 4),
        ], true);

        $this->assertSame([], $report->conflicts);
        $this->assertSame(1, $report->stats['path_intersections']);
        $this->assertSame(0, $report->stats['path_subsets']);
    }

    #[Test]
    public function test_a_disjoint_pair_does_not_stop_the_comparison_of_its_earlier_rule(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin'),
            self::rule(path: '^/shop$', line: 4),
            self::rule(path: '^/admin/users', line: 5),
        ], true);

        $this->assertSame(3, $report->stats['path_intersections']);
        $this->assertSame(1, $report->stats['path_subsets']);
        $this->assertSame(['^/admin', '^/admin/users'], [$report->conflicts[0]['rule']['path'], $report->conflicts[0]['conflict']['path']]);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{file: string, line: int, path: ?string, host: ?string, roles: array<int, string>, methods: array<int, string>, ips: array<int, string>, allowIf: ?string, requestMatcher: ?string, requiresChannel: ?string}
     */
    private static function rule(?string $path, ?string $host = null, array $roles = ['ROLE_ADMIN'], int $line = 3): array
    {
        return [
            'file' => 'security.yaml',
            'line' => $line,
            'path' => $path,
            'host' => $host,
            'roles' => $roles,
            'methods' => [],
            'ips' => [],
            'allowIf' => null,
            'requestMatcher' => null,
            'requiresChannel' => null,
        ];
    }
}
