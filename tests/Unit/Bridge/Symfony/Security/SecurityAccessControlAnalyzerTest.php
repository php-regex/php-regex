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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\AccessDecisionStrategyInterface;
use Symfony\Component\Security\Core\Authorization\Strategy\AffirmativeStrategy;
use Symfony\Component\Security\Core\Authorization\Strategy\ConsensusStrategy;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

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

    /**
     * PathRequestMatcher compiles the path alone, '{'.$path.'}s': a path
     * PCRE refuses there takes no request, whatever the search the
     * analysis builds from it would read, and its rule is left out.
     */
    #[Test]
    #[DataProvider('provideInvalidPaths')]
    public function test_a_path_pcre_refuses_skips_its_rule(string $path): void
    {
        $this->assertFalse(@preg_match('{'.$path.'}s', ''));

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: $path, roles: ['PUBLIC_ACCESS']),
            self::rule(path: '^/admin', line: 4),
        ], true);

        $this->assertSame([['index' => 1, 'file' => 'security.yaml', 'line' => 3, 'reason' => 'Invalid path regex.']], $report->skippedRules);
        $this->assertSame([], $report->conflicts);
    }

    /**
     * @return iterable<string, array{path: string}>
     */
    public static function provideInvalidPaths(): iterable
    {
        yield 'a group left open' => ['path' => '^/(admin'];
        yield 'a comment left open' => ['path' => '^/admin(?#oops'];
        yield 'an unbounded lookbehind' => ['path' => '^/admin(?<=a+)'];
        yield 'a reference to no group' => ['path' => '^/admin\\2(a)'];
        yield 'a class left open' => ['path' => '^/admin[a'];
    }

    /**
     * HostRequestMatcher compiles the host alone, '{'.$host.'}i': a host
     * PCRE refuses there leaves the paths to decide.
     */
    #[Test]
    #[DataProvider('provideInvalidHosts')]
    public function test_a_host_pcre_refuses_is_not_evaluated(string $host): void
    {
        $this->assertFalse(@preg_match('{'.$host.'}i', ''));

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin', host: $host),
        ]);

        $this->assertSame([], $report->skippedRules);
        $this->assertSame([1], $report->rulesWithUnsupportedHosts);
    }

    /**
     * @return iterable<string, array{host: string}>
     */
    public static function provideInvalidHosts(): iterable
    {
        yield 'a group left open' => ['host' => '^(admin\\.example\\.com$'];
        yield 'an unbounded lookbehind' => ['host' => '(?<=a+)\\.example\\.com$'];
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
     * A route and request attributes are compared as written, as addresses
     * are: the finding says so, and an earlier rule for one route is no
     * critical shadow of a later rule for every route.
     */
    #[Test]
    public function test_a_rule_for_a_route_notes_its_restriction_and_shadows_no_wider_rule(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            ['route' => 'app_login', 'attributes' => ['_format' => 'html']] + self::rule(path: null, roles: ['PUBLIC_ACCESS']),
            self::rule(path: '^/admin', line: 4),
        ], true);

        $this->assertSame(0, $report->stats['shadowed']);
        $this->assertSame(0, $report->stats['critical']);
        $this->assertSame('overlap', $report->conflicts[0]['type']);
        $this->assertSame('app_login', $report->conflicts[0]['rule']['route']);
        $this->assertSame(['_format' => 'html'], $report->conflicts[0]['rule']['attributes']);
        $this->assertContains('Route and attribute restrictions are not evaluated.', $report->conflicts[0]['notes']);
    }

    /**
     * A rule with no path takes the requests on every path, whatever its
     * methods, host, port or addresses keep it to; one that names a route
     * or request attributes takes only the paths the router gives them, and
     * is neither noted nor counted as matching every path.
     *
     * @param array{route?: string, attributes?: array<string, string>, methods?: list<string>, host?: string, port?: int, ips?: list<string>} $restriction
     */
    #[Test]
    #[DataProvider('provideRestrictionsBesideAMissingPath')]
    public function test_a_rule_with_no_path_matches_every_path_unless_a_route_or_attributes_keep_it(array $restriction, bool $everyPath): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            $restriction + self::rule(path: null, roles: ['PUBLIC_ACCESS']),
            self::rule(path: '^/admin', line: 4),
        ], true);

        $this->assertSame($everyPath ? [1] : [], $report->rulesWithNoPath);
        $this->assertCount(1, $report->conflicts);
        $this->assertSame($everyPath, \in_array('Rule has no path; it matches every path.', $report->conflicts[0]['notes'], true));
        $this->assertSame(isset($restriction['route']) || isset($restriction['attributes']), \in_array('Route and attribute restrictions are not evaluated.', $report->conflicts[0]['notes'], true));
    }

    /**
     * @return iterable<string, array{restriction: array{route?: string, attributes?: array<string, string>, methods?: list<string>, host?: string, port?: int, ips?: list<string>}, everyPath: bool}>
     */
    public static function provideRestrictionsBesideAMissingPath(): iterable
    {
        yield 'no other restriction' => ['restriction' => [], 'everyPath' => true];
        yield 'methods' => ['restriction' => ['methods' => ['POST']], 'everyPath' => true];
        yield 'a host' => ['restriction' => ['host' => '^api\.example\.com$'], 'everyPath' => true];
        yield 'a port' => ['restriction' => ['port' => 8080], 'everyPath' => true];
        yield 'addresses' => ['restriction' => ['ips' => ['10.0.0.0/8']], 'everyPath' => true];
        yield 'a route' => ['restriction' => ['route' => 'app_login'], 'everyPath' => false];
        yield 'request attributes' => ['restriction' => ['attributes' => ['_format' => 'json']], 'everyPath' => false];
    }

    /**
     * The attributes are a map: written in another order, or with the
     * route as its "_route" attribute, they require the same requests.
     */
    #[Test]
    public function test_a_rule_requiring_the_same_attributes_in_another_order_is_redundant(): void
    {
        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            ['route' => 'api_orders', 'attributes' => ['_format' => 'json', '_locale' => 'fr']] + self::rule(path: '^/api'),
            ['attributes' => ['_locale' => 'fr', '_route' => 'api_orders', '_format' => 'json']] + self::rule(path: '^/api', line: 4),
        ]);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(1, $report->stats['redundant']);
    }

    /**
     * symfony/http-foundation's MethodRequestMatcher takes a HEAD request
     * for GET since 6.4.50, 7.4.23 and 8.1.11, and never on 8.0: where the
     * matcher installed does, a later rule for GET and HEAD of the same
     * roles takes the requests of an earlier rule for GET, and is
     * redundant.
     */
    #[Test]
    public function test_a_rule_for_get_and_head_after_a_rule_for_get_is_redundant_where_get_takes_head(): void
    {
        $getTakesHead = (new MethodRequestMatcher(['GET']))->matches(Request::create('/', 'HEAD'));

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            ['methods' => ['GET']] + self::rule(path: '^/x'),
            ['methods' => ['GET', 'HEAD']] + self::rule(path: '^/x', line: 4),
        ]);

        $this->assertSame($getTakesHead ? 1 : 0, $report->stats['shadowed']);
        $this->assertSame($getTakesHead ? 1 : 0, $report->stats['redundant']);
        $this->assertSame($getTakesHead ? 0 : 1, $report->stats['carve_outs']);
    }

    /**
     * The methods a rule takes are asked of the MethodRequestMatcher
     * installed through requests built as they are: a factory the
     * application gives Request::setFactory() plays no part in them.
     */
    #[Test]
    public function test_the_methods_are_read_without_the_application_request_factory(): void
    {
        $getTakesHead = (new MethodRequestMatcher(['GET']))->matches(Request::create('/', 'HEAD'));
        Request::setFactory(static function (): Request {
            throw new \LogicException('The application builds no request here.');
        });

        try {
            $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
                ['methods' => ['GET']] + self::rule(path: '^/x'),
                ['methods' => ['HEAD']] + self::rule(path: '^/x', line: 4),
            ]);
        } finally {
            Request::setFactory(null);
        }

        $this->assertSame($getTakesHead ? 1 : 0, $report->stats['shadowed']);
    }

    /**
     * A rule is public when the security bundle's AuthenticatedVoter grants
     * its roles to a visitor: "PUBLIC_ACCESS" as written, alone, as the
     * voter and AccessListener compare the whole list with it, and nothing
     * else (IS_AUTHENTICATED_ANONYMOUSLY is gone since Symfony 6.0). A public
     * rule before a restricted one it shadows is critical.
     *
     * @param list<string> $roles
     */
    #[Test]
    #[DataProvider('providePublicRoles')]
    public function test_a_rule_is_public_as_the_authenticated_voter_reads_its_roles(array $roles): void
    {
        $public = VoterInterface::ACCESS_GRANTED === (new AuthenticatedVoter(new AuthenticationTrustResolver()))->vote(new NullToken(), null, $roles);

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin', roles: $roles),
            self::rule(path: '^/admin/users', line: 4),
        ]);

        $this->assertSame('shadowed', $report->conflicts[0]['type']);
        $this->assertSame($public ? 'critical' : 'warning', $report->conflicts[0]['severity']);
    }

    /**
     * @return iterable<string, array{roles: list<string>}>
     */
    public static function providePublicRoles(): iterable
    {
        yield 'PUBLIC_ACCESS' => ['roles' => ['PUBLIC_ACCESS']];
        yield 'PUBLIC_ACCESS after a space' => ['roles' => [' PUBLIC_ACCESS']];
        yield 'PUBLIC_ACCESS in lower case' => ['roles' => ['public_access']];
        yield 'IS_AUTHENTICATED_ANONYMOUSLY' => ['roles' => ['IS_AUTHENTICATED_ANONYMOUSLY']];
        yield 'PUBLIC_ACCESS beside another role' => ['roles' => ['PUBLIC_ACCESS', 'ROLE_USER']];
        yield 'PUBLIC_ACCESS twice' => ['roles' => ['PUBLIC_ACCESS', 'PUBLIC_ACCESS']];
    }

    /**
     * A later rule of the same path is redundant when Symfony decides every
     * request it would take as it decides them for the earlier rule. A role
     * written twice changes nothing to the voters, whatever the strategy,
     * but AccessListener lets a request through only for "PUBLIC_ACCESS"
     * written once and alone, and AuthenticatedVoter grants only that list:
     * "PUBLIC_ACCESS" written twice refuses every visitor.
     *
     * @param list<string> $earlier
     * @param list<string> $later
     */
    #[Test]
    #[DataProvider('provideRolesWrittenTwice')]
    public function test_a_later_rule_is_redundant_only_where_symfony_decides_its_roles_alike(array $earlier, array $later): void
    {
        $alike = true;
        foreach ([new AffirmativeStrategy(), new UnanimousStrategy(), new ConsensusStrategy()] as $strategy) {
            foreach ([new NullToken(), new UsernamePasswordToken(new InMemoryUser('a', null, ['ROLE_A']), 'main', ['ROLE_A'])] as $token) {
                $alike = $alike && self::granted($strategy, $token, $earlier) === self::granted($strategy, $token, $later);
            }
        }

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin', roles: $earlier),
            self::rule(path: '^/admin', roles: $later, line: 4),
        ]);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame($alike ? 1 : 0, $report->stats['redundant']);
    }

    /**
     * @return iterable<string, array{earlier: list<string>, later: list<string>}>
     */
    public static function provideRolesWrittenTwice(): iterable
    {
        yield 'PUBLIC_ACCESS, then PUBLIC_ACCESS twice' => ['earlier' => ['PUBLIC_ACCESS'], 'later' => ['PUBLIC_ACCESS', 'PUBLIC_ACCESS']];
        yield 'PUBLIC_ACCESS twice, then PUBLIC_ACCESS' => ['earlier' => ['PUBLIC_ACCESS', 'PUBLIC_ACCESS'], 'later' => ['PUBLIC_ACCESS']];
        yield 'a role twice, then once' => ['earlier' => ['ROLE_A', 'ROLE_A'], 'later' => ['ROLE_A']];
        yield 'a role once, then twice' => ['earlier' => ['ROLE_A'], 'later' => ['ROLE_A', 'ROLE_A']];
    }

    /**
     * The RoleVoter compares roles as written: a later rule for "role_admin"
     * does not take the users an earlier rule for "ROLE_ADMIN" takes, and is
     * not redundant.
     */
    #[Test]
    public function test_roles_differing_in_case_are_other_roles(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);
        $this->assertSame(VoterInterface::ACCESS_GRANTED, (new RoleVoter())->vote($token, null, ['ROLE_ADMIN']));
        $this->assertNotSame(VoterInterface::ACCESS_GRANTED, (new RoleVoter())->vote($token, null, ['role_admin']));

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze([
            self::rule(path: '^/admin', roles: ['ROLE_ADMIN']),
            self::rule(path: '^/admin', roles: ['role_admin'], line: 4),
        ]);

        $this->assertSame(1, $report->stats['shadowed']);
        $this->assertSame(0, $report->stats['redundant']);
    }

    /**
     * Whether a request of the token reaches the rule's controller, as
     * AccessListener decides it: through for "PUBLIC_ACCESS" alone, else
     * as the AccessDecisionManager of the strategy decides the roles.
     *
     * @param list<string> $roles
     */
    private static function granted(AccessDecisionStrategyInterface $strategy, TokenInterface $token, array $roles): bool
    {
        if (['PUBLIC_ACCESS'] === $roles) {
            return true;
        }

        $manager = new AccessDecisionManager([new RoleVoter(), new AuthenticatedVoter(new AuthenticationTrustResolver())], $strategy);

        // Symfony 7.3 puts an AccessDecision before allowMultipleAttributes.
        return 5 === (new \ReflectionMethod($manager, 'decide'))->getNumberOfParameters()
            ? $manager->decide($token, $roles, null, null, true)
            : $manager->decide($token, $roles, null, true);
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
