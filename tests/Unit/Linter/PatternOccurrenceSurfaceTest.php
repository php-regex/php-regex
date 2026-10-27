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

namespace PHPRegex\Tests\Unit\Linter;

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\HelperCallExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An extractor a user writes gives occurrences through
 * PatternOccurrence::found(): the pattern, where it stands, and the call
 * that holds it. The rest of the occurrence is the library's own.
 */
final class PatternOccurrenceSurfaceTest extends TestCase
{
    #[Test]
    public function test_found_occurrence_holds_where_the_pattern_stands(): void
    {
        $occurrence = PatternOccurrence::found('/a+/', '/app/src/Check.php', 12, 9, 'check()');

        $this->assertSame('/a+/', $occurrence->pattern);
        $this->assertSame('/app/src/Check.php', $occurrence->file);
        $this->assertSame(12, $occurrence->line);
        $this->assertSame(9, $occurrence->column);
        $this->assertSame('check()', $occurrence->source);
        $this->assertFalse($occurrence->isIgnored);
        $this->assertNull($occurrence->ignores);
        $this->assertNull($occurrence->unread);
        $this->assertNull($occurrence->parserFallback);
        $this->assertNull($occurrence->guard);
        $this->assertSame([], $occurrence->targets);
    }

    #[Test]
    public function test_found_occurrence_defaults_to_no_column_and_a_php_source(): void
    {
        $occurrence = PatternOccurrence::found('/a+/', '/app/src/Check.php', 3);

        $this->assertNull($occurrence->column);
        $this->assertNull($occurrence->fileOffset);
        $this->assertSame('php', $occurrence->source);
    }

    #[Test]
    public function test_full_constructor_stays_internal(): void
    {
        $this->assertStringContainsString('@internal', (string) (new \ReflectionMethod(PatternOccurrence::class, '__construct'))->getDocComment());
        $this->assertStringNotContainsString('@internal', (string) (new \ReflectionMethod(PatternOccurrence::class, 'found'))->getDocComment());
    }

    #[Test]
    public function test_extractor_returns_a_list_of_occurrences(): void
    {
        $this->assertStringContainsString('@return list<PatternOccurrence>', (string) (new \ReflectionMethod(ExtractorInterface::class, 'extract'))->getDocComment());

        $file = tempnam(sys_get_temp_dir(), 'regex-extractor-');
        $this->assertIsString($file);
        file_put_contents($file, "<?php\ncheck('/a+/', \$s);\n\ncheck('/b/', \$s);\n");

        try {
            $occurrences = (new HelperCallExtractor())->extract([$file]);
        } finally {
            unlink($file);
        }

        $this->assertTrue(array_is_list($occurrences));
        $this->assertSame([['/a+/', 2], ['/b/', 4]], array_map(static fn (PatternOccurrence $occurrence): array => [$occurrence->pattern, $occurrence->line], $occurrences));
    }
}
