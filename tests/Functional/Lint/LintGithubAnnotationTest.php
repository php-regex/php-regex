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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A GitHub annotation points at the column of the issue in the file, the
 * one the JSON report gives, not at an offset inside the pattern.
 */
final class LintGithubAnnotationTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    private const FILE = <<<'PHP'
        <?php

        preg_match('/a{1}/', $s); preg_match('/(b/', $s);

        PHP;

    #[Test]
    public function test_github_annotation_carries_the_column_of_the_issue(): void
    {
        $this->enterProject(['src/a.php' => self::FILE]);

        [, $json] = $this->runRegex(['lint', 'src', '--format=json', '--jobs=1', '--no-optimize']);
        [, $github] = $this->runRegex(['lint', 'src', '--format=github', '--jobs=1', '--no-optimize']);

        $document = json_decode($json, true);
        $this->assertIsArray($document, $json);
        $columns = [];
        foreach ((array) ($document['results'] ?? []) as $result) {
            $this->assertIsArray($result);
            $this->assertIsString($result['pattern'] ?? null);
            $columns[$result['pattern']] = $result['column'] ?? null;
        }
        $this->assertSame(['/a{1}/' => 12, '/(b/' => 38], $columns);

        $this->assertStringContainsString('::warning file=src/a.php,line=3,col=12,title=Lint (regex.lint.quantifier.useless)::', $github);
        $this->assertStringContainsString('::error file=src/a.php,line=3,col=38,title=Syntax (regex.group.unclosed)::', $github);
    }
}
