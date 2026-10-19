<?php

declare(strict_types=1);

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPUnit\Framework\TestCase;

final class TokenBasedExtractionStrategyTest extends TestCase
{

    public function test_extracts_preg_match_and_callback_array(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/preg_match_and_callback_array.php';

        $strategy = new TokenBasedExtractionStrategy();
        $occurrences = $strategy->extract([$file]);

        $patterns = array_map(fn (PatternOccurrence $o) => $o->pattern, $occurrences);
        $this->assertContains('/foo/i', $patterns);
        $this->assertContains('#bar#', $patterns);
    }

    /**
     * Half-typed code, as an editor sends it: the brackets of the call never
     * balance, so its last argument runs to the end of the file.
     */
    public function test_reads_a_call_whose_brackets_never_balance_without_a_warning(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'regex-extract-');
        $this->assertNotFalse($file);
        file_put_contents($file, "<?php preg_match(''{)}(''.");

        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $occurrences = (new TokenBasedExtractionStrategy())->extract([$file]);
        } finally {
            restore_error_handler();
            unlink($file);
        }

        $this->assertSame([], $warnings);
        $this->assertSame([], $occurrences);
    }

    public function test_extracts_custom_function_and_static_method(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/custom_function_and_static_method.php';

        $strategy = new TokenBasedExtractionStrategy(['My\Util::check', 'myfunc']);
        $occurrences = $strategy->extract([$file]);

        $this->assertSame(['/baz/', '/qux/'], array_map(fn (PatternOccurrence $o) => $o->pattern, $occurrences));
    }

    public function test_does_not_repair_mismatched_delimiters(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/mismatched_delimiter.php';

        $strategy = new TokenBasedExtractionStrategy();
        $occurrences = $strategy->extract([$file]);

        $patterns = array_map(fn (PatternOccurrence $o) => $o->pattern, $occurrences);

        // '/foo#' is broken at runtime (no ending delimiter); the extractor
        // must keep it verbatim so the linter reports the delimiter error
        // instead of silently rewriting it to '/foo/'.
        $this->assertContains('/foo#', $patterns);
        $this->assertNotContains('/foo/', $patterns);
    }

    public function test_handles_unicode_escape_in_pattern(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/unicode_escape.php';

        $strategy = new TokenBasedExtractionStrategy();
        $occurrences = $strategy->extract([$file]);

        $patterns = array_map(fn (PatternOccurrence $o) => $o->pattern, $occurrences);
        $this->assertContains('/\x{41}/', $patterns);
    }

    public function test_handles_double_quoted_pattern(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/double_quoted_pattern.php';

        $strategy = new TokenBasedExtractionStrategy();
        $occurrences = $strategy->extract([$file]);

        $patterns = array_map(fn (PatternOccurrence $o) => $o->pattern, $occurrences);
        $this->assertContains('/pattern/i', $patterns);
    }

    public function test_handles_single_quoted_pattern(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/single_quoted_pattern.php';

        $strategy = new TokenBasedExtractionStrategy();
        $occurrences = $strategy->extract([$file]);

        $patterns = array_map(fn (PatternOccurrence $o) => $o->pattern, $occurrences);
        $this->assertContains('/pattern/', $patterns);
    }

    public function test_extracts_plain_string_pattern_when_not_regex_literal(): void
    {
        $file = __DIR__.'/../../Fixtures/Extractor/plain_string_pattern.php';

        $strategy = new TokenBasedExtractionStrategy();
        $occurrences = $strategy->extract([$file]);

        $this->assertSame('plainpattern', $occurrences[0]->pattern ?? null);
    }
}
