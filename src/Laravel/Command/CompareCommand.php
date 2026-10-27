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

namespace PHPRegex\Laravel\Command;

use Illuminate\Console\Command;
use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Minimization\MinimizationAlgorithm;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Laravel\Output\ConsoleWriter;
use PHPRegex\Linter\Console\ConsoleWriter as DocumentWriter;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Compare two regex patterns: whether they share a string, whether the
 * first one's strings are all the second one's, or whether they are equal.
 *
 * @internal
 */
final class CompareCommand extends Command
{
    use ReadsStringInput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:compare
        {pattern1 : The first regex pattern}
        {pattern2 : The second regex pattern}
        {--method=intersection : Comparison (intersection, subset, equivalence)}
        {--minimizer= : DFA minimization algorithm (hopcroft, moore); config php-regex.automata.minimization_algorithm by default}
        {--determinizer= : NFA determinization algorithm (subset, subset-indexed); config php-regex.automata.determinization_algorithm by default}
        {--format=console : Output format (console, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compare two regex patterns using automata logic';

    public function __construct(private readonly Regex $regex)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pattern1 = $this->stringArgument('pattern1');
        $pattern2 = $this->stringArgument('pattern2');
        $minimizer = strtolower($this->optionOrConfig('minimizer', 'minimization_algorithm', 'hopcroft'));
        $determinizer = strtolower($this->optionOrConfig('determinizer', 'determinization_algorithm', 'subset-indexed'));
        $method = strtolower($this->stringOption('method'));
        $format = strtolower($this->stringOption('format'));

        $json = 'json' === $format;

        // A command line it cannot use is reported before the patterns.
        if (!\in_array($method, ['intersection', 'subset', 'equivalence'], true)) {
            return $this->refuse($json, "Invalid method '{$method}'. Supported: intersection, subset, equivalence", JsonDocument::STAGE_USAGE, self::INVALID);
        }

        if (!\in_array($minimizer, ['hopcroft', 'moore'], true)) {
            return $this->refuse($json, "Invalid minimizer '{$minimizer}'. Supported: hopcroft, moore", JsonDocument::STAGE_USAGE, self::INVALID);
        }

        if (!\in_array($determinizer, ['subset', 'subset-indexed'], true)) {
            return $this->refuse($json, "Invalid determinizer '{$determinizer}'. Supported: subset, subset-indexed", JsonDocument::STAGE_USAGE, self::INVALID);
        }

        foreach (['first' => $pattern1, 'second' => $pattern2] as $which => $pattern) {
            $validation = $this->regex->validate($pattern);
            if (!$validation->isValid) {
                return $this->invalidPattern($json, $which, $validation);
            }
        }

        $startTime = microtime(true);

        try {
            $solver = new LanguageSolver($this->regex->parser());
            $options = new SolverOptions(
                minimizationAlgorithm: MinimizationAlgorithm::from($minimizer),
                determinizationAlgorithm: DeterminizationAlgorithm::from($determinizer),
            );

            $outcome = match ($method) {
                'intersection' => $this->intersection($solver, $options, $pattern1, $pattern2),
                'subset' => $this->subset($solver, $options, $pattern1, $pattern2),
                default => $this->equivalence($solver, $options, $pattern1, $pattern2),
            };
            $elapsed = microtime(true) - $startTime;

            if ($json) {
                // Every key whatever the method, in the same order, null
                // where the method fills none.
                DocumentWriter::document($this->output, JsonDocument::encode(array_replace([
                    'pattern1' => $pattern1,
                    'pattern2' => $pattern2,
                    'method' => $method,
                    'disjoint' => null,
                    'subset' => null,
                    'equivalent' => null,
                    'example' => null,
                    'counterexample' => null,
                    'left_only_example' => null,
                    'right_only_example' => null,
                    'algorithms' => [
                        'minimizer' => $minimizer,
                        'determinizer' => $determinizer,
                    ],
                    'elapsed_ms' => round($elapsed * 1000, 2),
                ], $outcome['json'])));

                return $outcome['passed'] ? self::SUCCESS : self::FAILURE;
            }

            // Console output
            $this->line('<fg=cyan;options=bold>PHPRegex</> <fg=yellow>'.Regex::VERSION.'</> - Pattern Comparison');
            $this->newLine();

            $this->line('<fg=white;options=bold>Pattern 1:</>');
            $this->line('  '.ConsoleWriter::pattern($this->regex, $this->output, $pattern1));
            $this->newLine();

            $this->line('<fg=white;options=bold>Pattern 2:</>');
            $this->line('  '.ConsoleWriter::pattern($this->regex, $this->output, $pattern2));
            $this->newLine();

            $this->line($outcome['verdict']);

            if (null !== $outcome['example']) {
                [$label, $example] = $outcome['example'];
                $this->newLine();
                $this->line('<fg=white;options=bold>'.$label.':</>');
                $this->line('  <fg=yellow>'.OutputFormatter::escape(DisplayEscaper::quote($example)).'</>');

                // Show which pattern matches
                $engine = new PcreEngine();
                $match1 = $engine->match($pattern1, $example)->matched;
                $match2 = $engine->match($pattern2, $example)->matched;

                $this->line('  Pattern 1: '.($match1 ? '<fg=green>matches</>' : '<fg=red>no match</>'));
                $this->line('  Pattern 2: '.($match2 ? '<fg=green>matches</>' : '<fg=red>no match</>'));
            }

            $this->newLine();
            $this->line('<fg=gray>Time: '.round($elapsed * 1000, 2).'ms | Method: '.$method.' | Algorithms: '.$minimizer.'/'.$determinizer.'</>');
            $this->newLine();

            return $outcome['passed'] ? self::SUCCESS : self::FAILURE;
        } catch (ComplexityException $e) {
            // A valid pattern the automata cannot read, a backreference say.
            return $this->refuse($json, $json ? $e->getMessage() : 'Comparison failed: '.$e->getMessage(), JsonDocument::STAGE_PATTERN, self::FAILURE);
        } catch (\Throwable $e) {
            // Not reached by a pattern the validation let through: a bug of
            // the solver, which the command reports as the envelope says.
            return $this->refuse($json, $json ? $e->getMessage() : 'Comparison failed: '.$e->getMessage(), JsonDocument::STAGE_INTERNAL, self::FAILURE);
        }
    }

    /**
     * A comparison that cannot be made: the error envelope in JSON mode, an
     * error line otherwise.
     */
    private function refuse(bool $json, string $message, string $stage, int $exitCode): int
    {
        if ($json) {
            DocumentWriter::document($this->output, JsonDocument::error($message, $stage));
        } else {
            $this->error($message);
        }

        return $exitCode;
    }

    /**
     * An invalid pattern stops the comparison, with everything the
     * validation found.
     */
    private function invalidPattern(bool $json, string $which, ValidationResult $validation): int
    {
        if ($json) {
            DocumentWriter::document($this->output, JsonDocument::error(
                $validation->error ?? 'Invalid pattern.',
                JsonDocument::STAGE_PATTERN,
                ['validation' => $validation],
            ));
        } else {
            $this->error('Invalid '.$which.' pattern:');
            $this->line((string) $validation->error);
        }

        return self::FAILURE;
    }

    /**
     * @return array{passed: bool, verdict: string, example: array{string, string}|null, json: array{disjoint: bool, example: string|null}}
     */
    private function intersection(LanguageSolver $solver, SolverOptions $options, string $pattern1, string $pattern2): array
    {
        $result = $solver->intersection($pattern1, $pattern2, $options);

        return [
            'passed' => $result->isEmpty,
            'verdict' => $result->isEmpty
                ? '<bg=green;fg=white;options=bold> DISJOINT </> No intersection found. These regexes are disjoint.'
                : '<bg=red;fg=white;options=bold> CONFLICT </> Conflict detected!',
            'example' => null === $result->example ? null : ['Example', $result->example],
            'json' => ['disjoint' => $result->isEmpty, 'example' => $result->example],
        ];
    }

    /**
     * @return array{passed: bool, verdict: string, example: array{string, string}|null, json: array{subset: bool, counterexample: string|null}}
     */
    private function subset(LanguageSolver $solver, SolverOptions $options, string $pattern1, string $pattern2): array
    {
        $result = $solver->subsetOf($pattern1, $pattern2, $options);

        return [
            'passed' => $result->isSubset,
            'verdict' => match (true) {
                !$result->isSubset => '<bg=red;fg=white;options=bold> NOT A SUBSET </> Pattern 1 allows strings that Pattern 2 forbids.',
                // A subset the other way too: the same strings, not a strict one.
                $solver->subsetOf($pattern2, $pattern1, $options)->isSubset => '<bg=green;fg=white;options=bold> EQUIVALENT </> The patterns match the same strings: a subset, not a strict one.',
                default => '<bg=green;fg=white;options=bold> SUBSET </> Pattern 1 is a strict subset of Pattern 2.',
            },
            'example' => null === $result->counterExample ? null : ['Counterexample', $result->counterExample],
            'json' => ['subset' => $result->isSubset, 'counterexample' => $result->counterExample],
        ];
    }

    /**
     * @return array{passed: bool, verdict: string, example: array{string, string}|null, json: array{equivalent: bool, counterexample: string|null, left_only_example: string|null, right_only_example: string|null}}
     */
    private function equivalence(LanguageSolver $solver, SolverOptions $options, string $pattern1, string $pattern2): array
    {
        $result = $solver->equivalent($pattern1, $pattern2, $options);
        $counterexample = $result->leftOnlyExample ?? $result->rightOnlyExample;

        return [
            'passed' => $result->isEquivalent,
            'verdict' => $result->isEquivalent
                ? '<bg=green;fg=white;options=bold> EQUIVALENT </> The patterns match the same strings.'
                : '<bg=red;fg=white;options=bold> NOT EQUIVALENT </> The patterns match different strings.',
            'example' => null === $counterexample ? null : ['Counterexample', $counterexample],
            'json' => [
                'equivalent' => $result->isEquivalent,
                'counterexample' => $counterexample,
                'left_only_example' => $result->leftOnlyExample,
                'right_only_example' => $result->rightOnlyExample,
            ],
        ];
    }

    /**
     * The option when given, else the automata setting of
     * config/php-regex.php, else the package default.
     */
    private function optionOrConfig(string $option, string $setting, string $default): string
    {
        $value = $this->option($option);
        if (\is_string($value) && '' !== $value) {
            return $value;
        }

        $configured = config('php-regex.automata.'.$setting);

        return \is_string($configured) && '' !== $configured ? $configured : $default;
    }
}
