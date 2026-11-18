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
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
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
 * Each case is first checked against Symfony's own AccessMap, given the
 * request matchers SecurityExtension chains for the rules: which rule
 * applies to some requests, in the declared order and in the swapped one.
 *
 * @phpstan-type OrderRule array{path?: string, methods?: list<string>, host?: string, ips?: list<string>, port?: int, attributes?: array<string, string>, route?: string}
 * @phpstan-type OrderRequest array{0: string, 1: string, 2: int|null, 3: int|null, 4?: string, 5?: array<string, string>}
 */
final class AccessControlOrderTest extends TestCase
{
    /**
     * @param list<OrderRule>    $rules
     * @param list<OrderRequest> $requests each request's method and URL, the rule that applies to it in
     *                                     the declared order and in the swapped one, its address and the
     *                                     attributes the router gave it
     */
    #[Test]
    #[DataProvider('providePairs')]
    public function test_a_pair_is_reported_as_the_order_of_access_control_decides(array $rules, ?string $expected, array $requests): void
    {
        foreach ($requests as $request) {
            [$method, $url, $declared, $swapped] = $request;
            $address = $request[4] ?? '10.0.0.1';
            $attributes = $request[5] ?? [];
            $this->assertSame($declared, self::ruleApplied($rules, $method, $url, $address, $attributes), 'declared order, '.$method.' '.$url.' from '.$address);
            $this->assertSame($swapped, self::ruleApplied(array_reverse($rules, true), $method, $url, $address, $attributes), 'swapped order, '.$method.' '.$url.' from '.$address);
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
        // were they swapped. Its path is sent encoded, as a client sends a
        // space or a line feed, and Symfony decodes it.
        $example = $report->conflicts[0]['example'];
        $this->assertIsString($example);
        $method = self::methodOf($rules);
        $url = 'http://'.self::hostOf($rules).self::portOf($rules).implode('/', array_map(rawurlencode(...), explode('/', $example)));
        $address = self::addressOf($rules);
        $attributes = self::attributesOf($rules);
        $this->assertSame(0, self::ruleApplied($rules, $method, $url, $address, $attributes), $method.' '.$url);
        $this->assertSame(1, self::ruleApplied(array_reverse($rules, true), $method, $url, $address, $attributes), $method.' '.$url);
    }

    /**
     * @return iterable<string, array{rules: list<OrderRule>, expected: 'shadowed'|'overlap'|'carve-out'|null, requests: list<OrderRequest>}>
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

        yield 'an alternation whose first branch takes the later path shadows it' => [
            'rules' => [['path' => '^/assets|^/login'], ['path' => '^/assets/private']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/assets/private/report', 0, 1],
                ['GET', 'http://localhost/login', 0, 0],
            ],
        ];

        yield 'an alternation whose last branch takes the later path shadows it' => [
            'rules' => [['path' => '^/login|^/assets'], ['path' => '^/assets/private']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/assets/private/report', 0, 1],
                ['GET', 'http://localhost/login', 0, 0],
            ],
        ];

        yield 'an unanchored alternation whose first branch takes the later path shadows it' => [
            'rules' => [['path' => '/admin|/api'], ['path' => '^/admin/users']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['GET', 'http://localhost/shop/api', 0, 0],
            ],
        ];

        yield 'an unanchored alternation whose last branch takes the later path shadows it' => [
            'rules' => [['path' => '/api|/admin'], ['path' => '^/admin/users']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['GET', 'http://localhost/shop/api', 0, 0],
            ],
        ];

        yield 'an end anchor binds the last branch of an alternation only' => [
            'rules' => [['path' => '^/login|^/assets$'], ['path' => '^/login/form']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/login/form', 0, 1],
                ['GET', 'http://localhost/assets', 0, 0],
                ['GET', 'http://localhost/assets/logo', null, null],
            ],
        ];

        yield 'an end anchor on the last branch keeps a longer path out of it' => [
            'rules' => [['path' => '^/login|^/assets$'], ['path' => '^/assets/private']],
            'expected' => null,
            'requests' => [
                ['GET', 'http://localhost/assets/private', 1, 1],
                ['GET', 'http://localhost/login', 0, 0],
            ],
        ];

        yield 'a path opening with a start option shadows a narrower one' => [
            'rules' => [['path' => '(*UTF)^/admin'], ['path' => '^/admin/users']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['GET', 'http://localhost/admin', 0, 0],
            ],
        ];

        yield 'a path opening with several start options and an alternation shadows a narrower one' => [
            'rules' => [['path' => '(*UCP)(*LIMIT_MATCH=1000)/api|/admin'], ['path' => '^/admin/users']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['GET', 'http://localhost/shop/api', 0, 0],
            ],
        ];

        yield 'a narrower path opening with a start option first is a carve-out' => [
            'rules' => [['path' => '(*NO_JIT)(*LIMIT_MATCH=1000)^/admin/users'], ['path' => '^/admin']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['GET', 'http://localhost/admin', 1, 1],
            ],
        ];

        // A path under (*UTF) reads the URL a character at a time, one
        // without it a byte at a time: compared on the same requests.
        yield 'a narrower path read as UTF-8 first is a carve-out of a wider one read as bytes' => [
            'rules' => [['path' => '(*UTF)(*NO_JIT)^/admin/users'], ['path' => '^/admin']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['GET', 'http://localhost/admin/users%C4%80', 0, 1],
                ['GET', 'http://localhost/admin', 1, 1],
            ],
        ];

        yield 'a wider path read as bytes shadows a narrower one read as UTF-8' => [
            'rules' => [['path' => '^/admin'], ['path' => '(*UTF)^/admin/users']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/users', 0, 1],
                ['GET', 'http://localhost/admin/users%C4%80', 0, 1],
                ['GET', 'http://localhost/admin', 0, 0],
            ],
        ];

        yield 'a letter above ASCII read as one character shadows the same letter read as its bytes' => [
            'rules' => [['path' => '(*UTF)^/café$'], ['path' => '^/café$']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/caf%C3%A9', 0, 1],
                ['GET', 'http://localhost/cafe', null, null],
            ],
        ];

        // Symfony compiles the path alone: a comment of the x flag runs to
        // its end, and so does a \Q left open.
        yield 'a path under the x flag ending on a comment shadows a narrower one' => [
            'rules' => [['path' => '(?x)^/admin  # back office (the whole of it)'], ['path' => '^/admin/login']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin/login', 0, 1],
                ['GET', 'http://localhost/admin', 0, 0],
                ['GET', 'http://localhost/shop', null, null],
            ],
        ];

        yield 'a path quoted to its end shadows a narrower one' => [
            'rules' => [['path' => '^/admin\Q.x'], ['path' => '^/admin\.x/y']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin.x/y', 0, 1],
                ['GET', 'http://localhost/admin.xz', 0, 0],
                ['GET', 'http://localhost/adminyx', null, null],
            ],
        ];

        yield 'a narrower path under the x flag ending on a comment first is a carve-out' => [
            'rules' => [['path' => '(?x) ^/admin/login  # the form'], ['path' => '^/admin']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://localhost/admin/login', 0, 1],
                ['GET', 'http://localhost/admin/users', 1, 1],
            ],
        ];

        // SecurityExtension::createAuthorization() adds the route to the
        // attributes as "_route", and createRequestMatcher() chains an
        // AttributesRequestMatcher and a PortRequestMatcher to the path.
        yield 'a rule for one route leaves the other routes to a later rule on a path' => [
            'rules' => [['route' => 'app_login'], ['path' => '^/admin']],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost/admin/login', 0, 1, '10.0.0.1', ['_route' => 'app_login']],
                ['GET', 'http://localhost/admin/users', 1, 1, '10.0.0.1', ['_route' => 'admin_users']],
            ],
        ];

        yield 'a later rule for the route of an earlier one, on a path it holds, is shadowed' => [
            'rules' => [['route' => 'app_login'], ['path' => '^/login', 'route' => 'app_login']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/login', 0, 1, '10.0.0.1', ['_route' => 'app_login']],
            ],
        ];

        yield 'a wider path for one port leaves the other ports to the later rule' => [
            'rules' => [['path' => '^/admin', 'port' => 8080], ['path' => '^/admin/users']],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost:8080/admin/users', 0, 1],
                ['GET', 'http://localhost/admin/users', 1, 1],
            ],
        ];

        yield 'a later rule for a port, on a path an earlier rule for any port holds, is shadowed' => [
            'rules' => [['path' => '^/admin'], ['path' => '^/admin/users', 'port' => 8080]],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost:8080/admin/users', 0, 1],
            ],
        ];

        yield 'one path for the same port is shadowed' => [
            'rules' => [['path' => '^/admin', 'port' => 8080], ['path' => '^/admin', 'port' => 8080]],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost:8080/admin', 0, 1],
                ['GET', 'http://localhost/admin', null, null],
            ],
        ];

        yield 'one path for two ports is no conflict' => [
            'rules' => [['path' => '^/admin', 'port' => 8080], ['path' => '^/admin', 'port' => 9090]],
            'expected' => null,
            'requests' => [
                ['GET', 'http://localhost:8080/admin', 0, 0],
                ['GET', 'http://localhost:9090/admin', 1, 1],
            ],
        ];

        yield 'one path, the earlier rule restricted to an attribute, is a carve-out' => [
            'rules' => [['path' => '^/api', 'attributes' => ['_format' => 'json']], ['path' => '^/api']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://localhost/api/orders', 0, 1, '10.0.0.1', ['_format' => 'json']],
                ['GET', 'http://localhost/api/orders', 1, 1, '10.0.0.1', ['_format' => 'html']],
            ],
        ];

        yield 'a wider path restricted to an attribute leaves the other requests to the later rule' => [
            'rules' => [['path' => '^/api', 'attributes' => ['_format' => 'json']], ['path' => '^/api/orders']],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost/api/orders', 0, 1, '10.0.0.1', ['_format' => 'json']],
                ['GET', 'http://localhost/api/orders', 1, 1, '10.0.0.1', ['_format' => 'html']],
            ],
        ];

        yield 'a wider path and the same attribute shadows the narrower one' => [
            'rules' => [['path' => '^/api', 'attributes' => ['_format' => 'json']], ['path' => '^/api/orders', 'attributes' => ['_format' => 'json']]],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/api/orders', 0, 1, '10.0.0.1', ['_format' => 'json']],
            ],
        ];

        // SecurityExtension::createRequestMatcher() chains a path or a host
        // as written, when it is no "" and no "0": a space or a line feed
        // at either end is part of it.
        yield 'a path ending on an escaped line feed first is a carve-out' => [
            'rules' => [['path' => "^/admin\\\n"], ['path' => '^/admin']],
            'expected' => 'carve-out',
            'requests' => [
                ['GET', 'http://localhost/admin%0A', 0, 1],
                ['GET', 'http://localhost/admin', 1, 1],
            ],
        ];

        yield 'a path opening with a space takes no request' => [
            'rules' => [['path' => ' ^/admin'], ['path' => '^/admin']],
            'expected' => null,
            'requests' => [
                ['GET', 'http://localhost/admin', 1, 1],
                ['GET', 'http://localhost/%20/admin', null, null],
            ],
        ];

        yield 'a path of a space takes the paths holding one' => [
            'rules' => [['path' => ' '], ['path' => '^/admin']],
            'expected' => 'overlap',
            'requests' => [
                ['GET', 'http://localhost/admin/a%20b', 0, 1],
                ['GET', 'http://localhost/admin', 1, 1],
                ['GET', 'http://localhost/a%20b', 0, 0],
            ],
        ];

        yield 'a path of "0" is no path' => [
            'rules' => [['path' => '0'], ['path' => '^/admin']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin', 0, 1],
                ['GET', 'http://localhost/shop', 0, 0],
            ],
        ];

        yield 'a host ending on a space after its end takes no request' => [
            'rules' => [['path' => '^/admin', 'host' => '^admin\.example\.com$ '], ['path' => '^/admin', 'host' => '^admin\.example\.com$']],
            'expected' => null,
            'requests' => [
                ['GET', 'http://admin.example.com/admin', 1, 1],
            ],
        ];

        yield 'a host of "0" is no host' => [
            'rules' => [['path' => '^/admin', 'host' => '0'], ['path' => '^/admin']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://admin.example.com/admin', 0, 1],
                ['GET', 'http://shop.example.com/admin', 0, 1],
            ],
        ];

        // symfony/http-foundation compiles a path with "s" since 6.4.46,
        // 7.4.19 and 8.1.7, and never on 8.0: a dot takes a line feed where
        // the PathRequestMatcher installed does. Whatever the modifier, a
        // path is searched for in the whole request path, line feeds and
        // all.
        $dotAll = (new PathRequestMatcher('^/.$'))->matches(Request::create('/%0A'));
        yield 'a dot takes a line feed where the path is compiled with "s"' => [
            'rules' => [['path' => '^/admin.x'], ['path' => '^/admin\nx']],
            'expected' => $dotAll ? 'shadowed' : null,
            'requests' => [
                ['GET', 'http://localhost/admin%0Ax', $dotAll ? 0 : 1, 1],
                ['GET', 'http://localhost/adminyx', 0, 0],
            ],
        ];

        yield 'a path is searched for after a line feed' => [
            'rules' => [['path' => '/admin'], ['path' => '^/x\n/admin']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/x%0A/admin', 0, 1],
                ['GET', 'http://localhost/shop/admin', 0, 0],
            ],
        ];

        yield 'a path is searched for before a line feed' => [
            'rules' => [['path' => '^/admin'], ['path' => '^/admin\n']],
            'expected' => 'shadowed',
            'requests' => [
                ['GET', 'http://localhost/admin%0Ausers', 0, 1],
                ['GET', 'http://localhost/admin', 0, 0],
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
     * the order of the array, or null when none does. Each rule is given
     * the request matcher the security bundle chains for it.
     *
     * @param array<int, OrderRule> $rules
     * @param array<string, string> $attributes the attributes the router gave the request
     */
    private static function ruleApplied(array $rules, string $method, string $url, string $address, array $attributes = []): ?int
    {
        $accessControl = [];
        foreach ($rules as $index => $rule) {
            $accessControl[] = ['roles' => ['RULE_'.$index]] + $rule;
        }

        $container = new ContainerBuilder();
        (new SecurityExtension())->load([['firewalls' => ['main' => ['security' => false]], 'access_control' => $accessControl]], $container);

        $map = new AccessMap();
        foreach ($container->getDefinition('security.access_map')->getMethodCalls() as $call) {
            self::assertIsArray($call);
            self::assertIsArray($call[1]);
            [$matcher, $roles] = $call[1];
            self::assertInstanceOf(Reference::class, $matcher);
            self::assertIsArray($roles);
            $chain = $container->get((string) $matcher);
            self::assertInstanceOf(RequestMatcherInterface::class, $chain);
            $map->add($chain, $roles);
        }

        $request = Request::create($url, $method, server: ['REMOTE_ADDR' => $address]);
        $request->attributes->add($attributes);
        [$applied] = $map->getPatterns($request);

        $applied = $applied[0] ?? null;

        return \is_string($applied) ? (int) substr($applied, 5) : null;
    }

    /**
     * @param OrderRule $rule
     *
     * @return array{file: string, line: int, path: string|null, host: string|null, roles: list<string>, methods: list<string>, ips: list<string>, allowIf: null, requestMatcher: null, requiresChannel: null, port: int|null, attributes: array<string, string>, route: string|null}
     */
    private static function rule(array $rule): array
    {
        return [
            'file' => 'security.yaml',
            'line' => 1,
            'path' => $rule['path'] ?? null,
            'host' => $rule['host'] ?? null,
            'roles' => ['ROLE_USER'],
            'methods' => $rule['methods'] ?? [],
            'ips' => $rule['ips'] ?? [],
            'allowIf' => null,
            'requestMatcher' => null,
            'requiresChannel' => null,
            'port' => $rule['port'] ?? null,
            'attributes' => $rule['attributes'] ?? [],
            'route' => $rule['route'] ?? null,
        ];
    }

    /**
     * A method every rule of the pair takes: a rule without methods takes
     * them all.
     *
     * @param list<OrderRule> $rules
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
     * @param list<OrderRule> $rules
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
     * @param list<OrderRule> $rules
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

    /**
     * The port of a URL every rule of the pair takes, written after the host.
     *
     * @param list<OrderRule> $rules
     */
    private static function portOf(array $rules): string
    {
        foreach ($rules as $rule) {
            if (isset($rule['port'])) {
                return ':'.$rule['port'];
            }
        }

        return '';
    }

    /**
     * The attributes the router gives a request every rule of the pair
     * takes.
     *
     * @param list<OrderRule> $rules
     *
     * @return array<string, string>
     */
    private static function attributesOf(array $rules): array
    {
        $attributes = [];
        foreach ($rules as $rule) {
            $attributes = [...$attributes, ...($rule['attributes'] ?? []), ...(isset($rule['route']) ? ['_route' => $rule['route']] : [])];
        }

        return $attributes;
    }
}
