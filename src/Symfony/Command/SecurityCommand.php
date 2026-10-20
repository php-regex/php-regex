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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigException;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The security sections of regex:analyze, on their own: the same analyzer,
 * printed the same way.
 *
 * @internal
 */
#[AsCommand(
    name: 'regex:security',
    description: 'Analyze Symfony security access_control rules and firewall regexes.',
)]
final class SecurityCommand extends Command
{
    private readonly SecurityAnalyzer $security;

    private readonly ConsoleReportFormatter $formatter;

    public function __construct(
        SecurityConfigExtractor $extractor,
        SecurityAccessControlAnalyzer $accessAnalyzer,
        SecurityFirewallAnalyzer $firewallAnalyzer,
        SecurityConfigLocator $configLocator = new SecurityConfigLocator(),
        SecurityAccessSuggestionBuilder $suggestionBuilder = new SecurityAccessSuggestionBuilder(),
        private readonly ?KernelInterface $kernel = null,
        private readonly string $defaultRedosThreshold = 'high',
    ) {
        $this->security = new SecurityAnalyzer($extractor, $configLocator, $accessAnalyzer, $firewallAnalyzer, $suggestionBuilder);
        $this->formatter = new ConsoleReportFormatter();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('show-overlaps', null, InputOption::VALUE_NONE, 'Include partial overlaps in the report.')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Security config files to analyze.',
            )
            ->addOption('skip-firewalls', null, InputOption::VALUE_NONE, 'Skip firewall regex ReDoS analysis.')
            ->addOption(
                'redos-threshold',
                null,
                InputOption::VALUE_OPTIONAL,
                'Minimum ReDoS severity to report (low|medium|high|critical).',
                $this->defaultRedosThreshold,
            )
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command inspects security access control rules and firewall regexes.

                It reads the security configuration of the application, as debug:config security shows it.
                It detects access_control shadowing (ordering issues) and can flag risky firewall regex patterns.
                Use --show-overlaps to include partial overlaps in access control.
                With -v, it also prints the counts of the analysis and why a rule or a firewall was skipped.

                <info>php %command.full_name%</info>
                <info>php %command.full_name% --show-overlaps</info>
                <info>php %command.full_name% --redos-threshold=medium</info>

                Analyze specific YAML files (needs symfony/yaml):
                <info>php %command.full_name% --config=config/packages/security.yaml</info>
                EOF);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $threshold = $this->resolveThreshold($input, $io);
        if (null === $threshold) {
            return Command::INVALID;
        }

        $context = new AnalysisContext(
            $this->kernel?->getProjectDir() ?? (getcwd() ?: null),
            $this->kernel?->getEnvironment(),
            (bool) $input->getOption('show-overlaps'),
            $this->normalizeStringList($input, 'config'),
            $threshold,
            (bool) $input->getOption('skip-firewalls'),
            $output->isVerbose(),
            $this->kernel,
        );

        try {
            $config = $this->security->read($context);
        } catch (SecurityConfigException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if (null === $config) {
            $io->error('No security configuration found: configure the SecurityBundle, or name a security file with --config.');

            return Command::INVALID;
        }

        $report = new SecurityReport($this->security->sections($config, $context));
        $this->formatter->render($report, $io, true, $context->debug);

        return $report->hasAnyIssues() ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveThreshold(InputInterface $input, SymfonyStyle $io): ?RedosSeverity
    {
        $thresholdValue = $input->getOption('redos-threshold');
        $threshold = \is_string($thresholdValue) ? trim($thresholdValue, " \t\n\r\0\x0B") : '';

        try {
            return RedosSeverity::fromConfig('' === $threshold ? $this->defaultRedosThreshold : $threshold);
        } catch (InvalidRegexOptionException $e) {
            $io->error('Invalid value for --redos-threshold: '.$e->getMessage());

            return null;
        }
    }

    /**
     * The trimmed, non-empty strings an array option was given, each once.
     *
     * @return list<string>
     */
    private function normalizeStringList(InputInterface $input, string $option): array
    {
        $values = $input->getOption($option);
        $normalized = [];
        foreach (\is_array($values) ? $values : [] as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $trimmed = trim($value, " \t\n\r\0\x0B");
            if ('' !== $trimmed) {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }
}
