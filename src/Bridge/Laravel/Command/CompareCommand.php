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

namespace RegexParser\Bridge\Laravel\Command;

use Illuminate\Console\Command;
use RegexParser\Automata\Determinization\DeterminizationAlgorithm;
use RegexParser\Automata\Minimization\MinimizationAlgorithm;
use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Automata\LanguageSolver;
use RegexParser\Engine\PcreEngine;
use RegexParser\Regex;

/**
 * Compare two regex patterns for equivalence.
 */
final class CompareCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:compare
        {pattern1 : The first regex pattern}
        {pattern2 : The second regex pattern}
        {--minimizer= : DFA minimization algorithm (hopcroft, moore); config regex-parser.automata.minimization_algorithm by default}
        {--determinizer= : NFA determinization algorithm (subset, subset-indexed); config regex-parser.automata.determinization_algorithm by default}
        {--format=console : Output format (console, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compare two regex patterns for equivalence';

    public function __construct(
        private readonly Regex $regex,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pattern1 = (string) $this->argument('pattern1');
        $pattern2 = (string) $this->argument('pattern2');
        $minimizer = strtolower($this->optionOrConfig('minimizer', 'minimization_algorithm', 'hopcroft'));
        $determinizer = strtolower($this->optionOrConfig('determinizer', 'determinization_algorithm', 'subset-indexed'));
        $format = strtolower((string) $this->option('format'));

        // A command line it cannot use is reported before the patterns.
        if (!\in_array($minimizer, ['hopcroft', 'moore'], true)) {
            $this->error("Invalid minimizer '{$minimizer}'. Supported: hopcroft, moore");

            return self::INVALID;
        }

        if (!\in_array($determinizer, ['subset', 'subset-indexed'], true)) {
            $this->error("Invalid determinizer '{$determinizer}'. Supported: subset, subset-indexed");

            return self::INVALID;
        }

        // Validate patterns
        $validation1 = $this->regex->validate($pattern1);
        if (!$validation1->isValid) {
            $this->error('Invalid first pattern:');
            $this->line((string) $validation1->error);

            return self::FAILURE;
        }

        $validation2 = $this->regex->validate($pattern2);
        if (!$validation2->isValid) {
            $this->error('Invalid second pattern:');
            $this->line((string) $validation2->error);

            return self::FAILURE;
        }

        $startTime = microtime(true);

        try {
            $solver = new LanguageSolver($this->regex->parser());
            $options = new SolverOptions(
                minimizationAlgorithm: MinimizationAlgorithm::from($minimizer),
                determinizationAlgorithm: DeterminizationAlgorithm::from($determinizer),
            );

            $result = $solver->equivalent($pattern1, $pattern2, $options);
            $elapsed = microtime(true) - $startTime;

            if ('json' === $format) {
                $counterexample = $result->leftOnlyExample ?? $result->rightOnlyExample;
                $this->output->writeln((string) json_encode([
                    'pattern1' => $pattern1,
                    'pattern2' => $pattern2,
                    'equivalent' => $result->isEquivalent,
                    'counterexample' => $counterexample,
                    'leftOnlyExample' => $result->leftOnlyExample,
                    'rightOnlyExample' => $result->rightOnlyExample,
                    'algorithms' => [
                        'minimizer' => $minimizer,
                        'determinizer' => $determinizer,
                    ],
                    'elapsed_ms' => round($elapsed * 1000, 2),
                ], \JSON_PRETTY_PRINT));

                return $result->isEquivalent ? self::SUCCESS : self::FAILURE;
            }

            // Console output
            $this->line('<fg=cyan;options=bold>RegexParser</> <fg=yellow>'.Regex::VERSION.'</> - Pattern Comparison');
            $this->newLine();

            $this->line('<fg=white;options=bold>Pattern 1:</>');
            $this->line('  '.$this->regex->highlight($pattern1, 'console'));
            $this->newLine();

            $this->line('<fg=white;options=bold>Pattern 2:</>');
            $this->line('  '.$this->regex->highlight($pattern2, 'console'));
            $this->newLine();

            if ($result->isEquivalent) {
                $this->line('<bg=green;fg=white;options=bold> EQUIVALENT </> The patterns match the same strings.');
            } else {
                $this->line('<bg=red;fg=white;options=bold> NOT EQUIVALENT </> The patterns match different strings.');

                $counterexample = $result->leftOnlyExample ?? $result->rightOnlyExample;
                if (null !== $counterexample) {
                    $this->newLine();
                    $this->line('<fg=white;options=bold>Counterexample:</>');
                    $this->line('  <fg=yellow>'.$counterexample.'</>');

                    // Show which pattern matches
                    $engine = new PcreEngine();
                    $match1 = $engine->match($pattern1, $counterexample)->matched;
                    $match2 = $engine->match($pattern2, $counterexample)->matched;

                    $this->line('  Pattern 1: '.($match1 ? '<fg=green>matches</>' : '<fg=red>no match</>'));
                    $this->line('  Pattern 2: '.($match2 ? '<fg=green>matches</>' : '<fg=red>no match</>'));
                }
            }

            $this->newLine();
            $this->line('<fg=gray>Time: '.round($elapsed * 1000, 2).'ms | Algorithms: '.$minimizer.'/'.$determinizer.'</>');
            $this->newLine();

            return $result->isEquivalent ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            if ('json' === $format) {
                $this->output->writeln((string) json_encode([
                    'error' => $e->getMessage(),
                    'pattern1' => $pattern1,
                    'pattern2' => $pattern2,
                ], \JSON_PRETTY_PRINT));
            } else {
                $this->error('Comparison failed: '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * The option when given, else the automata setting of
     * config/regex-parser.php, else the package default.
     */
    private function optionOrConfig(string $option, string $setting, string $default): string
    {
        $value = $this->option($option);
        if (\is_string($value) && '' !== $value) {
            return $value;
        }

        $configured = config('regex-parser.automata.'.$setting);

        return \is_string($configured) && '' !== $configured ? $configured : $default;
    }
}
