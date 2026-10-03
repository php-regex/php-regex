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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\Output;
use PHPRegex\Cli\PcreRuntimeInfo;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
final readonly class LintOutputRenderer
{
    /**
     * @param array<string, int> $stats
     */
    public function renderSummary(Output $output, array $stats, bool $isEmpty = false): void
    {
        $output->write("\n");

        if ($isEmpty) {
            $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->dim('No regex patterns found.')."\n");
            $this->showFooter($output);

            return;
        }

        $errors = $stats['errors'];
        $warnings = $stats['warnings'];
        $optimizations = $stats['optimizations'];

        if ($errors > 0) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->color(\sprintf('%d invalid patterns', $errors), Output::RED.Output::BOLD)
                .$output->dim(\sprintf(', %d warnings, %d optimizations.', $warnings, $optimizations))
                ."\n");
        } elseif ($warnings > 0) {
            $output->write('  '.$output->badge('PASS', Output::BLACK, Output::BG_YELLOW).' '.$output->color(\sprintf('%d warnings found', $warnings), Output::YELLOW.Output::BOLD)
                .$output->dim(\sprintf(', %d optimizations available.', $optimizations))
                ."\n");
        } else {
            $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->color('No issues found', Output::GREEN.Output::BOLD)
                .$output->dim(\sprintf(', %d optimizations available.', $optimizations))
                ."\n");
        }

        $this->showFooter($output);
    }

    /**
     * @param array<int, string> $configFiles
     */
    public function renderBanner(Output $output, ProjectTarget $target, int $jobs = 1, array $configFiles = []): string
    {
        $version = Regex::VERSION;

        $banner = $output->color('PHPRegex', Output::CYAN.Output::BOLD).' '.$output->warning($version)." by Younes ENNAJI\n\n";

        $runtime = PcreRuntimeInfo::fromIni();
        // The running release, normalized the way every other surface spells
        // it (build date and pre-release suffix stripped): the Runtime and
        // Target rows read as parallel engine-identity lines.
        $runningPcre = PcreTarget::runtime()->pcreVersion;

        $lines = [
            'Runtime' => 'PHP '.$output->warning(\PHP_VERSION).', PCRE2 '.$output->warning($runningPcre),
            'Target' => 'PHP '.$output->warning($target->php()).', PCRE2 '.$output->warning($target->target()->pcreVersion).' ('.$target->source().')',
            'Processes' => $output->warning((string) $jobs),
        ];
        $lines['PCRE JIT'] = $output->warning($runtime->jitSetting ?? 'unknown');
        $lines['Backtrack'] = $output->warning((string) ($runtime->backtrackLimit ?? 'unknown'));
        $lines['Recursion'] = $output->warning((string) ($runtime->recursionLimit ?? 'unknown'));

        if ([] !== $configFiles) {
            $paths = array_map($this->relativePath(...), $configFiles);
            $lines['Configuration'] = implode(', ', $paths);
        }

        $maxLabelLength = max(array_map(strlen(...), array_keys($lines)));
        foreach ($lines as $label => $value) {
            $banner .= $output->bold(str_pad($label, $maxLabelLength)).' : '.$value."\n";
        }

        foreach ($target->notices() as $notice) {
            $banner .= $output->dim('Note: '.$notice)."\n";
        }

        $banner .= "\n";

        return $banner;
    }

    private function showFooter(Output $output): void
    {
        $output->write("\n");
        $message = 'If PHPRegex helps, a GitHub star is appreciated: ';
        $output->write('  '.$output->dim($message.'https://github.com/php-regex/php-regex')."\n");
        $output->write('  '.$output->dim('Cache: 0 hits, 0 misses')."\n\n");
    }

    private function relativePath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $cwd = getcwd();
        if (false === $cwd) {
            return $normalizedPath;
        }

        $normalizedCwd = rtrim(str_replace('\\', '/', $cwd), '/');
        if ('' === $normalizedCwd) {
            return $normalizedPath;
        }

        $prefix = $normalizedCwd.'/';
        if (str_starts_with($normalizedPath, $prefix)) {
            return substr($normalizedPath, \strlen($prefix));
        }

        return $normalizedPath;
    }
}
