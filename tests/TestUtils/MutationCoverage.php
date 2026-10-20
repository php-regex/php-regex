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

namespace PHPRegex\Tests\TestUtils;

/**
 * Reduces a PHPUnit XML coverage report to what a mutation run reads.
 *
 * Over the whole suite the report lists, for each line of a parser file,
 * every data set of tens of thousands of tests that reached it: some files
 * weigh a gigabyte, and the mutation tool holds the document of each file it
 * reads in memory, several at once. Reduced, the index lists only the files
 * the run mutates, and each of their lines names the test methods that reach
 * it once, whatever the number of their data sets. A mutant then runs every
 * data set of a method that reaches its line, which the tool already falls
 * back to when the list of data sets grows too long for a filter.
 */
final class MutationCoverage
{
    private const COVERAGE_NAMESPACE = 'https://schema.phpunit.de/coverage/1.0';

    /**
     * Keeps in the index of $directory only the files under $paths (files or
     * directories, relative to $projectRoot), collapses the data sets in the
     * report of each, and returns their paths relative to $projectRoot.
     *
     * With $shards above one, the files under $paths are dealt largest source
     * first, each to the shard holding the fewest bytes so far, and only those
     * of shard $shard are kept.
     *
     * @param list<string> $paths
     *
     * @throws \InvalidArgumentException when $shard is not from 1 to $shards
     * @throws \UnexpectedValueException when the report cannot be read, or
     *                                   covers a source outside $projectRoot
     *
     * @return list<string>
     */
    public static function reduce(string $directory, string $projectRoot, array $paths, int $shard = 1, int $shards = 1): array
    {
        if ($shards < 1 || $shard < 1 || $shard > $shards) {
            throw new \InvalidArgumentException(\sprintf('The shard is written K/N, K from 1 to N: %d/%d.', $shard, $shards));
        }

        $indexPath = $directory.'/index.xml';
        $index = new \DOMDocument();
        $index->preserveWhiteSpace = true;
        if (!is_file($indexPath) || !@$index->load($indexPath, \LIBXML_PARSEHUGE)) {
            throw new \UnexpectedValueException(\sprintf('Cannot read %s.', $indexPath));
        }

        $xPath = new \DOMXPath($index);
        $xPath->registerNamespace('p', self::COVERAGE_NAMESPACE);
        $source = $xPath->evaluate('string(/p:phpunit/p:project/@source)');
        $root = rtrim($projectRoot, '/').'/';
        if (!\is_string($source) || !str_starts_with(rtrim($source, '/').'/', $root)) {
            throw new \UnexpectedValueException(\sprintf('%s covers %s, not a source under %s.', $indexPath, \is_string($source) ? $source : '?', $projectRoot));
        }

        $sourcePrefix = substr(rtrim($source, '/').'/', \strlen($root));
        $candidates = [];
        $files = $xPath->query('//p:file');
        foreach (false === $files ? [] : iterator_to_array($files) as $file) {
            if (!$file instanceof \DOMElement) {
                continue;
            }

            $href = $file->getAttribute('href');
            $path = $sourcePrefix.(str_ends_with($href, '.xml') ? substr($href, 0, -4) : $href);
            if (self::isUnder($path, $paths)) {
                $candidates[$path] = $file;
            } else {
                $file->parentNode?->removeChild($file);
            }
        }

        $sizes = [];
        foreach (array_keys($candidates) as $path) {
            $size = @filesize($root.$path);
            $sizes[$path] = false === $size ? 0 : $size;
        }

        uksort($sizes, static fn (string $a, string $b): int => [$sizes[$b], $a] <=> [$sizes[$a], $b]);
        $loads = array_fill(1, $shards, 0);
        $kept = [];
        foreach ($sizes as $path => $size) {
            $lightest = (int) array_search(min($loads), $loads, true);
            $loads[$lightest] += $size;
            $file = $candidates[$path];
            if ($lightest !== $shard) {
                $file->parentNode?->removeChild($file);

                continue;
            }

            self::collapseDataSets($directory.'/'.$file->getAttribute('href'));
            $kept[] = $path;
        }

        if (false === $index->save($indexPath)) {
            throw new \UnexpectedValueException(\sprintf('Cannot write %s.', $indexPath));
        }

        sort($kept);

        return $kept;
    }

    /**
     * @param list<string> $paths
     */
    private static function isUnder(string $path, array $paths): bool
    {
        foreach ($paths as $candidate) {
            $candidate = rtrim($candidate, '/');
            if ($path === $candidate || str_starts_with($path, $candidate.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrites the report of one file line by line, as PHPUnit writes it:
     * each <covered by="Class::method#data set"/> on a line of its own, the
     * ones of a source line one after the other. Streaming keeps a report of
     * any size out of memory.
     */
    private static function collapseDataSets(string $file): void
    {
        $input = @fopen($file, 'r');
        $temporary = $file.'.reduced';
        $output = @fopen($temporary, 'w');
        if (false === $input || false === $output) {
            throw new \UnexpectedValueException(\sprintf('Cannot rewrite %s.', $file));
        }

        $seen = [];
        while (false !== ($line = fgets($input))) {
            if (1 !== preg_match('~^(\s*<covered by=")([^"#]*)(?:#[^"]*)?(".*)$~s', $line, $match)) {
                $seen = [];
                fwrite($output, $line);

                continue;
            }

            if (isset($seen[$match[2]])) {
                continue;
            }

            $seen[$match[2]] = true;
            fwrite($output, $match[1].$match[2].$match[3]);
        }

        fclose($input);
        fclose($output);

        self::assertWellFormed($temporary);
        if (!rename($temporary, $file)) {
            throw new \UnexpectedValueException(\sprintf('Cannot replace %s.', $file));
        }
    }

    private static function assertWellFormed(string $file): void
    {
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);

        try {
            $wellFormed = $reader->open($file, null, \LIBXML_PARSEHUGE);
            while ($wellFormed && $reader->read()) {
                // Reading to the end is the check.
            }

            $wellFormed = $wellFormed && [] === libxml_get_errors();
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$wellFormed) {
            @unlink($file);

            throw new \UnexpectedValueException(\sprintf('The reduced report %s is not well-formed XML.', $file));
        }
    }
}
