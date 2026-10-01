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

namespace PhpRegex\Symfony\Analyzer;

/**
 * @internal
 */
interface AnalyzerInterface
{
    public function getId(): string;

    public function getLabel(): string;

    public function getPriority(): int;

    /**
     * @return array<int, \PhpRegex\Symfony\Analyzer\ReportSection>
     */
    public function analyze(AnalysisContext $context): array;
}
