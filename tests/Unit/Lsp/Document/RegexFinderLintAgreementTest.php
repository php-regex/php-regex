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

namespace PHPRegex\Tests\Unit\Lsp\Document;

use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The editor finds the patterns `regex lint` reads in the same source.
 */
final class RegexFinderLintAgreementTest extends TestCase
{
    /**
     * b'...' is a plain string to PHP: preg_match(b'/(a/') fails to
     * compile, "missing closing parenthesis at offset 2", and lint reads
     * the pattern.
     */
    #[Test]
    public function test_a_binary_string_literal_is_found(): void
    {
        $this->assertAgree("<?php\npreg_match(b'/(a/', \$s);\n", ['/(a/']);
    }

    /**
     * One literal read by two calls, Nette's utf8: true appending "u" for
     * one of them: lint reads "/\xFF/" and "/\xFF/u", and PHP refuses the
     * second, "UTF-8 error: illegal byte (0xfe or 0xff) at offset 0". The
     * editor must not keep the first reading only.
     */
    #[Test]
    public function test_each_reading_of_one_literal_is_found(): void
    {
        $code = "<?php\nuse Nette\\Utils\\Strings;\n\nfinal class M\n{\n    private const RE = \"/\xFF/\";\n\n    public function run(string \$s): void\n    {\n        preg_match(self::RE, \$s);\n        Strings::match(\$s, self::RE, utf8: true);\n    }\n}\n";

        $this->assertAgree($code, ["/\xFF/", "/\xFF/u"]);
    }

    /**
     * @param list<string> $patterns
     */
    private function assertAgree(string $code, array $patterns): void
    {
        $read = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->pattern,
            (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::defaults()))->extractSource($code),
        );
        sort($read);
        $this->assertSame($patterns, $read, 'What lint reads.');

        $found = array_map(static fn (RegexOccurrence $occurrence): string => $occurrence->pattern, (new RegexFinder())->find($code));
        sort($found);
        $this->assertSame($patterns, $found, 'What the editor finds.');
    }
}
