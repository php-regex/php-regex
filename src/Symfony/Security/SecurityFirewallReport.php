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

namespace PHPRegex\Symfony\Security;

/**
 * @internal
 *
 * @phpstan-type FirewallFinding array{
 *     name: string,
 *     file: string,
 *     line: int,
 *     pattern: string,
 *     severity: string,
 *     score: int,
 *     vulnerable: ?string,
 *     trigger: ?string,
 * }
 * @phpstan-type FirewallSkip array{name: string, file: string, line: int, reason: string}
 */
final readonly class SecurityFirewallReport
{
    /**
     * @param array<FirewallFinding> $findings
     * @param array<FirewallSkip>    $skippedFirewalls
     * @param array<string, int>     $stats
     */
    public function __construct(
        public array $findings,
        public array $skippedFirewalls,
        public array $stats,
    ) {}
}
