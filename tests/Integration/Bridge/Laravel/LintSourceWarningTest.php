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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\WithConfig;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * PHP warns about a file as it tokenizes it when a double-quoted literal
 * holds an octal escape above \377, on stdout by default, where artisan
 * prints the JSON report: regex:lint reads such a file without a word.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
#[WithConfig('php-regex.runtime_pcre_validation', false)]
final class LintSourceWarningTest extends LaravelTestCase
{
    use TemporaryProject;

    #[Test]
    public function test_a_file_php_warns_about_leaves_stdout_to_the_json_report(): void
    {
        // A file-level variable, a constant of the class and a marker make
        // the lint tokenize the file to read them; the constant's pattern
        // nests its quantifiers, which the report says.
        $code = "<?php\nnamespace App;\n\$byte = \"\\600\";\n\$pattern = '/^a+$/';\npreg_match(\$pattern, \$s); // @regex-ignore regex.lint.redos\npreg_match(\\App\\Rules::CODE, \$s);\nfinal class Rules { public const CODE = '/(a+)+$/'; }\n";
        $project = $this->makeProject(['app/Rules.php' => $code]);
        $display = (string) \ini_get('display_errors');
        \ini_set('display_errors', '1');
        ob_start();

        try {
            $tokens = token_get_all($code);
            $warning = (string) ob_get_contents();
            ob_clean();
            $status = Artisan::call('regex:lint', ['paths' => [$project.'/app'], '--format' => 'json', '--no-routes' => true, '--jobs' => '1']);
        } finally {
            $output = (string) ob_get_clean();
            \ini_set('display_errors', $display);
        }

        $this->assertNotSame([], $tokens);
        $this->assertStringContainsString('Octal escape sequence overflow \600 is greater than \377', $warning);
        $this->assertSame('', $output);
        $this->assertSame(0, $status);
        $document = JsonContract::decodeDocument(Artisan::output());
        $this->assertIsArray($document);
        $this->assertIsArray($document['results'] ?? null);
        $issues = [];
        foreach ($document['results'] as $result) {
            $this->assertIsArray($result);
            $this->assertIsArray($result['issues'] ?? null);
            foreach ($result['issues'] as $issue) {
                $this->assertIsArray($issue);
                $issues[] = [$result['pattern'] ?? null, $issue['issue_id'] ?? null];
            }
        }
        $this->assertContains(['/(a+)+$/', 'regex.lint.quantifier.nested'], $issues, Artisan::output());
    }

    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
