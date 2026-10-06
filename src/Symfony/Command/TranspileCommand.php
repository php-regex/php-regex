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

namespace PHPRegex\Symfony\Command;

use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Target\TargetRegistry;
use PHPRegex\Transpiler\TranspileException;
use PHPRegex\Transpiler\Transpiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'regex:transpile',
    description: 'Transpile PCRE regex to other dialects.',
)]
final class TranspileCommand extends Command
{
    public function __construct(private readonly Regex $regex)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pattern', InputArgument::REQUIRED, 'The PCRE pattern to transpile.')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Target dialect (js, python)', 'js')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (console, json)', 'console');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');
        $json = \is_string($format) && 'json' === strtolower($format);
        if (!$json && (!\is_string($format) || 'console' !== strtolower($format))) {
            // As the CLI: an unknown format is a usage error, and JSON was not asked for.
            $io->error(\sprintf('Invalid value for --format: %s. Use console or json.', \is_string($format) ? $format : ''));

            return Command::INVALID;
        }

        $pattern = $input->getArgument('pattern');
        if (!\is_string($pattern)) {
            return $this->fail($output, $io, $json, 'Pattern must be a string.', JsonDocument::STAGE_USAGE, Command::INVALID);
        }

        $target = $input->getOption('target');
        if (!\is_string($target)) {
            $target = 'js'; // Default fallback if something weird happens, though definition says default is 'js'
        }

        try {
            (new TargetRegistry())->get($target);
        } catch (TranspileException $e) {
            return $this->fail($output, $io, $json, $e->getMessage(), JsonDocument::STAGE_USAGE, Command::INVALID);
        }

        // An invalid pattern stops here, with everything the validation
        // found: the transpiler would only throw its first parse error.
        $validation = $this->regex->validate($pattern);
        if (!$validation->isValid) {
            $message = $validation->error ?? 'Invalid pattern.';
            if ($json) {
                $this->writeDocument($output, JsonDocument::error($message, JsonDocument::STAGE_PATTERN, ['validation' => $validation]));

                return Command::FAILURE;
            }

            $io->error(null === $validation->caretSnippet ? $message : [$message, $validation->caretSnippet]);

            return Command::FAILURE;
        }

        try {
            $transpiler = new Transpiler($this->regex->parser());
            $result = $transpiler->transpile($pattern, $target);
        } catch (LexerException|ParserException|TranspileException $e) {
            // A valid pattern the target cannot express.
            return $this->fail($output, $io, $json, $e->getMessage(), JsonDocument::STAGE_PATTERN, Command::FAILURE);
        }

        if ($json) {
            $this->writeDocument($output, JsonDocument::encode($result->jsonSerialize()));

            return Command::SUCCESS;
        }

        $io->title('Transpilation Result');

        $io->text('<info>Target:</info> '.strtoupper($result->target));
        $io->text('<info>Source:</info> '.$result->source);
        $io->newLine();

        $io->section('Literal');
        $io->text('    '.$result->literal);

        $io->section('Constructor');
        $io->text('    '.$result->constructor);

        if ($result->hasWarnings()) {
            $io->warning($result->warnings);
        }

        if ($result->hasNotes()) {
            $io->note($result->notes);
        }

        return Command::SUCCESS;
    }

    /**
     * A failure: the error envelope in JSON mode, an error block otherwise.
     */
    private function fail(OutputInterface $output, SymfonyStyle $io, bool $json, string $message, string $stage, int $exitCode): int
    {
        if ($json) {
            $this->writeDocument($output, JsonDocument::error($message, $stage));
        } else {
            $io->error($message);
        }

        return $exitCode;
    }

    /**
     * The document as it is, never read for console tags, and written even
     * under --quiet: it is the output asked for, not a status line.
     */
    private function writeDocument(OutputInterface $output, string $document): void
    {
        $output->write($document, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }
}
