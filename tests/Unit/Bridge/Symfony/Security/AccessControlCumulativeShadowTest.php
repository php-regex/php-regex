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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * access_control applies the first rule that matches: a rule several
 * earlier rules cover together, none alone, never applies, which no pair
 * of rules tells.
 */
final class AccessControlCumulativeShadowTest extends TestCase
{
    #[Test]
    public function test_two_rules_shadow_a_third_on_its_paths(): void
    {
        $report = self::analyze([
            self::rule('^/admin/[0-9]', ['ROLE_USER']),
            self::rule('^/admin/[^0-9]', ['ROLE_USER']),
            self::rule('^/admin/.', ['ROLE_ADMIN']),
        ]);

        $this->assertSame([], $report->conflicts, 'No earlier rule alone takes every request of the third.');
        $this->assertCount(1, $report->cumulative);
        $this->assertSame(3, $report->cumulative[0]['rule']['index']);
        $this->assertSame([1, 2], array_map(static fn (array $taken): int => $taken['rule']['index'], $report->cumulative[0]['takenBy']));
        $this->assertSame('warning', $report->cumulative[0]['severity']);
        $this->assertSame(1, $report->stats['cumulative']);
    }

    #[Test]
    public function test_a_public_rule_among_those_is_critical(): void
    {
        $report = self::analyze([
            self::rule('^/api', ['PUBLIC_ACCESS'], ['GET']),
            self::rule('^/api', ['ROLE_USER'], ['POST']),
            self::rule('^/api', ['ROLE_API'], ['GET', 'POST']),
        ]);

        $this->assertCount(1, $report->cumulative);
        $this->assertSame('critical', $report->cumulative[0]['severity']);
        $this->assertSame(1, $report->stats['critical']);
        $this->assertStringStartsWith('GET ', $report->cumulative[0]['takenBy'][0]['example']);
    }

    #[Test]
    public function test_a_path_is_searched_for(): void
    {
        // Unanchored, "/admin" takes "/x/admin" too.
        $report = self::analyze([
            self::rule('/admin', ['ROLE_ADMIN']),
            self::rule('^/(?!.*/admin)', ['ROLE_USER']),
            self::rule('^/', ['ROLE_X']),
        ]);

        $this->assertCount(1, $report->cumulative);
        $this->assertSame(3, $report->cumulative[0]['rule']['index']);
    }

    #[Test]
    public function test_a_rule_matched_on_addresses_shadows_nothing(): void
    {
        $restricted = self::rule('^/admin/[0-9]', ['ROLE_USER']);
        $restricted['ips'] = ['127.0.0.1'];
        $report = self::analyze([
            $restricted,
            self::rule('^/admin/[^0-9]', ['ROLE_USER']),
            self::rule('^/admin/.', ['ROLE_ADMIN']),
        ]);

        $this->assertSame([], $report->cumulative);
    }

    #[Test]
    public function test_a_rule_one_earlier_rule_shadows_is_reported_as_a_pair_only(): void
    {
        $report = self::analyze([
            self::rule('^/admin', ['ROLE_ADMIN']),
            self::rule('^/admin/users', ['ROLE_USER']),
            self::rule('^/admin/users/[0-9]', ['ROLE_X']),
        ]);

        $this->assertSame([], $report->cumulative);
        $this->assertNotSame([], $report->conflicts);
    }

    #[Test]
    public function test_a_rule_keeping_some_requests_is_not_reported(): void
    {
        $report = self::analyze([
            self::rule('^/admin/[0-9]', ['ROLE_USER']),
            self::rule('^/admin/[a-z]', ['ROLE_USER']),
            self::rule('^/admin/.', ['ROLE_ADMIN']),
        ]);

        $this->assertSame([], $report->cumulative, '"/admin/-" reaches the third rule.');
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    private static function analyze(array $rules): \PHPRegex\Symfony\Security\SecurityAccessControlReport
    {
        /* @phpstan-ignore argument.type */
        return (new SecurityAccessControlAnalyzer(Regex::create()))->analyze($rules);
    }

    /**
     * @param list<string> $roles
     * @param list<string> $methods
     *
     * @return array<string, mixed>
     */
    private static function rule(string $path, array $roles, array $methods = []): array
    {
        return [
            'file' => 'security.yaml',
            'line' => 3,
            'path' => $path,
            'host' => null,
            'roles' => $roles,
            'methods' => $methods,
            'ips' => [],
            'allowIf' => null,
            'requestMatcher' => null,
            'requiresChannel' => null,
        ];
    }
}
