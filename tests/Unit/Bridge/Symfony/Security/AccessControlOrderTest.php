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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\HostRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\IpsRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\Security\Http\AccessMap;

/**
 * access_control is first match: Symfony's AccessMap applies the first rule
 * whose request matcher takes the request. For an earlier rule E and a
 * later rule L that share a request:
 *
 * - L is shadowed when E takes every request L takes: L never applies;
 * - E is a carve-out of L when E takes only requests L takes too, or when
 *   E's path is strictly narrower than L's: the order access_control
 *   expects, reported never;
 * - otherwise the order decides which rule applies to the requests both
 *   take, and the pair is an overlap.
 *
 * Each case is first checked against Symfony's own AccessMap: which rule
 * applies to some requests, in the declared order and in the swapped one.
 */
final class AccessControlOrderTest extends TestCase
{
    /**
     * @param list<array{path: string, methods?: list<string>, host?: string, ips?: list<string>}> $rules
     * @param list<array{0: string, 1: string, 2: int|null, 3: int|null, 4?: string}>              $requests each request's method and URL, and
     *                                                                                                       the rule that applies to it in the
     *                                                                                                       declared order and in the swapped one
     */
    #[Test]
    #[DataProvider('providePairs')]
    public function test_a_pair_is_reported_as_the_order_of_access_control_decides(array $rules, ?string $expected, array $requests): void
    {
        foreach ($requests as $request) {
            [$method, $url, $declared, $swapped] = $request;
            $address = $request[4] ?? '10.0.0.1';
            $this->assertSame($declared, self::ruleApplied($rules, $method, $url, $address), 'declared order, '.$method.' '.$url.' from '.$address);
            $this->assertSame($swapped, self::ruleApplied(array_reverse($rules, true), $method, $url, $address), 'swapped order, '.$method.' '.$url.' from '.$address);
        }

        $report = (new SecurityAccessControlAnalyzer(Regex::create()))->analyze(array_map(self::rule(...), array_values($rules)), true);

        $this->assertSame('carve-out' === $expected ? null : $expected, $report->conflicts[0]['type'] ?? null);
        $this->assertSame('shadowed' === $expected ? 1 : 0, $report->stats['shadowed']);
        $this->assertSame('overlap' === $expected ? 1 : 0, $report->stats['overlaps']);
        $this->assertSame('carve-out' === $expected ? 1 : 0, $report->stats['carve_outs']);

        if (null === $expected || 'carve-out' === $expected) {
            return;
        }

        // The request the report gives as an example is one both rules
        // take: the earlier one applies to it, and the later one would
        // were they swapped.
        $example = $report->conflicts[0]['example'];
        $this->assertIsString($example);
        $method = self::methodOf($rules);
        $url = 'http://'.self::hostOf($rules).$example;
        $address = self::addressOf($rules);
        $this->assertSame(0, self::ruleApplied($rules, $method, $url, $address), $method.' '.$url);
        $this->assertSame(1, self::ruleApplied(array_reverse($rules, true), $method, $url, $address), $method.' '.$url);
    }

    /**
     * @return iterable<string, array{rules: list<array{path: string, methods?: list<string>, host?: string, ips?: list<string>}>, expected: 'shadowed'|'overlap'|'carve-out'|null, requests: list<array{0: string, 1: string, 2: int|null, 3: int|null, 4?: string}>}>
     */
    public static function providePairs(): iterable
    {
        yield 'a narrower path first is a carve-out' => [
            'rules' => [['path' => '^/admin/public'], ['path' => '^/admin']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://localhost/admin/public/doc', 0, 1],
                ['GET', 'http://localhost/admin/users', 1, 1],
            ],
        ];

        yield 'a wider path first shadows the narrower one' => [
            'rules' => [['path' => '^/admin'], ['path' => '^/admin/public']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/public/doc', 0, 1],
                ['GET', 'http://localhost/admin/users', 0, 0],
            ],
        ];

        yield 'two paths sharing some requests overlap' => [
            'rules' => [['path' => '^/admin'], ['path' => '/reports$']],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost/admin/reports', 0, 1],
                ['GET', 'http://localhost/admin/users', 0, 0],
                ['GET', 'http://localhost/shop/reports', 1, 1],
            ],
        ];

        yield 'a narrower path first is a carve-out whatever methods the wider one restricts to' => [
            'rules' => [['path' => '^/admin/public'], ['path' => '^/admin', 'methods' => ['GET']]],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://localhost/admin/public', 0, 1],
                ['POST', 'http://localhost/admin/public', 0, 0],
                ['GET', 'http://localhost/admin/users', 1, 1],
            ],
        ];

        yield 'a wider path restricted to some methods leaves the other methods to the later rule' => [
            'rules' => [['path' => '^/admin', 'methods' => ['GET']], ['path' => '^/admin/users']],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['POST', 'http://localhost/admin/users', 1, 1],
            ],
        ];

        yield 'one path, the earlier rule restricted to a method, is a carve-out' => [
            'rules' => [['path' => '^/api', 'methods' => ['POST']], ['path' => '^/api']],
            'expected' => 'carve-out',
            'requests' => [
                ['POST', 'http://localhost/api/orders', 0, 1],
                ['GET', 'http://localhost/api/orders', 1, 1],
            ],
        ];

        yield 'one path, the later rule restricted to a method, is shadowed' => [
            'rules' => [['path' => '^/api'], ['path' => '^/api', 'methods' => ['POST']]],
            'expected' => 'shadowed',
            'requests' => [
                ['POST', 'http://localhost/api/orders', 0, 1],
            ],
        ];

        yield 'one path, methods that cross, overlap' => [
            'rules' => [['path' => '^/api', 'methods' => ['GET', 'PUT']], ['path' => '^/api', 'methods' => ['GET', 'POST']]],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost/api', 0, 1],
                ['PUT', 'http://localhost/api', 0, 0],
                ['POST', 'http://localhost/api', 1, 1],
            ],
        ];

        yield 'one path, the earlier rule restricted to a host, is a carve-out' => [
            'rules' => [['path' => '^/admin', 'host' => '^admin\.example\.com$'], ['path' => '^/admin']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://admin.example.com/admin', 0, 1],
                ['GET', 'http://shop.example.com/admin', 1, 1],
            ],
        ];

        yield 'one path, the later rule restricted to a host, is shadowed' => [
            'rules' => [['path' => '^/admin'], ['path' => '^/admin', 'host' => '^admin\.example\.com$']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://admin.example.com/admin', 0, 1],
            ],
        ];

        yield 'a wider path restricted to an address leaves the other addresses to the later rule' => [
            'rules' => [['path' => '^/admin', 'ips' => ['127.0.0.1']], ['path' => '^/admin/users']],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1, '127.0.0.1'],
                ['GET', 'http://localhost/admin/users', 1, 1, '10.0.0.1'],
            ],
        ];

        yield 'a later rule restricted to an address the earlier one takes is shadowed' => [
            'rules' => [['path' => '^/admin'], ['path' => '^/admin/users', 'ips' => ['127.0.0.1']]],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1, '127.0.0.1'],
            ],
        ];

        yield 'one path and the same address is shadowed' => [
            'rules' => [['path' => '^/admin', 'ips' => ['127.0.0.1']], ['path' => '^/admin', 'ips' => ['127.0.0.1']]],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin', 0, 1, '127.0.0.1'],
                ['GET', 'http://localhost/admin', null, null, '10.0.0.1'],
            ],
        ];

        yield 'one path, a later host the earlier one holds, is shadowed' => [
            'rules' => [['path' => '^/admin', 'host' => '\.example\.com$'], ['path' => '^/admin', 'host' => '^admin\.example\.com$']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://admin.example.com/admin', 0, 1],
            ],
        ];

        yield 'one path, an earlier host the later one holds, is a carve-out' => [
            'rules' => [['path' => '^/admin', 'host' => '^admin\.example\.com$'], ['path' => '^/admin', 'host' => '\.example\.com$']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://admin.example.com/admin', 0, 1],
                ['GET', 'http://shop.example.com/admin', 1, 1],
            ],
        ];

        yield 'hosts the solver cannot compare leave the paths to decide' => [
            'rules' => [['path' => '^/admin', 'host' => '^(a)\1\.example\.com$'], ['path' => '^/admin/users', 'host' => '^aa\.example\.com$']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://aa.example.com/admin/users', 0, 1],
            ],
        ];

        yield 'paths that share no request are no conflict' => [
            'rules' => [['path' => '^/admin'], ['path' => '^/api']],
            'expected' => null,
            'requests' => [
                ['GET', 'http://localhost/admin', 0, 0],
                ['GET', 'http://localhost/api', 1, 1],
            ],
        ];
    }

    /**
     * The index of the rule Symfony's AccessMap applies to the request, in
     * the order of the array, or null when none does.
     *
     * @param array<int, array{path: string, methods?: list<string>, host?: string, ips?: list<string>}> $rules
     */
    private static function ruleApplied(array $rules, string $method, string $url, string $address): ?int
    {
        $map = new AccessMap();
        foreach ($rules as $index => $rule) {
            $matchers = [new PathRequestMatcher($rule['path'])];
            if (isset($rule['host'])) {
                $matchers[] = new HostRequestMatcher($rule['host']);
            }
            if (isset($rule['methods'])) {
                $matchers[] = new MethodRequestMatcher($rule['methods']);
            }
            if (isset($rule['ips'])) {
                $matchers[] = new IpsRequestMatcher($rule['ips']);
            }
            $map->add(new ChainRequestMatcher($matchers), ['RULE_'.$index]);
        }

        [$attributes] = $map->getPatterns(Request::create($url, $method, server: ['REMOTE_ADDR' => $address]));

        $applied = $attributes[0] ?? null;

        return \is_string($applied) ? (int) substr($applied, 5) : null;
    }

    /**
     * @param array{path: string, methods?: list<string>, host?: string, ips?: list<string>} $rule
     *
     * @return array{file: string, line: int, path: string, host: string|null, roles: list<string>, methods: list<string>, ips: list<string>, allowIf: null, requestMatcher: null, requiresChannel: null}
     */
    private static function rule(array $rule): array
    {
        return [
            'file' => 'security.yaml',
            'line' => 1,
            'path' => $rule['path'],
            'host' => $rule['host'] ?? null,
            'roles' => ['ROLE_USER'],
            'methods' => $rule['methods'] ?? [],
            'ips' => $rule['ips'] ?? [],
            'allowIf' => null,
            'requestMatcher' => null,
            'requiresChannel' => null,
        ];
    }

    /**
     * A method every rule of the pair takes: a rule without methods takes
     * them all.
     *
     * @param list<array{path: string, methods?: list<string>, host?: string, ips?: list<string>}> $rules
     */
    private static function methodOf(array $rules): string
    {
        $methods = null;
        foreach ($rules as $rule) {
            if (isset($rule['methods'])) {
                $methods = null === $methods ? $rule['methods'] : array_values(array_intersect($methods, $rule['methods']));
            }
        }

        return $methods[0] ?? 'GET';
    }

    /**
     * An address every rule of the pair takes.
     *
     * @param list<array{path: string, methods?: list<string>, host?: string, ips?: list<string>}> $rules
     */
    private static function addressOf(array $rules): string
    {
        foreach ($rules as $rule) {
            if (isset($rule['ips'])) {
                return $rule['ips'][0];
            }
        }

        return '10.0.0.1';
    }

    /**
     * A host every rule of the pair takes.
     *
     * @param list<array{path: string, methods?: list<string>, host?: string, ips?: list<string>}> $rules
     */
    private static function hostOf(array $rules): string
    {
        foreach ($rules as $rule) {
            if (isset($rule['host'])) {
                return str_contains($rule['host'], '(a)') ? 'aa.example.com' : 'admin.example.com';
            }
        }

        return 'localhost';
    }
}
