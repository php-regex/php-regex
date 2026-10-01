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
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Explain a regular expression in human-readable format.
 *
 * @internal
 */
final class ExplainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:explain
        {pattern : The regex pattern to explain}
        {--format=text : Output format (text, html)}
        {--highlight : Include syntax highlighting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Explain a regular expression in human-readable format';

    public function __construct(private readonly Regex $regex)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pattern = (string) $this->argument('pattern');
        $format = strtolower((string) $this->option('format'));
        $highlight = (bool) $this->option('highlight');

        if (!\in_array($format, ['text', 'html'], true)) {
            $this->error("Invalid format '{$format}'. Supported formats: text, html");

            return self::INVALID;
        }

        // Validate the pattern first
        $validation = $this->regex->validate($pattern);
        if (!$validation->isValid) {
            $this->error('Invalid regex pattern:');
            $this->line((string) $validation->error);
            if (null !== $validation->caretSnippet) {
                $this->line($validation->caretSnippet);
            }

            return self::FAILURE;
        }

        $this->line('<fg=cyan;options=bold>PHPRegex</> <fg=yellow>'.Regex::VERSION.'</> - Pattern Explanation');
        $this->newLine();

        // Show the pattern
        $this->line('<fg=white;options=bold>Pattern:</>');
        if ($highlight) {
            $highlighted = $this->regex->highlight($pattern, 'console');
            $this->line('  '.$highlighted);
        } else {
            $this->line('  <fg=yellow>'.$pattern.'</>');
        }
        $this->newLine();

        // Show explanation
        $this->line('<fg=white;options=bold>Explanation:</>');
        $explanation = $this->regex->explain($pattern, $format);
        $this->line($explanation);
        $this->newLine();

        // Show analysis info
        $analysis = $this->regex->analyze($pattern);

        if ($analysis->isValid) {
            // Show optimizations if available
            if ($analysis->optimizations->original !== $analysis->optimizations->optimized) {
                $this->line('<fg=white;options=bold>Optimization Suggestion:</>');
                $this->line('  <fg=red>-</> '.$analysis->optimizations->original);
                $this->line('  <fg=green>+</> '.$analysis->optimizations->optimized);
                $this->newLine();
            }

            // Show ReDoS analysis
            if (!$analysis->redos->isSafe()) {
                $this->line('<fg=white;options=bold>Security Warning:</>');
                $this->line('  <fg=red>'.$analysis->redos->headline().'</>');
                $this->line('  Severity: <fg=yellow>'.$analysis->redos->severity->value.'</>');
                if (null !== $analysis->redos->witness) {
                    $this->line('  Attack: <fg=yellow>'.OutputFormatter::escape($analysis->redos->witness->render()).'</>');
                }
                if (null !== $analysis->redos->vulnerablePart) {
                    $this->line('  Vulnerable part: <fg=yellow>'.$analysis->redos->vulnerablePart.'</>');
                }
                if (!empty($analysis->redos->recommendations)) {
                    $this->line('  Recommendations:');
                    foreach ($analysis->redos->recommendations as $recommendation) {
                        $this->line('    - '.$recommendation);
                    }
                }
                $this->newLine();
            }

            // Show lint issues
            if (!empty($analysis->lintIssues)) {
                $this->line('<fg=white;options=bold>Lint Issues:</>');
                foreach ($analysis->lintIssues as $issue) {
                    $this->line('  - '.$issue->message);
                }
                $this->newLine();
            }
        }

        // Generate sample match
        $this->line('<fg=white;options=bold>Sample Match:</>');

        try {
            $sample = $this->regex->generate($pattern);
            $this->line('  <fg=green>'.$sample.'</>');
        } catch (\Throwable) {
            $this->line('  <fg=gray>(unable to generate sample)</>');
        }
        $this->newLine();

        return self::SUCCESS;
    }
}
