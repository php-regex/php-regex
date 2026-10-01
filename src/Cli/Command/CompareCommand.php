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

namespace PhpRegex\Cli\Command;

use PhpRegex\Automata\Determinization\DeterminizationAlgorithm;
use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Minimization\MinimizationAlgorithm;
use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Cli\ConsoleStyle;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Parser\Internal\DisplayEscaper;

final class CompareCommand extends AbstractCommand
{
    private const METHOD_INTERSECTION = 'intersection';
    private const METHOD_SUBSET = 'subset';
    private const METHOD_EQUIVALENCE = 'equivalence';

    public function getName(): string
    {
        return 'compare';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Compare two regex patterns using automata logic';
    }

    public function run(Input $input, Output $output): int
    {
        $parsed = $this->parseArguments($input->args);
        if (null !== $parsed['error']) {
            $output->write($output->error('Error: '.$parsed['error']."\n"));
            $output->write("Usage: regex compare <pattern1> <pattern2> [--method intersection|subset|equivalence] [--minimizer hopcroft|moore] [--determinizer subset|subset-indexed]\n");

            return self::INVALID;
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $solver = new LanguageSolver($regex->parser());
        $options = new SolverOptions(
            matchMode: MatchMode::Full,
            minimizationAlgorithm: MinimizationAlgorithm::from($parsed['minimizer']),
            determinizationAlgorithm: DeterminizationAlgorithm::from($parsed['determinizer']),
        );

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [
            'Method' => $output->warning($parsed['method']),
            'Minimizer' => $output->warning($parsed['minimizer']),
            'Determinizer' => $output->warning($parsed['determinizer']),
        ];
        $meta += $this->targetMeta($input, $output);

        $style->renderBanner('compare', $meta, 'Automata-based regex comparison.');
        $style->renderPattern($parsed['pattern1'], 'Pattern 1');
        $style->renderPattern($parsed['pattern2'], 'Pattern 2');
        $output->write("\n");

        try {
            return match ($parsed['method']) {
                self::METHOD_INTERSECTION => $this->handleIntersection($solver, $options, $parsed, $output),
                self::METHOD_SUBSET => $this->handleSubset($solver, $options, $parsed, $output),
                self::METHOD_EQUIVALENCE => $this->handleEquivalence($solver, $options, $parsed, $output),
                default => self::INVALID,
            };
        } catch (ComplexityException) {
            $output->write($output->error('Comparison not supported: Pattern contains advanced features (e.g., lookarounds).')."\n");

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $output->write($output->error('Comparison failed: '.$exception->getMessage())."\n");

            return self::FAILURE;
        }
    }

    /**
     * @param array{pattern1: string, pattern2: string, method: string, minimizer: string, determinizer: string, error: ?string} $parsed
     */
    private function handleIntersection(LanguageSolver $solver, SolverOptions $options, array $parsed, Output $output): int
    {
        $result = $solver->intersection($parsed['pattern1'], $parsed['pattern2'], $options);

        if ($result->isEmpty) {
            $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->success('No intersection found. These regexes are disjoint.')."\n");

            return self::SUCCESS;
        }

        $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Conflict detected!')."\n");
        $output->write("\n");
        $this->writeDetail($output, 'Example', $this->formatExample($result->example ?? ''));

        return self::FAILURE;
    }

    /**
     * @param array{pattern1: string, pattern2: string, method: string, minimizer: string, determinizer: string, error: ?string} $parsed
     */
    private function handleSubset(LanguageSolver $solver, SolverOptions $options, array $parsed, Output $output): int
    {
        $result = $solver->subsetOf($parsed['pattern1'], $parsed['pattern2'], $options);

        if ($result->isSubset) {
            $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->success('Pattern 1 is a strict subset of Pattern 2.')."\n");

            return self::SUCCESS;
        }

        $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Pattern 1 allows strings that Pattern 2 forbids.')."\n");
        $output->write("\n");
        $this->writeDetail($output, 'Counter-example', $this->formatExample($result->counterExample ?? ''));

        return self::FAILURE;
    }

    /**
     * @param array{pattern1: string, pattern2: string, method: string, minimizer: string, determinizer: string, error: ?string} $parsed
     */
    private function handleEquivalence(LanguageSolver $solver, SolverOptions $options, array $parsed, Output $output): int
    {
        $result = $solver->equivalent($parsed['pattern1'], $parsed['pattern2'], $options);

        if ($result->isEquivalent) {
            $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->success('Patterns are mathematically equivalent.')."\n");

            return self::SUCCESS;
        }

        $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Patterns are different.')."\n");
        $hasDetails = null !== $result->leftOnlyExample || null !== $result->rightOnlyExample;
        if ($hasDetails) {
            $output->write("\n");
        }
        if (null !== $result->leftOnlyExample) {
            $this->writeDetail($output, 'Pattern 1 only', $this->formatExample($result->leftOnlyExample));
        }
        if (null !== $result->rightOnlyExample) {
            $this->writeDetail($output, 'Pattern 2 only', $this->formatExample($result->rightOnlyExample));
        }

        return self::FAILURE;
    }

    private function formatExample(string $example): string
    {
        return DisplayEscaper::quote($example);
    }

    private function writeDetail(Output $output, string $label, string $value): void
    {
        $labelText = $label.':';
        $paddedLabel = \str_pad($labelText, 18);
        $output->write('  '.$output->bold($paddedLabel).' '.$value."\n");
    }

    /**
     * @param array<int, string> $args
     *
     * @return array{pattern1: string, pattern2: string, method: string, minimizer: string, determinizer: string, error: ?string}
     */
    private function parseArguments(array $args): array
    {
        $method = self::METHOD_INTERSECTION;
        $minimizer = MinimizationAlgorithm::Hopcroft->value;
        $determinizer = DeterminizationAlgorithm::SubsetIndexed->value;
        $patterns = [];
        $stopParsing = false;

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if (!$stopParsing && '--' === $arg) {
                $stopParsing = true;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--method=')) {
                $method = \strtolower(substr($arg, \strlen('--method=')));

                continue;
            }

            if (!$stopParsing && '--method' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorResult('Missing value for --method.');
                }
                $method = \strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--minimizer=')) {
                $minimizer = \strtolower(substr($arg, \strlen('--minimizer=')));

                continue;
            }

            if (!$stopParsing && '--minimizer' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorResult('Missing value for --minimizer.');
                }
                $minimizer = \strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--determinizer=')) {
                $determinizer = \strtolower(substr($arg, \strlen('--determinizer=')));

                continue;
            }

            if (!$stopParsing && '--determinizer' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorResult('Missing value for --determinizer.');
                }
                $determinizer = \strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--')) {
                return $this->errorResult('Unknown option: '.$arg);
            }

            $patterns[] = $arg;
        }

        if (!\in_array($method, [self::METHOD_INTERSECTION, self::METHOD_SUBSET, self::METHOD_EQUIVALENCE], true)) {
            return $this->errorResult('Invalid value for --method.');
        }

        if (!\in_array($minimizer, [MinimizationAlgorithm::Hopcroft->value, MinimizationAlgorithm::Moore->value], true)) {
            return $this->errorResult('Invalid value for --minimizer. Use hopcroft or moore.');
        }

        if (!\in_array($determinizer, [DeterminizationAlgorithm::Subset->value, DeterminizationAlgorithm::SubsetIndexed->value], true)) {
            return $this->errorResult('Invalid value for --determinizer. Use subset or subset-indexed.');
        }

        if (\count($patterns) < 2) {
            return $this->errorResult('Missing required patterns.');
        }

        if (\count($patterns) > 2) {
            return $this->errorResult('Too many arguments provided.');
        }

        return [
            'pattern1' => $patterns[0],
            'pattern2' => $patterns[1],
            'method' => $method,
            'minimizer' => $minimizer,
            'determinizer' => $determinizer,
            'error' => null,
        ];
    }

    /**
     * @return array{pattern1: string, pattern2: string, method: string, minimizer: string, determinizer: string, error: string}
     */
    private function errorResult(string $error): array
    {
        return [
            'pattern1' => '',
            'pattern2' => '',
            'method' => self::METHOD_INTERSECTION,
            'minimizer' => MinimizationAlgorithm::Hopcroft->value,
            'determinizer' => DeterminizationAlgorithm::SubsetIndexed->value,
            'error' => $error,
        ];
    }
}
