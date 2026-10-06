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

namespace PHPRegex\Tests\Support;

/**
 * The documentation pages: the root README.md and CONTRIBUTING.md, every
 * Markdown file under docs/ and every package README (src/<Package>/README.md).
 * Paths are relative to the repository root. CHANGELOG.md and UPGRADE-2.0.md are
 * history and are not among them.
 */
final class DocumentationPages
{
    public const ROOT = __DIR__.'/../..';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $pages = ['README.md', 'CONTRIBUTING.md'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT.'/docs', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'md' === $file->getExtension()) {
                $pages[] = 'docs/'.str_replace('\\', '/', substr($file->getPathname(), \strlen(self::ROOT.'/docs/')));
            }
        }

        foreach (glob(self::ROOT.'/src/*/README.md') ?: [] as $readme) {
            $pages[] = substr($readme, \strlen(self::ROOT.'/'));
        }

        sort($pages);

        return $pages;
    }

    /**
     * The ```php fences of a page: their code, the page line of the code's
     * first line, and the prose line closest above the fence.
     *
     * @return list<array{code: string, line: int, intro: string}>
     */
    public static function phpFences(string $page): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents(self::ROOT.'/'.$page)) ?: [];

        $fences = [];
        $intro = '';
        $state = 'prose';
        $code = [];
        $first = 0;
        $fence = '';

        foreach ($lines as $number => $line) {
            if ('prose' === $state) {
                if (1 === preg_match('/^\s*(`{3,}|~{3,})\s*(\S*)/', $line, $open)) {
                    // A ```php fence is read; any other fence is skipped to its end.
                    $state = 'php' === strtolower($open[2]) ? 'php' : 'other';
                    $fence = $open[1];
                    $code = [];
                    $first = $number + 2;
                } elseif ('' !== trim($line)) {
                    $intro = $line;
                }

                continue;
            }

            if (1 === preg_match('/^\s*'.preg_quote($fence[0], '/').'{'.\strlen($fence).',}\s*$/', $line)) {
                if ('php' === $state) {
                    $fences[] = ['code' => implode("\n", $code), 'line' => $first, 'intro' => $intro];
                }
                $state = 'prose';
                $intro = '';

                continue;
            }

            if ('php' === $state) {
                $code[] = $line;
            }
        }

        return $fences;
    }
}
