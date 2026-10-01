<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Symfony\Security;

use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Toolkit\Regex;

/**
 * @internal
 *
 * @phpstan-import-type FirewallRule from SecurityConfigExtractor
 * @phpstan-import-type FirewallFinding from SecurityFirewallReport
 * @phpstan-import-type FirewallSkip from SecurityFirewallReport
 */
final readonly class SecurityFirewallAnalyzer
{
    public function __construct(private Regex $regex, private SecurityPatternNormalizer $patternNormalizer = new SecurityPatternNormalizer()) {}

    /**
     * @param array<FirewallRule> $firewalls
     */
    public function analyze(array $firewalls, RedosSeverity $threshold): SecurityFirewallReport
    {
        $findings = [];
        $skipped = [];

        foreach ($firewalls as $firewall) {
            if (null !== $firewall['requestMatcher']) {
                $skipped[] = [
                    'name' => $firewall['name'],
                    'file' => $firewall['file'],
                    'line' => $firewall['line'],
                    'reason' => 'request_matcher patterns cannot be analyzed.',
                ];

                continue;
            }

            $pattern = $firewall['pattern'];
            if (null === $pattern || '' === trim($pattern)) {
                continue;
            }

            $normalized = $this->patternNormalizer->normalize($pattern);

            try {
                $analysis = $this->regex->redos($normalized);
            } catch (\Throwable $exception) {
                $skipped[] = [
                    'name' => $firewall['name'],
                    'file' => $firewall['file'],
                    'line' => $firewall['line'],
                    'reason' => 'ReDoS analysis failed: '.$exception->getMessage(),
                ];

                continue;
            }

            if (null !== $analysis->error) {
                $skipped[] = [
                    'name' => $firewall['name'],
                    'file' => $firewall['file'],
                    'line' => $firewall['line'],
                    'reason' => $analysis->error,
                ];

                continue;
            }

            if (!$analysis->exceedsThreshold($threshold)) {
                continue;
            }

            $findings[] = [
                'name' => $firewall['name'],
                'file' => $firewall['file'],
                'line' => $firewall['line'],
                'pattern' => $pattern,
                'severity' => $analysis->severity->value,
                'score' => $analysis->score,
                'vulnerable' => $analysis->getVulnerableSubpattern(),
                'trigger' => $analysis->trigger,
            ];
        }

        $stats = [
            'firewalls' => \count($firewalls),
            'flagged' => \count($findings),
            'skipped' => \count($skipped),
        ];

        return new SecurityFirewallReport($findings, $skipped, $stats);
    }
}
