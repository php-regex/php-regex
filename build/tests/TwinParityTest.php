<?php

declare(strict_types=1);

namespace PHPRegex\Build\Tests;

use PHPRegex\Build\Verify\Witness;
use PHPUnit\Framework\TestCase;

/**
 * The twin reads every pattern of the reference as the original does, for
 * every target: the tokens, the tree, the validation, the capture shapes
 * and every other reading Witness takes. On PHP 8.1 and later the reference
 * is written by the original on the same PHP, and every reading must be the
 * same; on PHP 7.4 and 8.0, older than the original runs, the reference is
 * the original's on PHP 8.1, and only the patterns engine-dependent.php
 * names may differ.
 */
final class TwinParityTest extends TestCase
{
    private const CHUNK = 200;

    /**
     * @return iterable<string, array{int, int}>
     */
    public function provideChunks(): iterable
    {
        $count = \count(Reference::patterns()['patterns']);
        for ($start = 0; $start < $count; $start += self::CHUNK) {
            $end = min($count, $start + self::CHUNK);

            yield sprintf('patterns %d to %d', $start, $end - 1) => [$start, $end];
        }
    }

    /**
     * @dataProvider provideChunks
     */
    public function test_the_twin_reads_each_pattern_as_the_original(int $start, int $end): void
    {
        $targets = Reference::patterns()['targets'];
        $engineDependent = \PHP_VERSION_ID < 80100 ? require __DIR__.'/engine-dependent.php' : [];
        $differences = [];

        for ($index = $start; $index < $end; $index++) {
            $pattern = Reference::pattern($index);
            $reference = Reference::witnesses($index);
            $patternDiffers = false;

            foreach ($targets as $target => $settings) {
                $witness = Witness::of($pattern, $settings);
                foreach ($reference[$target] as $reading => $hash) {
                    $read = Witness::encode($witness[$reading] ?? null);
                    if (md5($read) !== $hash) {
                        $patternDiffers = true;
                        $differences[] = sprintf('#%d %s for %s: %s reads %s', $index, json_encode($pattern, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), json_encode($settings), $reading, substr($read, 0, 400));
                    }
                }

                foreach (array_diff_key($witness, $reference[$target]) as $reading => $value) {
                    $patternDiffers = true;
                    $differences[] = sprintf('#%d for %s: the twin reads %s, the original does not', $index, json_encode($settings), $reading);
                }
            }

            if (isset($engineDependent[$pattern])) {
                if ($patternDiffers) {
                    $differences = array_values(array_filter($differences, static function (string $difference) use ($index): bool {
                        return 0 !== strpos($difference, '#'.$index.' ');
                    }));
                } else {
                    $differences[] = sprintf('#%d %s reads as the original now: remove it from engine-dependent.php', $index, $pattern);
                }
            }
        }

        $this->assertSame([], $differences, implode("\n", \array_slice($differences, 0, 20)));
    }
}
