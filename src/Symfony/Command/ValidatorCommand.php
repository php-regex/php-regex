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

use PHPRegex\Linter\Console\ConsoleWriter;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Symfony\Analyzer\ValidatorAnalyzer;
use PHPRegex\Symfony\Validator\ValidatedClassLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The validator section of regex:analyze, on its own: the same audit,
 * printed the same way, for the classes given or every class the
 * validator maps with a Regex constraint.
 *
 * @internal
 */
#[AsCommand(
    name: 'regex:validator',
    description: 'Audit the Regex constraints of validated classes against their HTML pattern and their property.',
)]
final class ValidatorCommand extends Command
{
    private readonly ConsoleReportFormatter $consoleFormatter;

    private readonly JsonReportFormatter $jsonFormatter;

    public function __construct(private readonly ValidatedClassLocator $locator, private readonly ?KernelInterface $kernel = null)
    {
        $this->consoleFormatter = new ConsoleReportFormatter();
        $this->jsonFormatter = new JsonReportFormatter();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('classes', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The classes to audit; by default those the mapping files name and those src/ declares with a Regex constraint.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (console, json).', 'console')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command audits each Regex constraint of the validated classes:

                  * the HTML pattern a form gives the browser: whether getHtmlPattern() drops it (flags, paired delimiters),
                    whether a browser compiles it under the v flag, and whether the browser accepts exactly the values
                    the server accepts, with an example of each difference and a suggested htmlPattern, and whether the
                    solver proved it matches the same values;
                  * the other constraints of the property: a Length no non-empty value of the pattern fits, a Choice
                    value the pattern rejects, two Regex constraints no non-empty value passes together.

                It needs the validator service of Symfony Validator, and exits with code 2 without it.

                Without classes it reads those the validator mapping files name and those src/ declares with a Regex constraint.
                It names each class or mapping file it could not read, and says the audit is incomplete; with -v,
                it also prints the counts.

                <info>php %command.full_name%</info>
                <info>php %command.full_name% 'App\Entity\User'</info>
                <info>php %command.full_name% --format=json</info>
                EOF);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');
        $json = 'json' === $format;
        if (!$json && 'console' !== $format) {
            $io->error('The --format value must be one of: console, json.');

            return Command::INVALID;
        }

        if (!$this->locator->isSupported()) {
            $message = 'Validator service is not available. Install Symfony Validator or enable the validator service.';
            if ($json) {
                ConsoleWriter::document($output, JsonDocument::error($message, JsonDocument::STAGE_CONFIG));
            } else {
                $io->error($message);
            }

            return Command::INVALID;
        }

        /** @var list<string> $classes */
        $classes = array_values(array_filter((array) $input->getArgument('classes'), \is_string(...)));
        $context = new AnalysisContext($this->kernel?->getProjectDir() ?? (getcwd() ?: null), $this->kernel?->getEnvironment(), debug: $output->isVerbose());
        $report = new SecurityReport((new ValidatorAnalyzer($this->locator, classes: $classes))->analyze($context));

        if ($json) {
            ConsoleWriter::document($output, $this->jsonFormatter->format($report, $context->debug));
        } else {
            $this->consoleFormatter->render($report, $io, true, $context->debug);
        }

        return $report->hasSeverity(CheckOutcome::Fail) ? Command::FAILURE : Command::SUCCESS;
    }
}
