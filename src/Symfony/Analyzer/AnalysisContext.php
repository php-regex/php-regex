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

namespace PHPRegex\Symfony\Analyzer;

use PHPRegex\Redos\RedosSeverity;

/**
 * @internal
 */
final class AnalysisContext
{
    /**
     * @param array<int, string> $only
     * @param array<int, string> $securityConfigPaths
     */
    public function __construct(
        public readonly ?string $projectDir,
        public readonly ?string $environment,
        public readonly bool $includeOverlaps = false,
        public readonly array $only = [],
        public readonly array $securityConfigPaths = [],
        public readonly RedosSeverity $redosThreshold = RedosSeverity::High,
        public readonly bool $skipFirewalls = false,
        public readonly bool $debug = false,
    ) {}
}
