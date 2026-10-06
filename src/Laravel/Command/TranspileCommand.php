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
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Target\TargetRegistry;
use PHPRegex\Transpiler\TranspileException;
use PHPRegex\Transpiler\TranspileOptions;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Transpile a PCRE regex to another dialect.
 *
 * @internal
 */
final class TranspileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:transpile
        {pattern : The regex pattern to transpile}
        {--target=javascript : Target dialect (javascript, js, python, py)}
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
        if ('json' !== $format && 'console' !== $format) {
            // As the CLI: an unknown format is a usage error, and JSON was not asked for.
            $this->error(\sprintf('Invalid value for --format: %s. Use console or json.', (string) $this->option('format')));

            return self::INVALID;
        }

        // The transpiler's own targets, aliases included, as the regex
        // command reads them; the JSON envelope carries its very message.
        $registry = new TargetRegistry();

        try {
            $registry->get($target);
        } catch (TranspileException $e) {
            if ('json' === $format) {
                $this->writeDocument(JsonDocument::error($e->getMessage(), JsonDocument::STAGE_USAGE));
            } else {
                $this->error(\sprintf(
                    "Invalid target '%s'. Supported targets: %s",
                    $target,
                    implode(', ', $registry->listTargets()),
                ));
            }

            return self::INVALID;
        }

        // An invalid pattern stops here, with everything the validation
        // found: the transpiler would only throw its first parse error.
        $validation = $this->regex->validate($pattern);
        if (!$validation->isValid) {
            if ('json' === $format) {
                $this->writeDocument(JsonDocument::error(
                    $validation->error ?? 'Invalid pattern.',
                    JsonDocument::STAGE_PATTERN,
                    ['validation' => $validation],
                ));
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
                $this->writeDocument(JsonDocument::encode($result->jsonSerialize()));

                return self::SUCCESS;
            }

            // Console output
            $this->line('<fg=cyan;options=bold>PHPRegex</> <fg=yellow>'.Regex::VERSION.'</> - Pattern Transpilation');
            $this->newLine();

            $this->line('<fg=white;options=bold>Source (PCRE):</>');
            $this->line('  '.$this->regex->highlight($pattern, 'console'));
            $this->newLine();

            $this->line('<fg=white;options=bold>Target ('.ucfirst($result->target).'):</>');
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
        } catch (LexerException|ParserException|TranspileException $e) {
            // A valid pattern the target cannot express.
            if ('json' === $format) {
                $this->writeDocument(JsonDocument::error($e->getMessage(), JsonDocument::STAGE_PATTERN));
            } else {
                $this->error('Transpilation failed: '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * The document as it is, never read for console tags, and written even
     * under --quiet: it is the output asked for, not a status line.
     */
    private function writeDocument(string $document): void
    {
        $this->output->write($document, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }
}
