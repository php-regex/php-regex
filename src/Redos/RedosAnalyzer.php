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

namespace PHPRegex\Redos;

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\RegexParser;

final class RedosAnalyzer
{
    /**
     * @var array<string>
     */
    private array $ignoredPatternsNormalized = [];

    /**
     * @param array<string> $ignoredPatterns
     */
    public function __construct(
        private readonly ?RegexParser $parser = null,
        /**
         * @var array<string>
         */
        private array $ignoredPatterns = [],
        private readonly RedosSeverity $threshold = RedosSeverity::High,
        private readonly ?ConfirmationRunnerInterface $confirmationRunner = null,
    ) {
        $this->ignoredPatterns = array_values(array_unique($this->ignoredPatterns));
        $this->ignoredPatternsNormalized = $this->normalizeIgnoredPatterns($this->ignoredPatterns);
    }

    public function analyze(
        string $regex,
        ?RedosSeverity $threshold = null,
        RedosMode $mode = RedosMode::Theoretical,
        ?ConfirmationOptions $confirmOptions = null,
    ): RedosAnalysis {
        $threshold ??= $this->threshold;

        if (RedosMode::Off === $mode) {
            return new RedosAnalysis(
                RedosSeverity::Safe,
                0,
                null,
                [],
                null,
                null,
                null,
                RedosConfidence::Low,
                null,
                [],
                null,
                null,
                [],
                RedosMode::Off,
                null,
            );
        }

        if ($this->shouldIgnore($regex)) {
            return new RedosAnalysis(
                RedosSeverity::Safe,
                0,
                null,
                [],
                null,
                null,
                null,
                RedosConfidence::Low,
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
            $ast = ($this->parser ?? RegexParser::create())->parse($regex);
            $visitor = new RedosProfiler(new CharSetAnalyzer($ast->flags));
            $ast->accept($visitor);

            $result = $visitor->getResult();
            $confidence = $result['confidence'] ?? RedosConfidence::Low;

            $analysis = new RedosAnalysis(
                $result['severity'],
                match ($result['severity']) {
                    RedosSeverity::Safe => 0,
                    RedosSeverity::Low => 2,
                    RedosSeverity::Medium => 5,
                    RedosSeverity::High => 8,
                    RedosSeverity::Critical => 10,
                    RedosSeverity::Unknown => 5,
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

            if (RedosMode::Confirmed === $mode && $analysis->exceedsThreshold($threshold)) {
                $runner = $this->confirmationRunner ?? new ConfirmationRunner();
                $confirmation = $runner->confirm($regex, $analysis, $confirmOptions);
                $confirmedConfidence = $analysis->confidenceLevel();
                if ($confirmation->confirmed && RedosConfidence::High !== $confirmedConfidence) {
                    $confirmedConfidence = RedosConfidence::High;
                }

                return new RedosAnalysis(
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
            return new RedosAnalysis(
                RedosSeverity::Unknown,
                0,
                null,
                ['Analysis incomplete: '.$e->getMessage()],
                $e::class.': '.$e->getMessage(),
                null,
                null,
                RedosConfidence::Low,
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
            [$pattern] = PatternParser::extractPatternAndFlags($regex, $this->parser?->target());

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
