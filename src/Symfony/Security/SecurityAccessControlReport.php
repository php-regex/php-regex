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

namespace PhpRegex\Symfony\Security;

/**
 * @internal
 *
 * @phpstan-type AccessRule array{index: int, file: string, line: int, path: ?string, pattern: string, roles: array<int, string>, methods: array<int, string>, host: ?string, hostPattern: ?string, hostUnsupported: bool, ips: array<int, string>, allowIf: ?string, requiresChannel: ?string, accessLevel: string, notes: array<int, string>}
 * @phpstan-type AccessConflict array{
 *     rule: AccessRule,
 *     conflict: AccessRule,
 *     type: string,
 *     severity: string,
 *     example: ?string,
 *     equivalent: bool,
 *     redundant: bool,
 *     notes: array<int, string>,
 * }
 * @phpstan-type AccessSkip array{index: int, file: string, line: int, reason: string}
 */
final readonly class SecurityAccessControlReport
{
    /**
     * @param array<AccessConflict> $conflicts
     * @param array<AccessSkip>     $skippedRules
     * @param array<string, int>    $stats
     * @param array<int, int>       $rulesWithAllowIf
     * @param array<int, int>       $rulesWithIps
     * @param array<int, int>       $rulesWithNoPath
     * @param array<int, int>       $rulesWithUnsupportedHosts
     */
    public function __construct(
        public array $conflicts,
        public array $skippedRules,
        public array $stats,
        public array $rulesWithAllowIf,
        public array $rulesWithIps,
        public array $rulesWithNoPath,
        public array $rulesWithUnsupportedHosts,
    ) {}
}
