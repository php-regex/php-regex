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

namespace PhpRegex\Redos;

use PhpRegex\Parser\Analysis\CharSetAnalyzer;
use PhpRegex\Parser\Internal\PatternParser;
use PhpRegex\Parser\RegexParser;

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
        private readonly RedosSeverity $threshold = RedosSeverity::HIGH,
        private readonly ?ConfirmationRunnerInterface $confirmationRunner = null,
    ) {
        $this->ignoredPatterns = array_values(array_unique($this->ignoredPatterns));
        $this->ignoredPatternsNormalized = $this->normalizeIgnoredPatterns($this->ignoredPatterns);
    }

    public function analyze(
        string $regex,
        ?RedosSeverity $threshold = null,
        RedosMode $mode = RedosMode::THEORETICAL,
        ?ConfirmationOptions $confirmOptions = null,
    ): RedosAnalysis {
        $threshold ??= $this->threshold;

        if (RedosMode::OFF === $mode) {
            return new RedosAnalysis(
                RedosSeverity::SAFE,
                0,
                null,
                [],
                null,
                null,
                null,
                RedosConfidence::LOW,
                null,
                [],
                null,
                null,
                [],
                RedosMode::OFF,
                null,
            );
        }

        if ($this->shouldIgnore($regex)) {
            return new RedosAnalysis(
                RedosSeverity::SAFE,
                0,
                null,
                [],
                null,
                null,
                null,
                RedosConfidence::LOW,
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
            $confidence = $result['confidence'] ?? RedosConfidence::LOW;

            $analysis = new RedosAnalysis(
                $result['severity'],
                match ($result['severity']) {
                    RedosSeverity::SAFE => 0,
                    RedosSeverity::LOW => 2,
                    RedosSeverity::MEDIUM => 5,
                    RedosSeverity::HIGH => 8,
                    RedosSeverity::CRITICAL => 10,
                    RedosSeverity::UNKNOWN => 5,
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

            if (RedosMode::CONFIRMED === $mode && $analysis->exceedsThreshold($threshold)) {
                $runner = $this->confirmationRunner ?? new ConfirmationRunner();
                $confirmation = $runner->confirm($regex, $analysis, $confirmOptions);
                $confirmedConfidence = $analysis->confidenceLevel();
                if ($confirmation->confirmed && RedosConfidence::HIGH !== $confirmedConfidence) {
                    $confirmedConfidence = RedosConfidence::HIGH;
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
                RedosSeverity::UNKNOWN,
                0,
                null,
                ['Analysis incomplete: '.$e->getMessage()],
                $e::class.': '.$e->getMessage(),
                null,
                null,
                RedosConfidence::LOW,
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
