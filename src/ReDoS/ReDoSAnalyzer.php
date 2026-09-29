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

namespace RegexParser\ReDoS;

use RegexParser\Internal\PatternParser;
use RegexParser\NodeVisitor\ReDoSProfileNodeVisitor;
use RegexParser\Regex;

final class ReDoSAnalyzer
{
    /**
     * @var array<string>
     */
    private array $ignoredPatternsNormalized = [];

    /**
     * @param array<string> $ignoredPatterns
     */
    public function __construct(
        private readonly ?Regex $regex = null,
        /**
         * @var array<string>
         */
        private array $ignoredPatterns = [],
        private readonly ReDoSSeverity $threshold = ReDoSSeverity::HIGH,
        private readonly ?ReDoSConfirmationRunnerInterface $confirmationRunner = null,
    ) {
        $this->ignoredPatterns = array_values(array_unique($this->ignoredPatterns));
        $this->ignoredPatternsNormalized = $this->normalizeIgnoredPatterns($this->ignoredPatterns);
    }

    public function analyze(
        string $regex,
        ?ReDoSSeverity $threshold = null,
        ReDoSMode $mode = ReDoSMode::THEORETICAL,
        ?ReDoSConfirmOptions $confirmOptions = null,
    ): ReDoSAnalysis {
        $threshold ??= $this->threshold;

        if (ReDoSMode::OFF === $mode) {
            return new ReDoSAnalysis(
                ReDoSSeverity::SAFE,
                0,
                null,
                [],
                null,
                null,
                null,
                ReDoSConfidence::LOW,
                null,
                [],
                null,
                null,
                [],
                ReDoSMode::OFF,
                null,
            );
        }

        if ($this->shouldIgnore($regex)) {
            return new ReDoSAnalysis(
                ReDoSSeverity::SAFE,
                0,
                null,
                [],
                null,
                null,
                null,
                ReDoSConfidence::LOW,
                null,
                [],
                null,
                null,
                [],
                $mode,
                null,
            );
        }

        try {
            $ast = ($this->regex ?? Regex::create())->parse($regex);
            $visitor = new ReDoSProfileNodeVisitor(new CharSetAnalyzer($ast->flags));
            $ast->accept($visitor);

            $result = $visitor->getResult();
            $confidence = $result['confidence'] ?? ReDoSConfidence::LOW;

            $analysis = new ReDoSAnalysis(
                $result['severity'],
                match ($result['severity']) {
                    ReDoSSeverity::SAFE => 0,
                    ReDoSSeverity::LOW => 2,
                    ReDoSSeverity::MEDIUM => 5,
                    ReDoSSeverity::HIGH => 8,
                    ReDoSSeverity::CRITICAL => 10,
                    ReDoSSeverity::UNKNOWN => 5,
                },
                $result['vulnerablePattern'],
                array_values($result['recommendations']),
                null,
                $result['vulnerablePattern'],
                $result['trigger'],
                $confidence,
                $result['falsePositiveRisk'],
                array_values($result['findings']),
                $result['suggestedRewrite'],
                culpritNode: $visitor->getCulpritNode(),
                hotspots: $visitor->getHotspots(),
                mode: $mode,
                confirmation: null,
            );

            if (ReDoSMode::CONFIRMED === $mode && $analysis->exceedsThreshold($threshold)) {
                $runner = $this->confirmationRunner ?? new ReDoSConfirmationRunner();
                $confirmation = $runner->confirm($regex, $analysis, $confirmOptions);
                $confirmedConfidence = $analysis->confidenceLevel();
                if ($confirmation->confirmed && ReDoSConfidence::HIGH !== $confirmedConfidence) {
                    $confirmedConfidence = ReDoSConfidence::HIGH;
                }

                return new ReDoSAnalysis(
                    $analysis->severity,
                    $analysis->score,
                    $analysis->vulnerablePart,
                    $analysis->recommendations,
                    $analysis->error,
                    $analysis->vulnerableSubpattern,
                    $analysis->trigger,
                    $confirmedConfidence,
                    $analysis->falsePositiveRisk,
                    $analysis->findings,
                    $analysis->suggestedRewrite,
                    culpritNode: $analysis->getCulpritNode(),
                    hotspots: $analysis->hotspots,
                    mode: $mode,
                    confirmation: $confirmation,
                );
            }

            return $analysis;
        } catch (\Throwable $e) {
            return new ReDoSAnalysis(
                ReDoSSeverity::UNKNOWN,
                0,
                null,
                ['Analysis incomplete: '.$e->getMessage()],
                $e::class.': '.$e->getMessage(),
                null,
                null,
                ReDoSConfidence::LOW,
                null,
                [],
                null,
                null,
                [],
                $mode,
                null,
            );
        }
    }

    private function shouldIgnore(string $regex): bool
    {
        if ([] === $this->ignoredPatterns) {
            return false;
        }

        $normalized = $this->normalizePattern($regex);

        return \in_array($normalized, $this->ignoredPatternsNormalized, true)
            || \in_array($normalized, $this->ignoredPatterns, true)
            || \in_array($regex, $this->ignoredPatterns, true);
    }

    private function normalizePattern(string $regex): string
    {
        try {
            [$pattern] = PatternParser::extractPatternAndFlags($regex, $this->regex?->target());

            return $pattern;
        } catch (\Throwable) {
            return $regex;
        }
    }

    /**
     * @param array<string> $patterns
     *
     * @return array<string>
     */
    private function normalizeIgnoredPatterns(array $patterns): array
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            $normalized[] = $this->normalizePattern($pattern);
        }

        return array_values(array_unique($normalized));
    }
}
