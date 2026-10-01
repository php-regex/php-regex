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

namespace PhpRegex\Symfony\Analyzer;

use PhpRegex\Redos\RedosSeverity;

/**
 * @internal
 */
final readonly class AnalysisContext
{
    /**
     * @param array<int, string> $only
     * @param array<int, string> $securityConfigPaths
     */
    public function __construct(
        public ?string $projectDir,
        public ?string $environment,
        public bool $includeOverlaps = false,
        public array $only = [],
        public array $securityConfigPaths = [],
        public RedosSeverity $redosThreshold = RedosSeverity::HIGH,
        public bool $skipFirewalls = false,
        public bool $debug = false,
    ) {}
}
