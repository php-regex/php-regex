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

namespace PhpRegex\Laravel\Command;

use Illuminate\Console\Command;
use PhpRegex\Toolkit\Regex;
use PhpRegex\Transpiler\TranspileOptions;

/**
 * Transpile a PCRE regex to another dialect.
 */
final class TranspileCommand extends Command
{
    /**
     * Supported target dialects.
     */
    private const SUPPORTED_TARGETS = [
        'javascript',
        'python',
        'ruby',
        'go',
        'rust',
        'java',
        'csharp',
        'swift',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:transpile
        {pattern : The regex pattern to transpile}
        {--target=javascript : Target dialect (javascript, python, ruby, go, rust, java, csharp, swift)}
        {--format=console : Output format (console, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transpile a PCRE regex to another dialect';

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
        $target = strtolower((string) $this->option('target'));
        $format = strtolower((string) $this->option('format'));

        // Validate target
        if (!\in_array($target, self::SUPPORTED_TARGETS, true)) {
            $this->error(\sprintf(
                "Invalid target '%s'. Supported targets: %s",
                $target,
                implode(', ', self::SUPPORTED_TARGETS),
            ));

            return self::INVALID;
        }

        // Validate pattern
        $validation = $this->regex->validate($pattern);
        if (!$validation->isValid) {
            if ('json' === $format) {
                $this->output->writeln((string) json_encode([
                    'error' => 'Invalid pattern',
                    'details' => $validation->error,
                    'snippet' => $validation->caretSnippet,
                ], \JSON_PRETTY_PRINT));
            } else {
                $this->error('Invalid pattern:');
                $this->line((string) $validation->error);
                if (null !== $validation->caretSnippet) {
                    $this->line($validation->caretSnippet);
                }
            }

            return self::FAILURE;
        }

        try {
            $options = new TranspileOptions();

            $result = $this->regex->transpile($pattern, $target, $options);

            if ('json' === $format) {
                $this->output->writeln((string) json_encode([
                    'source' => $pattern,
                    'target' => $target,
                    'result' => $result->pattern,
                    'flags' => $result->flags,
                    'warnings' => $result->warnings,
                    'compatible' => !$result->hasWarnings(),
                ], \JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            // Console output
            $this->line('<fg=cyan;options=bold>RegexParser</> <fg=yellow>'.Regex::VERSION.'</> - Pattern Transpilation');
            $this->newLine();

            $this->line('<fg=white;options=bold>Source (PCRE):</>');
            $this->line('  '.$this->regex->highlight($pattern, 'console'));
            $this->newLine();

            $this->line('<fg=white;options=bold>Target ('.ucfirst($target).'):</>');
            $targetPattern = $result->pattern;
            if ('' !== $result->flags) {
                $targetPattern .= ' <fg=gray>(flags: '.$result->flags.')</>';
            }
            $this->line('  <fg=green>'.$targetPattern.'</>');
            $this->newLine();

            if (!empty($result->warnings)) {
                $this->line('<fg=white;options=bold>Warnings:</>');
                foreach ($result->warnings as $warning) {
                    $this->line('  <fg=yellow>⚠</> '.$warning);
                }
                $this->newLine();
            }

            if (!$result->hasWarnings()) {
                $this->line('<bg=green;fg=white;options=bold> COMPATIBLE </> Pattern transpiled successfully.');
            } else {
                $this->line('<bg=yellow;fg=black;options=bold> PARTIAL </> Pattern transpiled with limitations.');
            }
            $this->newLine();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ('json' === $format) {
                $this->output->writeln((string) json_encode([
                    'error' => 'Transpilation failed',
                    'details' => $e->getMessage(),
                ], \JSON_PRETTY_PRINT));
            } else {
                $this->error('Transpilation failed: '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
