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

namespace PhpRegex\Cli\Command;

use PhpRegex\Cli\Output;
use PhpRegex\Cli\PcreRuntimeInfo;
use PhpRegex\Toolkit\Regex;

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
    public function renderBanner(Output $output, int $jobs = 1, array $configFiles = []): string
    {
        $version = Regex::VERSION;

        $banner = $output->color('RegexParser', Output::CYAN.Output::BOLD).' '.$output->warning($version)." by Younes ENNAJI\n\n";

        $lines = [
            'Runtime' => 'PHP '.$output->warning(\PHP_VERSION),
            'Processes' => $output->warning((string) $jobs),
        ];
        $runtime = PcreRuntimeInfo::fromIni();
        $lines['PCRE'] = $output->warning($runtime->version);
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

        $banner .= "\n";

        return $banner;
    }

    private function showFooter(Output $output): void
    {
        $output->write("\n");
        $message = 'If RegexParser helps, a GitHub star is appreciated: ';
        $output->write('  '.$output->dim($message.'https://github.com/php-regex/regex-parser')."\n");
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
